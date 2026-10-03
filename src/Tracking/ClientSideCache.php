<?php declare(strict_types=1);

namespace Resp3\Laravel\Tracking;

use InvalidArgumentException;
use Resp3\Laravel\Client\ConnectionException;
use Resp3\Laravel\Client\Resp3ClientInterface;
use Resp3\Laravel\Connections\Resp3ClusterConnection;
use Resp3\Laravel\Connections\Resp3Connection;
use Resp3\PushMessage;
use Resp3\RedisException;
use WeakMap;

/**
 * Client-side caching (CLIENT TRACKING) for one Resp3Connection.
 *
 * The server tracks the keys this connection reads and sends an
 * `invalidate` push when one of them changes, so a local copy stays valid
 * until that push arrives. Rules:
 *
 * - Namespace: local entries belong to `pid:HELLO id`, the one connection
 *   that receives their invalidations. A new connection (reconnect,
 *   failover, fork) gets a new namespace; the old one is dropped. A reused
 *   persistent socket with tracking still on keeps its namespace after the
 *   queued invalidations are applied.
 * - Drain before trust: every local lookup first drains the pushes already
 *   on the socket and applies them.
 * - Ordering: a read-through reply is stored only when no invalidation for
 *   one of its keys (and no flush) arrived with or right after it.
 * - Writes never populate the local store; the caller deletes the entry
 *   after writing to the server.
 * - A drain that fails (ConnectionException: the client closed the socket
 *   after an oversized drain or a parse error) may have lost invalidations:
 *   every local store on the connection is cleared and the read falls back
 *   to a plain GET.
 *
 * One instance per connection object and config fingerprint (config plus
 * store prefix), held in a WeakMap so it dies with the connection. The first
 * instance on a connection is the leader: it owns the client hooks, runs the
 * handshake and forwards every push and connect event to its siblings, which
 * take its namespace and active state. A sibling keeps its own local store
 * (own APCu scope). A store whose CLIENT TRACKING arguments differ from the
 * leader's gets no instance and runs without client-side caching. Cluster
 * connections are not supported.
 */
final class ClientSideCache
{
    /** @var WeakMap<Resp3Connection, array<string, self|null>>|null fingerprint => cache, the leader first; null when the tracking arguments differ from the leader's */
    private static ?WeakMap $instances = null;

    /** The instance that owns the client hooks; null when this one does. */
    private readonly ?self $leader;

    /** @var list<self> Siblings on the same connection (leader only). */
    private array $followers = [];

    private bool $enabled = true;

    /** Tracking is confirmed on for the current connection. */
    private bool $active = false;

    /** @var array<string, true>|null Keys of the read in flight. */
    private ?array $watching = null;

    /** @var array<string, true> Watched keys invalidated during the read. */
    private array $tripped = [];

    private bool $flushedDuringRead = false;

    /** True while the connect hook runs for a socket opened before we attached. */
    private bool $attachedLate = false;

    public function __construct(
        Resp3ClientInterface&TrackableClient $client,
        private readonly TrackingConfig $config,
        private readonly LocalStore $local,
        private readonly string $storePrefix = '',
        ?self $leader = null,
    ) {
        $this->leader = $leader;

        if ($leader !== null) {
            if ($leader->config->trackingArguments($leader->storePrefix) !== $config->trackingArguments($storePrefix)) {
                throw new InvalidArgumentException('A sibling ClientSideCache needs the same CLIENT TRACKING arguments as its leader.');
            }

            // A sibling that joins after the handshake missed the pushes the
            // group applied so far, so whatever its store already holds under
            // the namespace (APCu from an earlier request) is dropped.
            $leader->followers[] = $this;
            $this->follow($leader->local->namespace(), $leader->active, $leader->enabled, clear: true);

            return;
        }

        $client->setTrackingListener(function (PushMessage $push): void {
            $this->dispatch($push);
        });

        // A socket already open when we attach may have dropped pushes while
        // no tracking listener was set (a command that ran before the cache
        // existed), so the first handshake must not keep its namespace.
        $this->attachedLate = $client->connectionId() !== null;
        try {
            $client->onConnect(function (Resp3ClientInterface $connected): void {
                $this->handshake($connected);
            });
        } finally {
            $this->attachedLate = false;
        }
    }

