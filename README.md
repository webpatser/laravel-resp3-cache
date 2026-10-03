# laravel-resp3-cache

A synchronous Redis client and Laravel cache driver backed by the
[ext-resp3][php-resp3] C parser. Drop in to a php-fpm Laravel app, point
your `redis.client` config at `resp3`, and your existing `Cache::*` calls
parse responses through the C extension instead of pure-PHP code.

> [!NOTE]
> Status: v0.x, pre-launch. API may change. Breaking changes land in 0.x
> minors and are listed in the [changelog](./CHANGELOG.md). Version 0.7
> needs ext-resp3 ^0.2; see the upgrade notes there.

## Quickstart

```bash
# Install the C extension first (PHP 8.4+, ext-resp3 ^0.2)
pie install webpatser/php-resp3:^0.2

# Then this Laravel package
composer require webpatser/laravel-resp3-cache
```

In `config/database.php`:

```php
'redis' => [
    'client' => env('REDIS_CLIENT', 'resp3'),
    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD'),
        'database' => env('REDIS_DB', 0),
    ],
],
```

That's the whole opt-in. The standard `redis` cache driver picks up the
new client through Laravel's existing `RedisManager` extension hook.

## Why this exists

See the bench in [php-resp3 BENCHMARKS.md][benchmarks]. Cache-heavy
read patterns (one round trip returning many small values) spend most
of their PHP time inside the parser. Replacing the parse step with a C
implementation moves the bottleneck elsewhere; expect a multiple-x
speedup on `Cache::many()`, `Cache::tags()->many()`, and large
`HGETALL` reads. Single-key `Cache::get` workloads are dominated by
round trip latency, so the parser swap matters less there.

## Cache driver

The `resp3` cache driver is a `RedisStore` subclass (`Resp3Store`) that
uses the server features listed below when they exist. Register it by
setting `'driver' => 'resp3'` on a store in `config/cache.php`; the store
accepts the same options as the `redis` driver.

```php
'stores' => [
    'resp3' => [
        'driver' => 'resp3',
        'connection' => 'cache',          // key in config/database.php redis
        'lock_connection' => 'default',
        'prefix' => env('CACHE_PREFIX', 'app:'),
    ],
],
```

Key prefixing is the store `prefix` (or the global `cache.prefix`). The
`options.prefix` setting on the Redis connection is not applied by this
driver and is not supported.

What the driver does differently from the stock `redis` driver:

- `add()` is a single `SET key value EX seconds NX`.
- `putMany()` returns the real result. It uses one atomic `MSETEX` when
  the server has it (on a cluster only when all keys share a slot),
  `SETEX` per key on a cluster otherwise, and `MULTI`/`SETEX`/`EXEC` on a
  single node without `MSETEX`.
- `many()` and `putMany()` work on a cluster without hash tags: keys are
  grouped per slot.
- `putIfEquals(string $key, mixed $expected, mixed $value, ?int $seconds = null): bool`
  is a compare-and-set built on `SET ... IFEQ`. It throws
  `BadMethodCallException` when the server lacks `SET IFEQ`, so a caller
  never skips the compare silently. A missing key never matches.
- Locks release with `DELEX key IFEQ owner` when available, else with the
  Lua script. `Resp3Lock::refresh(?int $seconds = null): bool` extends a
  held lock (`SET ... IFEQ`, else Lua) and returns false when the lock
  expired or belongs to another owner.

Redis error replies reaching a Laravel connection are thrown as
`Resp3\Laravel\Client\ServerException`. It extends `RuntimeException` and
has a readonly `prefix` (`ERR`, `WRONGTYPE`, `NOAUTH`, ...) and
`getRedisException()` for the original `Resp3\RedisException`.

## Server features

The client reads the `HELLO 3` reply once per connection and enables
optional commands by server and version. `Resp3Connection::capabilities()`
returns a `ServerCapabilities` object with `delex()`, `setIfEq()`,
`msetex()`, `increx()`, `has(string $feature)`, `disable(string $feature)`,
`server()`, `version()`, `id()`, `mode()`, `isRedis()`, `isValkey()` and
`toArray()`.

