<?php declare(strict_types=1);

namespace Resp3\Laravel\Cache;

use BadMethodCallException;
use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\Connection;
use Resp3\Laravel\Client\Resp3ClientInterface;
use Resp3\Laravel\Client\ServerCapabilities;
use Resp3\Laravel\Client\ServerException;
use Resp3\Laravel\Cluster\CRC16;
use Resp3\Laravel\Connections\Resp3ClusterConnection;
use Resp3\Laravel\Connections\Resp3Connection;

/**
 * Cache store for the `resp3` cache driver.
 *
 * Drop-in for RedisStore that uses newer server commands when the server
 * advertises them (see ServerCapabilities) and falls back to the classic
 * commands otherwise:
 *
 * - putMany(): one atomic MSETEX (Redis 8.4.4+, Valkey 9.1+), else
 *   MULTI/SETEX/EXEC on a single node, else one put() per key on a cluster.
 * - many(): one MGET per slot on a cluster instead of a CROSSSLOT error.
 * - add(): plain `SET key value EX ttl NX` instead of a Lua script.
 * - lock(): Resp3Lock, which releases with DELEX and refreshes with SET IFEQ.
 * - putIfEquals(): compare-and-set with `SET ... IFEQ`.
 *
 * When a feature command is answered with "unknown command" or a command NOPERM (an
 * ACL or a proxy hiding it), the feature is disabled on that connection and
 * the call falls back once.
 *
 * Connections that are not Resp3Connection get the plain RedisStore
 * behaviour.
 */
class Resp3Store extends RedisStore
{
    public function many(array $keys)
    {
        $connection = $this->connection();

        if (count($keys) === 0 || !$connection instanceof Resp3ClusterConnection) {
            return parent::many($keys);
        }

        // Group by slot so every MGET stays on one node.
        $bySlot = [];
        foreach ($keys as $key) {
            $bySlot[CRC16::slot($this->prefix.$key)][] = $key;
        }

        $found = [];
        foreach ($bySlot as $slotKeys) {
            $values = $connection->mget(array_map(fn ($key) => $this->prefix.$key, $slotKeys));
            foreach ($slotKeys as $index => $key) {
                $value = $values[$index] ?? null;
                $found[$key] = $value !== null ? $this->connectionAwareUnserialize($value, $connection) : null;
            }
        }

        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $found[$key];
        }

