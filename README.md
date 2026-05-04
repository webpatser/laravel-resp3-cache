# laravel-resp3-cache

A synchronous Redis client and Laravel cache driver backed by the
[ext-resp3][php-resp3] C parser. Drop in to a php-fpm Laravel app, point
your `redis.client` config at `resp3`, and your existing `Cache::*` calls
parse responses through the C extension instead of pure-PHP code.

> [!NOTE]
> Status: v0.x, pre-launch. API may change. The core `Resp3Client` does
> HELLO 3, AUTH, SELECT, and command + pipeline round trips against a
> local Valkey or Redis 6+. Laravel integration ships in v0.1.

## Quickstart

```bash
# Install the C extension first (PHP 8.4+)
pie install webpatser/php-resp3

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

Set `'cluster' => 'redis'` and provide a `clusters` block — same shape
as the standard Laravel cluster config for phpredis or predis. The
package's connector picks it up via `connectToCluster()` and routes
commands by slot across the cluster.

```php
'redis' => [
    'client' => 'resp3',
    'options' => [
        'cluster' => 'redis',
        'prefix'  => env('CACHE_PREFIX', 'app:'),
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

Local cluster boot works on Linux. Docker Desktop on macOS sometimes
fails the cluster-bus handshake when nodes announce 127.0.0.1; in that
case rely on CI for the cluster suite.

## Limitations

- No Sentinel failover.
- No Pub/Sub (Laravel cache does not use it; subscribe support comes if
  there is demand).
- No connection pooling beyond `STREAM_CLIENT_PERSISTENT`.
- Cross-slot multi-key commands (`MGET`, `MSET`, transactions) are
  rejected; use hash tags to colocate keys.

## Compatibility

| Layer | Versions |
| ----- | -------- |
| PHP | 8.4, 8.5 |
| Laravel | 11, 12, 13 |
| Redis or Valkey | 6.0+ (RESP3 capable) |

## More

- [`php-resp3`][php-resp3] for the underlying C extension and the
  parser-level benchmarks.
- [`BENCHMARKS.md`][benchmarks] for the workloads where this helps.

## License

[MIT](./LICENSE).

[php-resp3]: https://github.com/webpatser/php-resp3
[benchmarks]: https://github.com/webpatser/php-resp3/blob/main/BENCHMARKS.md