| Feature | Command | Redis | Valkey | Used for |
| ------- | ------- | ----- | ------ | -------- |
| `delex` | `DELEX key IFEQ v` | 8.4.0 | not detected | Lock release |
| `setIfEq` | `SET key v IFEQ old` | 8.4.0 | 8.1.0 | `putIfEquals()`, `Resp3Lock::refresh()` |
| `msetex` | `MSETEX numkeys k v ... EX s` | 8.4.4 | 9.1.0 | `putMany()` |
| `increx` | `INCREX` | 8.8.0 | not detected | Reported only; no store method uses it yet |

`MSETEX` needs Redis 8.4.4 because earlier 8.4 releases had an ACL bypass
in that command. Valkey 9.2 added `DELEX`, but the client does not detect
it there yet; force it on with the `features` option below if you want it.
Valkey answers `HELLO` as `redis 7.2.x` when its Redis compatibility mode
is on; in that case the client runs `INFO server` once and reads
`valkey_version`.

Override detection per connection with `features` in the Redis options.
Each value is `true`, `false` or `'auto'`:

```php
'redis' => [
    'client' => 'resp3',
    'options' => [
        'features' => ['msetex' => false, 'delex' => 'auto'],
    ],
    'default' => [/* ... */],
],
```

When the server answers a feature command with `ERR unknown command`, or
with `NOPERM` and a message that contains "command" (an ACL or a proxy
hides it), the feature is disabled for that connection and the call falls
back once. After a sentinel failover the connection runs `HELLO` again, so
a disabled feature is detected again and the fallback fires once more.

> [!NOTE]
> Cluster connections do not support `features` overrides. They detect
> capabilities from the first live master only.

## Client-side caching

Opt-in, off by default. With it on, `get()` and `many()` on the `resp3`
cache driver are served from a process-local store, and the server tells
the client when a key changed (`CLIENT TRACKING`, delivered as RESP3
push messages). With it off the store sends no extra command.

```php
'stores' => [
    'resp3' => [
        'driver' => 'resp3',
        'connection' => 'cache',
        'prefix' => env('CACHE_PREFIX', 'app:'),
        'client_tracking' => [
            'enabled' => env('RESP3_CLIENT_TRACKING', false),
            'mode' => 'optin',          // or 'bcast'
            'prefixes' => [],           // bcast only; default: the store prefix
            'noloop' => false,
            'local_store' => 'auto',    // 'array' | 'apcu'
            'local_ttl' => 60,          // seconds, upper bound for a local entry
            'max_entries' => 10000,
            'max_value_bytes' => 65536, // larger values are never cached locally
            'max_bytes' => 33554432,    // array store: key plus value bytes, 32 MiB
        ],
    ],
],
```

A runnable demo is in [`examples/client_tracking.php`](./examples/client_tracking.php).

How it works:

- In `optin` mode the client sends `CLIENT TRACKING ON OPTIN` once and
  prefixes each tracked read with `CLIENT CACHING YES`, so only cache
  reads are tracked. A tracked `get()` is one pipeline: `CLIENT CACHING
  YES`, `GET`, `PTTL`. `many()` sends `MGET` plus one `PTTL` per key.
  `bcast` mode tracks every key under the given prefixes instead.
- A local entry lives for the smaller of `local_ttl` and the server TTL
  of the key.
- Pushes are drained before every local hit, so an invalidation that has
  reached the socket is applied first. An invalidation that arrives
  together with a reply keeps that value out of the local store.
  `FLUSHDB` and `FLUSHALL` clear the whole local namespace.
- `tracking-redir-broken` disables tracking for that connection; reads go
  to the server as before.
- Writes (`put`, `putMany`, `forever`, `forget`, `increment`, `decrement`,
  `putIfEquals`) go to the server and then delete the local entry. Nothing
  is ever written to the local store by a write. `add()` and locks bypass
  the local store. `flush()` clears it, then runs `FLUSHDB`.
- Values are stored locally as the serialized string and unserialized on
  every hit, so callers never share object instances.
- A tracked read inside `MULTI` on the same connection throws
  `LogicException`.

Local stores:

- `array`: a per-process LRU (insertion order) capped by `max_entries`.
  Right for Octane, queue workers and other long-lived processes.
- `apcu`: shared by the workers of one PHP-FPM pool. Needs the apcu
  extension (and `apc.enable_cli=1` on the CLI) and `local_ttl` of at
  least 1; with `enabled` true the config throws otherwise. Entry names
  are scoped with an HMAC keyed by `app.key`, so pools with different app
  keys cannot read each other's entries. The secret is never a config key.
  `APP_KEY` must be set when you use the `apcu` store: with an empty app
  key the scope is predictable again.