        return $results;
    }

    public function putMany(array $values, $seconds)
    {
        $connection = $this->connection();

        if (!$connection instanceof Resp3Connection) {
            return parent::putMany($values, $seconds);
        }
        if ($values === []) {
            return false;
        }

        $ttl = (int) max(1, $seconds);
        // [key, value] pairs: an array keyed by the prefixed key would turn
        // numeric keys into ints.
        $pairs = [];
        foreach ($values as $key => $value) {
            $pairs[] = [$this->prefix.$key, $this->connectionAwareSerialize($value, $connection)];
        }

        $isCluster = $connection instanceof Resp3ClusterConnection;

        if (
            self::supports($connection, ServerCapabilities::MSETEX)
            && (!$isCluster || self::sameSlot(array_column($pairs, 0)))
        ) {
            $args = [(string) count($pairs)];
            foreach ($pairs as [$key, $value]) {
                $args[] = $key;
                $args[] = $value;
            }
            array_push($args, 'EX', (string) $ttl);

            try {
                return $connection->command('MSETEX', $args) === 1;
            } catch (ServerException $e) {
                self::disableIfUnsupported($connection, ServerCapabilities::MSETEX, $e);
            }
        }

        if ($isCluster) {
            $result = true;
            foreach ($pairs as [$key, $value]) {
                $result = $connection->setex($key, $ttl, $value) && $result;
            }
            return $result;
        }

        $connection->multi();
        foreach ($pairs as [$key, $value]) {
            $connection->setex($key, $ttl, $value);
        }
        $replies = $connection->exec();

        return is_array($replies)
            && count($replies) === count($pairs)
            && array_all($replies, fn ($reply) => $reply === 'OK');
    }

    /**
     * Store an item if the key does not exist. A single `SET ... EX ... NX`
     * is atomic on every supported server version.
     */
    public function add($key, $value, $seconds)
    {
        $connection = $this->connection();

        if (!$connection instanceof Resp3Connection) {
            return parent::add($key, $value, $seconds);
        }

        return $connection->command('SET', [
            $this->prefix.$key,
            $this->pack($value, $connection),
            'EX',
            (string) (int) max(1, $seconds),
            'NX',
        ]) === 'OK';
    }

    /**
     * Replace the value of $key with $value only when its current value
     * equals $expected (compare-and-set). Both values are serialized the
     * same way put() serializes them. A missing key never matches.
     *
     * $seconds null stores the new value without expiry.
     *
     * @throws BadMethodCallException when the server has no `SET ... IFEQ`
     *         (Redis before 8.4, Valkey before 8.1), so a caller never
     *         silently skips the compare.
     */
    public function putIfEquals(string $key, mixed $expected, mixed $value, ?int $seconds = null): bool
    {
        $connection = $this->connection();

        if (!$connection instanceof Resp3Connection || !self::supports($connection, ServerCapabilities::SET_IF_EQ)) {
            throw self::unsupported('putIfEquals', 'SET IFEQ');
        }

        $args = [
            $this->prefix.$key,
            $this->connectionAwareSerialize($value, $connection),
            'IFEQ',
            $this->connectionAwareSerialize($expected, $connection),
        ];
        if ($seconds !== null) {
            array_push($args, 'EX', (string) max(1, $seconds));
        }

        try {
            return $connection->command('SET', $args) === 'OK';
        } catch (ServerException $e) {
            self::disableIfUnsupported($connection, ServerCapabilities::SET_IF_EQ, $e, syntaxErrorMeansUnsupported: true);
            throw self::unsupported('putIfEquals', 'SET IFEQ', $e);
        }
    }

    public function lock($name, $seconds = 0, $owner = null)
    {
        $lockConnection = $this->lockConnection();

        if (!$lockConnection instanceof Resp3Connection) {
            return parent::lock($name, $seconds, $owner);
        }

        return new Resp3Lock($lockConnection, $this->prefix.$name, $seconds, $owner);
    }

    public function restoreLock($name, $owner)
    {
        return $this->lock($name, 0, $owner);
    }

    // ------------------------------------------------------------------ capability helpers

    /**
     * The capabilities of the server behind a connection, or null when the
     * connection cannot report them (then every optional feature is off).
     *
     * @internal Shared with Resp3Lock.
     */
    public static function capabilitiesOf(Connection $connection): ?ServerCapabilities
    {
        if ($connection instanceof Resp3Connection) {
            return $connection->capabilities();
        }

        $client = $connection->client();

        return $client instanceof Resp3ClientInterface ? $client->capabilities() : null;
    }

    /** @internal Shared with Resp3Lock. */
    public static function supports(Connection $connection, string $feature): bool
    {
        return self::capabilitiesOf($connection)?->has($feature) ?? false;
    }

    /**
     * Disable $feature when $e says the server does not know or does not
     * allow the command; rethrow anything else.
     *
     * `SET ... IFEQ` on a server without the option fails with "ERR syntax
     * error" rather than "unknown command", hence the flag.
     *
     * @internal Shared with Resp3Lock.
     * @throws ServerException when the error is not about support
     */
    public static function disableIfUnsupported(
        Connection $connection,
        string $feature,
        ServerException $e,
        bool $syntaxErrorMeansUnsupported = false,
    ): void {
        $message = strtolower($e->getMessage());
        // NOPERM counts only for a command ACL ("no permissions to run the
        // 'delex' command"), never for a key-pattern denial.
        $unsupported = ($e->prefix === 'NOPERM' && str_contains($message, 'command'))
            || ($e->prefix === 'ERR' && (
                str_contains($message, 'unknown command')
                || ($syntaxErrorMeansUnsupported && str_contains($message, 'syntax error'))
            ));

        if (!$unsupported) {
            throw $e;
        }

        self::capabilitiesOf($connection)?->disable($feature);
    }

    /** @param list<string> $keys */
    private static function sameSlot(array $keys): bool
    {
        $slot = CRC16::slot($keys[0]);
        foreach ($keys as $key) {
            if (CRC16::slot($key) !== $slot) {
                return false;
            }
        }
        return true;
    }

    private static function unsupported(string $method, string $command, ?\Throwable $previous = null): BadMethodCallException
    {
        return new BadMethodCallException(
            "{$method}() needs {$command} (Redis 8.4+ or Valkey 8.1+); this connection does not support it.",
            0,
            $previous,
        );
    }
}