    /**
     * The instance for $connection and this config plus store prefix,
     * created on first use. The first one on a connection leads; later ones
     * with the same CLIENT TRACKING arguments follow it. Null when the
     * connection cannot track (cluster, or a client without the
     * TrackableClient surface) or when the tracking arguments differ from
     * the leader's.
     */
    public static function for(Resp3Connection $connection, TrackingConfig $config, string $storePrefix = ''): ?self
    {
        if ($connection instanceof Resp3ClusterConnection) {
            return null;
        }

        self::$instances ??= new WeakMap();
        $group = self::$instances[$connection] ?? [];
        $fingerprint = $config->fingerprint($storePrefix);
        if (array_key_exists($fingerprint, $group)) {
            return $group[$fingerprint];
        }

        $client = $connection->client();
        if (!$client instanceof Resp3ClientInterface || !$client instanceof TrackableClient) {
            return null;
        }

        $leader = $group === [] ? null : $group[array_key_first($group)];

        if ($leader !== null && $leader->config->trackingArguments($leader->storePrefix) !== $config->trackingArguments($storePrefix)) {
            $cache = null;
        } else {
            $cache = new self(
                $client,
                $config,
                self::localStoreFor($connection, $client, $config, $fingerprint),
                $storePrefix,
                $leader,
            );
        }

        $group[$fingerprint] = $cache;
        self::$instances[$connection] = $group;

        return $cache;
    }

    /**
     * Raw value of $key: a trusted local hit, else a tracked read from the
     * server. Falls back to a plain GET while tracking is not active.
     */
    public function get(Resp3Connection $connection, string $key): ?string
    {
        if (!$this->ready($connection)) {
            $value = $connection->get($key);
            return is_string($value) ? $value : null;
        }

        $hit = $this->local->get($key);
        if ($hit !== null) {
            return $hit;
        }

        return $this->readThrough($connection, 'get', [$key])[0];
    }

    /**
     * Raw values for $keys, aligned with $keys. Local hits are served
     * locally, misses come from one tracked MGET.
     *
     * @param  list<string>  $keys
     * @return list<string|null>
     */
    public function many(Resp3Connection $connection, array $keys): array
    {
        $keys = array_values($keys);
        if ($keys === []) {
            return [];
        }

        if (!$this->ready($connection)) {
            return array_map(
                fn ($value) => is_string($value) ? $value : null,
                array_values($connection->mget($keys)) + array_fill(0, count($keys), null),
            );
        }

        $values = [];
        $misses = [];
        foreach ($keys as $index => $key) {
            $values[$index] = $this->local->get($key);
            if ($values[$index] === null) {
                $misses[$index] = $key;
            }
        }

        if ($misses !== []) {
            $fetched = $this->readThrough($connection, 'mget', array_values(array_unique($misses)));
            $byKey = array_combine(array_values(array_unique($misses)), $fetched);
            foreach ($misses as $index => $key) {
                $values[$index] = $byKey[$key];
            }
        }

        ksort($values);

        return $values;
    }

    /** Drop local entries after a write to the server. */
    public function forget(string ...$keys): void
    {
        foreach ($keys as $key) {
            $this->local->delete($key);
        }
    }

    /** Drop every local entry of the current namespace. */
    public function clear(): void
    {
        $this->local->clear();
    }

    /**
     * Apply one push frame: `invalidate` with keys deletes them, with a
     * null payload (FLUSHALL, FLUSHDB) clears the namespace;
     * `tracking-redir-broken` clears and disables tracking.
     */
    public function apply(PushMessage $push): void
    {
        $payload = $push->payload;
        $kind = $payload[0] ?? null;

        if ($kind === 'invalidate') {
            $keys = $payload[1] ?? null;
            if ($keys === null) {
                $this->local->clear();
                $this->flushedDuringRead = $this->watching !== null;
                return;
            }
            foreach ((array) $keys as $key) {
                if (!is_string($key)) {
                    continue;
                }
                $this->local->delete($key);
                if (isset($this->watching[$key])) {
                    $this->tripped[$key] = true;
                }
            }
            return;
        }

        if ($kind === 'tracking-redir-broken') {
            $this->disable();
        }
    }