- `auto`: APCu when it is loaded, the connection is persistent and
  `local_ttl` is at least 1, else `array`.

`mode` and `local_store` are validated only when `enabled` is true.

Tracking belongs to one server connection. The local namespace is the
process id plus the connection id from `HELLO`, so entries from another
connection are never read. A plain PHP-FPM request opens a new connection
and starts with an empty local store, so it gains nothing. To benefit
under PHP-FPM set `'persistent' => true` in the Redis options. A
persistent socket is keyed per database, username and
`options.persistent_id`, so two connections (for example `default` and
`cache`) do not share one socket. A clean persistent socket stays open at
request end. On reuse the handshake pipelines `HELLO` and a `PING` nonce
to check that the stream is aligned, and reconnects once if it is not.
When the `HELLO` id is unchanged the queued invalidations are applied and
the entries stay; a different id drops the namespace.

The array store is bounded three ways: `max_entries`, `max_value_bytes`
per value, and `max_bytes` for the whole store, counted as key plus value
bytes. While the store is over `max_bytes` the least recently used
entries are evicted. The default budget is 32 MiB per process. With the
`apcu` store the limit is APCu's `apc.shm_size`.

Several stores on one connection: stores with the same tracking arguments
(mode, prefixes, noloop) share one namespace. The first one registers the
client hooks and forwards pushes; each store keeps its own local store. A
store whose tracking arguments differ gets no client-side cache on that
connection and reads through to the server.

A store that attaches to a connection after another store already
handshaked, or to a persistent socket that already ran a command in this
request, starts with an empty local namespace because it may have missed
invalidations. Lazily resolved secondary stores therefore do not reuse
APCu entries across requests; the first store resolved on a fresh request
does.

Push handling: a single push drain is capped at 8 MiB. Over the cap the
connection closes with a `ConnectionException` and the store falls back to
a plain `GET` for that read; any protocol error during a drain falls back
the same way. The tracking listener and a listener set with
`setPushListener()` are chained, tracking first, so a listener on the
cache connection does not swallow invalidations. Use a separate
connection for pub/sub anyway.

> [!NOTE]
> `'noloop' => true` makes the server skip invalidations caused by this
> connection's own writes. Writes made outside the store on the same
> connection (tag flushes, which delete with `DEL`, and `Redis` facade
> writes) then never invalidate the local entries. Do not combine
> `noloop` with cache tags or raw writes on the cache connection.

Scope: single node and sentinel connections. Cluster connections ignore
the `client_tracking` block; cluster support is a follow-up.

## Testing

```bash
vendor/bin/phpunit --testsuite=Unit
php -d extension=/path/to/resp3.so vendor/bin/phpunit --testsuite=Feature
```

Feature tests target `127.0.0.1:6379`. Point them at another server with
`RESP3_TEST_HOST` and `RESP3_TEST_PORT`, for example a Redis container on
6380 next to Valkey on 6379. CI runs the suite on Valkey 8.1, Valkey 9.1
and Redis 8.10, each on PHP 8.4 and 8.5, plus the cluster suite. See
[CONTRIBUTING.md](./CONTRIBUTING.md) for the full setup.

## What's in v0.1

- Sync TCP client with optional TLS, AUTH (single password or Redis 6
  ACL username + password), database SELECT, configurable timeout, and
  persistent connections.
- Pipelining via `pipeline([...])`.
- Drop-in `Resp3Connection` that satisfies the methods Laravel's
  `RedisStore` actually calls (get, mget, setex, set, del, eval,
  multi/exec, scan, flushdb, incr/decr/expire).
- Standard `Cache::*` API works through the existing `redis` driver.

## Cluster mode

Set `'cluster' => 'redis'` and provide a `clusters` block, in the same shape
as the standard Laravel cluster config for phpredis or predis. The
package's connector picks it up via `connectToCluster()` and routes
commands by slot across the cluster.

```php
'redis' => [
    'client' => 'resp3',
    'options' => [
        'cluster' => 'redis',
    ],
    'clusters' => [
        'default' => [
            ['host' => env('REDIS_HOST', '127.0.0.1'), 'port' => 6379],
            ['host' => env('REDIS_HOST_2', '127.0.0.1'), 'port' => 6380],
            ['host' => env('REDIS_HOST_3', '127.0.0.1'), 'port' => 6381],
        ],
        'options' => [
            'timeout' => 2,
            'cluster_read_replicas' => env('REDIS_CLUSTER_READ_REPLICAS', false),
        ],
    ],
],
```

