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
use Resp3\Laravel\Tracking\ClientSideCache;
use Resp3\Laravel\Tracking\TrackingConfig;

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
 *
 * Optional client-side caching (`client_tracking` in the store config, see
 * TrackingConfig): get() and many() read through a process-local copy that
 * the server invalidates with CLIENT TRACKING pushes; put, putMany, forever,
 * forget, increment, decrement and putIfEquals write to the server and then
 * drop the local copy; flush() clears the local copy, then runs FLUSHDB. add()
 * and locks bypass it. Off by default; while off no extra command is sent.
 */
class Resp3Store extends RedisStore
{
    /** Client-side caching settings; null leaves every code path untouched. */
    protected ?TrackingConfig $tracking = null;

    /**
     * Turn on client-side caching (CLIENT TRACKING) for this store, or off
     * with null / a disabled config. Applies to single node and sentinel
     * connections; cluster connections ignore it.
     */
    public function setClientTracking(?TrackingConfig $config): static
    {
        $this->tracking = $config?->enabled ? $config : null;

        return $this;
    }

    public function clientTracking(): ?TrackingConfig
    {
        return $this->tracking;
    }

    /**
     * The client-side cache of the store's connection, or null when tracking
     * is off or the connection cannot track.
     */
    public function clientSideCache(): ?ClientSideCache
    {
        return $this->trackingFor($this->connection());
    }

    public function get($key)
    {
        if ($this->tracking === null) {
            return parent::get($key);
        }

        $connection = $this->connection();
        $cache = $this->trackingFor($connection);
        if ($cache === null) {
            return parent::get($key);
        }

        $value = $cache->get($connection, $this->prefix.$key);

        return $value !== null ? $this->connectionAwareUnserialize($value, $connection) : null;
    }

    public function many(array $keys)
    {
        if ($this->tracking !== null && count($keys) > 0) {
            $connection = $this->connection();
            $cache = $this->trackingFor($connection);
            if ($cache !== null) {
                $keys = array_values($keys);
                $values = $cache->many($connection, array_map(fn ($key) => $this->prefix.$key, $keys));

                $results = [];
                foreach ($keys as $index => $key) {
                    $value = $values[$index] ?? null;
                    $results[$key] = $value !== null ? $this->connectionAwareUnserialize($value, $connection) : null;
                }

                return $results;
            }
        }

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
        if ($this->tracking === null) {
            return $this->putManyOnServer($values, $seconds);
        }

        try {
            return $this->putManyOnServer($values, $seconds);
        } finally {
            $this->forgetLocally(...array_keys($values));
        }
    }

    public function put($key, $value, $seconds)
    {
        if ($this->tracking === null) {
            return parent::put($key, $value, $seconds);
        }

        try {
            return parent::put($key, $value, $seconds);
        } finally {
            $this->forgetLocally($key);
        }
    }

    public function forever($key, $value)
    {
        if ($this->tracking === null) {
            return parent::forever($key, $value);
        }

        try {
            return parent::forever($key, $value);
        } finally {
            $this->forgetLocally($key);
        }
    }

    public function forget($key)
    {
        if ($this->tracking === null) {
            return parent::forget($key);
        }

        try {
            return parent::forget($key);
        } finally {
            $this->forgetLocally($key);
        }
    }

    public function increment($key, $value = 1)
    {
        if ($this->tracking === null) {
            return parent::increment($key, $value);
        }

        try {
            return parent::increment($key, $value);
        } finally {
            $this->forgetLocally($key);
        }
    }

    public function decrement($key, $value = 1)
    {
        if ($this->tracking === null) {
            return parent::decrement($key, $value);
        }

        try {
            return parent::decrement($key, $value);
        } finally {
            $this->forgetLocally($key);
        }
    }

    /** With client-side caching the local entries go first, then FLUSHDB. */
    public function flush()
    {
        if ($this->tracking !== null) {
            $this->trackingFor($this->connection())?->clear();
        }

        return parent::flush();
    }

    private function putManyOnServer(array $values, $seconds): bool
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
        } finally {
            if ($this->tracking !== null) {
                $this->forgetLocally($key);
            }
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

    // ------------------------------------------------------------------ client-side caching helpers

    private function trackingFor(Connection $connection): ?ClientSideCache
    {
        if ($this->tracking === null || !$connection instanceof Resp3Connection) {
            return null;
        }

        $cache = ClientSideCache::for($connection, $this->tracking, $this->prefix);

        return $cache?->isEnabled() ? $cache : null;
    }

    /** Drop the local copies of unprefixed cache keys after a server write. */
    private function forgetLocally(string|int ...$keys): void
    {
        $cache = $this->trackingFor($this->connection());
        if ($cache === null) {
            return;
        }

        foreach ($keys as $key) {
            $cache->forget($this->prefix.$key);
        }
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