    /** Whether tracking is confirmed on for the current connection. */
    public function isActive(): bool
    {
        return $this->enabled && $this->active;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function localStore(): LocalStore
    {
        return $this->local;
    }

    public function config(): TrackingConfig
    {
        return $this->config;
    }

    /** Turn tracking off for this connection object and drop local entries. */
    public function disable(): void
    {
        $this->enabled = false;
        $this->active = false;
        $this->local->clear();
    }

    // ------------------------------------------------------------------ internals

    /**
     * Connect hook (leader only): negotiate tracking for the new connection,
     * then hand the outcome to every sibling.
     */
    private function handshake(Resp3ClientInterface $client): void
    {
        $kept = $this->negotiate($client);

        foreach ($this->followers as $follower) {
            $follower->follow($this->local->namespace(), $this->active, $this->enabled, clear: !$kept);
        }
    }

    /**
     * Bind the namespace of the new connection, then make sure tracking is
     * on with our options. CLIENT TRACKINGINFO tells a reused persistent
     * socket (tracking still on, namespace kept) from a fresh one. Pushes
     * read during these commands reach dispatch() through the listener.
     * True when tracking was already on with our options and the socket was
     * opened after we attached (entries kept).
     */
    private function negotiate(Resp3ClientInterface $client): bool
    {
        $this->active = false;
        if (!$this->enabled) {
            return false;
        }

        $id = $client instanceof TrackableClient ? $client->connectionId() : null;
        if ($id === null) {
            return false;
        }
        $this->local->useNamespace(self::namespaceFor($id));

        $info = $client->command('CLIENT', 'TRACKINGINFO');
        if ($info instanceof RedisException) {
            $this->disable();
            return false;
        }

        $flags = is_array($info) && is_array($info['flags'] ?? null) ? $info['flags'] : [];
        if ($this->trackingMatches($info, $flags)) {
            $this->active = true;
            if ($this->attachedLate) {
                // Tracking is on, but invalidations may have been dropped
                // before we attached: keep tracking, drop the entries.
                $this->local->clear();
                return false;
            }
            return true;
        }

        // Fresh connection, or tracking with other options: anything held
        // under this namespace was never invalidated by this connection.
        $this->local->clear();

        if (in_array('on', $flags, true)) {
            $off = $client->command('CLIENT', 'TRACKING', 'OFF');
            if ($off instanceof RedisException) {
                $this->disable();
                return false;
            }
        }

        $on = $client->command('CLIENT', 'TRACKING', 'ON', ...$this->config->trackingArguments($this->storePrefix));
        if ($on instanceof RedisException) {
            $this->disable();
            return false;
        }

        $this->active = true;

        return false;
    }

    /**
     * Sibling side of a handshake: take the leader's namespace and state.
     * $clear drops entries held under an unchanged namespace that the
     * connection never tracked (or whose pushes this sibling missed).
     */
    private function follow(?string $namespace, bool $active, bool $leaderEnabled, bool $clear): void
    {
        $this->active = false;
        if (!$this->enabled) {
            return;
        }
        if (!$leaderEnabled) {
            $this->disable();
            return;
        }
        if (!$active || $namespace === null) {
            return;
        }

        $this->local->useNamespace($namespace);
        if ($clear) {
            $this->local->clear();
        }
        $this->active = true;
    }

    /** Apply a push to the leader and every sibling. */
    private function dispatch(PushMessage $push): void
    {
        $this->apply($push);
        foreach ($this->followers as $follower) {
            $follower->apply($push);
        }
    }

    /**
     * Drain the pushes already on the socket and apply them to the whole
     * group. False when the drain failed: the client closed the socket and
     * invalidations may be lost, so every local store of the group is
     * cleared and tracking stays inactive until the next handshake.
     */
    private function drain(Resp3Connection $connection): bool
    {
        $group = $this->leader ?? $this;

        try {
            $pushes = $connection->pollPushes();
        } catch (ConnectionException) {
            foreach ([$group, ...$group->followers] as $cache) {
                $cache->active = false;
                $cache->local->clear();
            }

            return false;
        }

        foreach ($pushes as $push) {
            $group->dispatch($push);
        }

        return true;
    }

    /** @param  list<mixed>  $flags */
    private function trackingMatches(mixed $info, array $flags): bool
    {
        if (!is_array($info) || !in_array('on', $flags, true) || in_array('broken_redirect', $flags, true)) {
            return false;
        }
        if ((int) ($info['redirect'] ?? -1) > 0) {
            return false;
        }
        if (in_array('noloop', $flags, true) !== $this->config->noloop) {
            return false;
        }
        if ($this->config->optIn()) {
            return in_array('optin', $flags, true);
        }
        if (!in_array('bcast', $flags, true)) {
            return false;
        }

        $expected = $this->config->prefixesFor($this->storePrefix);
        $actual = is_array($info['prefixes'] ?? null) ? array_map('strval', $info['prefixes']) : [];
        sort($expected);
        sort($actual);

        return $expected === $actual;
    }

    /**
     * Whether local entries can be trusted right now: tracking is on for the
     * live connection, the namespace is that connection's, and the pushes
     * already on the socket have been applied.
     */
    private function ready(Resp3Connection $connection): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if ($connection->trackingId() === null) {
            // Connect now so the hook runs before the first lookup.
            $connection->capabilities();
        }
        if (!$this->active || !$this->drain($connection)) {
            return false;
        }

        $namespace = $this->expectedNamespace($connection);

        return $this->active && $namespace !== null && $this->local->namespace() === $namespace;
    }