Topology comes from `CLUSTER SHARDS` (Redis 7+) with `CLUSTER SLOTS`
fallback. The slot map is loaded lazily on the first command and
refreshed on `MOVED`. `ASK` redirects send `ASKING` then the original
command without touching the cached map. Failed nodes drop out of the
pool with up to 5 retries (exponential backoff capped at 200ms).

`'cluster_read_replicas' => true` routes read commands (`GET`, `MGET`,
`HGET`, `HGETALL`, etc) to a random replica for the slot. Writes
always go to the master. Fresh replica connections get a one-shot
`READONLY` so they accept reads.

### Hash tags for multi-key commands

Redis cluster requires all keys in one command (`MGET`, `MSET`, `DEL k1
k2`) to live in the same slot. Use `{tag}` syntax to colocate keys:

```php
Cache::put('{user:42}.profile', $profile);
Cache::put('{user:42}.cart',    $cart);
Cache::many(['{user:42}.profile', '{user:42}.cart']);  // one round trip
```

Without the hash tag the keys land on different nodes and `MGET` raises
`CROSSSLOT keys in request don't hash to the same slot`. Same rule
applies inside `MULTI`/`EXEC`.

`Cache::flush()` and similar broadcast operations iterate every master.

### Local cluster development

```bash
make cluster-up      # boots 6-node Valkey cluster on 127.0.0.1:7100-7105
make cluster-test    # runs the cluster suite + tears it down
make cluster-down    # tears it down manually
```

`tests/cluster/setup.sh` needs only Docker; `valkey-cli` runs inside the
node containers. It is idempotent and exits non-zero, printing the
cluster-init log and each node's `cluster info`, when the cluster does
not reach `cluster_state:ok` with all 16384 slots.

## Pub/Sub

Standard Laravel `Redis::subscribe()` and `Redis::psubscribe()` work on
single-node connections:

```php
use Illuminate\Support\Facades\Redis;

Redis::subscribe(['user-events'], function (string $message, string $channel) {
    echo "[$channel] $message\n";
});

Redis::psubscribe(['user.*'], function (string $message, string $channel, string $pattern) {
    // ...
});
```

The subscribe loop opens a dedicated socket so the original connection
stays free for normal commands. Returning `false` from the callback
exits the loop cleanly (UNSUBSCRIBE + close).

If the `pcntl` extension is loaded, SIGTERM and SIGINT break the loop
and clean up. That makes `php artisan` consumers behave well under
process supervisors (Horizon, Supervisor, systemd) without orphan
connections.

For cluster mode, regular `subscribe`/`psubscribe` still raise
`BadMethodCallException`: the global pub/sub bus does not scale across
a cluster, which is why Redis 7 introduced sharded pub/sub. Use
`ssubscribe()` (see below) for per-shard delivery, or open a single-node
`Redis::connection()` pointed at one master for global broadcast
semantics.

### Sharded pub/sub on cluster

Redis 7 (and Valkey) ship `SSUBSCRIBE` / `SUNSUBSCRIBE` / `SPUBLISH`:
channels CRC16-hash to a slot just like keys, and only subscribers on
that slot's master receive the message. No cluster-bus fanout, linear
scalability.

```php
use Illuminate\Support\Facades\Redis;

// Subscriber: every channel must hash to the same slot. Use {hash tag}
// syntax to colocate them, exactly like multi-key MGET / MSET.
Redis::connection()->ssubscribe(
    ['orders.{user42}.created', 'orders.{user42}.updated'],
    function (string $message, string $channel) {
        // ...
    },
);

// Publisher: route via SPUBLISH (not PUBLISH) so the message lands on
// the same shard as the subscribers.
Redis::connection()->command('SPUBLISH', ['orders.{user42}.created', $payload]);
```

Channels in a single `ssubscribe()` call that hash to different slots
raise `RuntimeException` with a CROSSSLOT-style hint; add a `{tag}` to
group them. Sharded pub/sub has no pattern subscribe equivalent
(`SPSUBSCRIBE` is not part of the Redis spec); for pattern matching on
a cluster, design your channel naming so the prefix lives inside the
hash tag.