    /**
     * Tracked GET or MGET, then drain and apply pushes before deciding what
     * to store (ordering rule).
     *
     * @param  list<string>  $keys
     * @return list<string|null>
     */
    private function readThrough(Resp3Connection $connection, string $method, array $keys): array
    {
        $namespace = $this->local->namespace();
        $this->watching = array_fill_keys($keys, true);
        $this->tripped = [];
        $this->flushedDuringRead = false;

        try {
            $args = $method === 'get' ? [$keys[0]] : $keys;
            [$reply, $tracked, $ttls] = $connection->trackedRead($method, $args, $this->config->optIn());

            // A failed drain still returns the values read, but stores none.
            $drained = $this->drain($connection);

            $values = [];
            $replies = $method === 'get' ? [$reply] : (is_array($reply) ? array_values($reply) : []);
            foreach ($keys as $index => $key) {
                $value = $replies[$index] ?? null;
                $values[] = is_string($value) ? $value : null;
            }

            $storable = $drained
                && $tracked
                && $this->active
                && !$this->flushedDuringRead
                && $namespace !== null
                && $this->local->namespace() === $namespace
                && $this->expectedNamespace($connection) === $namespace;

            if ($storable) {
                foreach ($keys as $index => $key) {
                    $value = $values[$index];
                    $pttl = $ttls[$index] ?? null;
                    // PTTL -1: no expiry, the store ttl applies. -2 (gone) or
                    // a failed PTTL: do not store. Otherwise the local copy
                    // expires no later than the server key (whole seconds,
                    // rounded down; under one second is not stored).
                    if ($value === null || $pttl === null || $pttl < -1 || isset($this->tripped[$key])) {
                        continue;
                    }
                    if ($this->cacheable($key, $value)) {
                        $this->local->set($key, $value, $pttl === -1 ? null : intdiv($pttl, 1000));
                    }
                }
            }

            return $values;
        } finally {
            $this->watching = null;
            $this->tripped = [];
            $this->flushedDuringRead = false;
        }
    }

    private function cacheable(string $key, string $value): bool
    {
        if (strlen($value) > $this->config->maxValueBytes) {
            return false;
        }

        // BCAST only invalidates keys under the registered prefixes.
        $prefixes = $this->config->prefixesFor($this->storePrefix);
        if ($prefixes === []) {
            return true;
        }
        foreach ($prefixes as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function expectedNamespace(Resp3Connection $connection): ?string
    {
        $id = $connection->trackingId();

        return $id === null ? null : self::namespaceFor($id);
    }

    private static function namespaceFor(int $id): string
    {
        return getmypid().':'.$id;
    }

    /**
     * The local store for one instance. APCu (explicit, or `auto` with a
     * persistent socket and local_ttl of at least one second) is scoped by
     * an HMAC over the connection identity and the config fingerprint, keyed
     * with the app secret, so entry names are neither guessable nor shared
     * between siblings.
     */
    private static function localStoreFor(Resp3Connection $connection, TrackableClient $client, TrackingConfig $config, string $fingerprint): LocalStore
    {
        $useApcu = match ($config->localStore) {
            TrackingConfig::STORE_APCU => true,
            TrackingConfig::STORE_ARRAY => false,
            default => $config->localTtl >= 1 && $client->isPersistent() && ApcuLocalStore::available(),
        };

        if (!$useApcu) {
            return new ArrayLocalStore($config->maxEntries, $config->localTtl, $config->maxBytes);
        }

        $cfg = $connection->config;
        $scope = substr(hash_hmac('sha256', implode("\0", [
            (string) $connection->getName(),
            (string) ($cfg['host'] ?? ''),
            (string) ($cfg['port'] ?? ''),
            (string) ($cfg['database'] ?? 0),
            (string) ($cfg['username'] ?? ''),
            $fingerprint,
        ]), $config->scopeSecret), 0, 32);

        return new ApcuLocalStore($scope, $config->localTtl);
    }
}