`SUNSUBSCRIBE` runs on every exit path (callback returning `false`,
SIGTERM/SIGINT with `pcntl` loaded, socket drop). Subscriber sockets are
dedicated and never persistent, mirroring the single-node pub/sub
behaviour shipped in v0.3.

## Sentinel mode

Set `'replication' => 'sentinel'` and provide the master service name
plus a list of seed sentinels in the standard Predis-compatible config
shape. The connector queries the sentinels for the current master,
opens a normal data-plane connection, and re-discovers transparently
when the connection drops or the master is demoted.

```php
'redis' => [
    'client' => 'resp3',
    'options' => [
        'replication' => 'sentinel',
        'service'     => env('REDIS_SENTINEL_SERVICE', 'mymaster'),
        'sentinel_password' => env('REDIS_SENTINEL_PASSWORD'),
        'password' => env('REDIS_PASSWORD'),
        'database' => 0,
        'timeout'  => 0.5,
    ],
    'default' => [
        ['host' => env('REDIS_SENTINEL_1_HOST'), 'port' => 26379],
        ['host' => env('REDIS_SENTINEL_2_HOST'), 'port' => 26379],
        ['host' => env('REDIS_SENTINEL_3_HOST'), 'port' => 26379],
    ],
],
```

Failover is reactive: a dropped connection or a `READONLY` reply from
a demoted master triggers a fresh `SENTINEL get-master-addr-by-name`
query. The seed list is rotated on every failed attempt so a dead
sentinel does not block subsequent tries. No background pub/sub on
`+switch-master`; the next command is what notices.

### Replica reads

Set `'sentinel_read_replicas' => true` to route read commands to
replicas (matches the `cluster_read_replicas` flag in cluster mode).
Writes always go to the master. The replica list is discovered via
`SENTINEL replicas <service>`; replicas flagged `s_down` or `o_down`
are filtered out. The pool refreshes on exhaustion, and any read that
cannot find a healthy replica falls back silently to the master.

```php
'options' => [
    'replication' => 'sentinel',
    'service'     => env('REDIS_SENTINEL_SERVICE', 'mymaster'),
    'sentinel_read_replicas' => env('REDIS_SENTINEL_READ_REPLICAS', false),
    // ...
],
```

A few caveats:

- Random selection only in v0.5; round-robin and weighted strategies
  land later.
- Read-after-write on the same connection can return the previous
  value because of normal replication lag. If you need strict
  read-after-write, force the read through the master by reusing the
  Cache key right after writing on a non-replica path or by toggling
  the flag off on that connection.
- Pipelines and EVAL/EVALSHA always route to the master because their
  read/write profile is opaque to the classifier.

### Local Sentinel development

```bash
make sentinel-up      # boots 1 master + 2 replicas + 3 sentinels
make sentinel-test    # runs the sentinel suite + tears down
make sentinel-down    # tears down manually
```

## Limitations

- Sentinel replica reads use random selection only; round-robin and
  weighted strategies land later.
- Sharded pub/sub has no pattern equivalent (`SPSUBSCRIBE` is not in
  the Redis spec); design channel names so the prefix lives inside the
  `{hash tag}`.
- No connection pooling beyond `STREAM_CLIENT_PERSISTENT`.
- Cross-slot multi-key commands (`MGET`, `MSET`, transactions) and
  multi-channel `ssubscribe()` calls are rejected; use hash tags to
  colocate keys or channels. The cache driver's `many()` and
  `putMany()` split per slot for you.
- Client-side caching works on single node and sentinel connections only;
  cluster connections ignore it.
- `features` overrides are not supported on cluster connections.
- A feature disabled at runtime is detected again after a sentinel
  failover.
- Client-side caching with `noloop` misses this connection's own writes
  made outside the store; avoid it with cache tags.

## Compatibility

| Layer | Versions |
| ----- | -------- |
| PHP | 8.4, 8.5 |
| Laravel | 11, 12, 13 |
| ext-resp3 | ^0.2 |
| Redis or Valkey | 6.0+ (RESP3 capable); optional commands need the versions in [Server features](#server-features) |

## More

- [`php-resp3`][php-resp3] for the underlying C extension and the
  parser-level benchmarks.
- [`BENCHMARKS.md`][benchmarks] for the workloads where this helps.

## License

[MIT](./LICENSE).

[php-resp3]: https://github.com/webpatser/php-resp3
[benchmarks]: https://github.com/webpatser/php-resp3/blob/main/BENCHMARKS.md
