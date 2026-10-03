# Changelog

All notable changes to this project go here. Format follows
[Keep a Changelog 1.1][kac]. Versions follow [Semantic Versioning 2.0][semver]
once the project hits 1.0; until then, breaking changes can land in any 0.x
minor and are called out in the entry.

## [Unreleased]

Nothing yet.

## [0.7.0] - 2026-10-03

Adopts ext-resp3 0.2.0 (typed error replies, push queue, hardened parser),
adds the `resp3` cache driver with server feature detection, and adds
opt-in client-side caching on `CLIENT TRACKING`.

### Upgrade

Breaking changes, in the order you are likely to hit them:

- ext-resp3 `^0.2` is required (php-resp3 0.2.0). `composer.json` now
  declares `ext-resp3: ^0.2`; run `pie install webpatser/php-resp3:^0.2`.
- `Resp3Connection::command()` throws `Resp3\Laravel\Client\ServerException`
  (extends `RuntimeException`) for error replies instead of returning a
  `Resp3\RedisException` value. `ServerException` has a readonly `prefix`
  (the first token of the error, such as `WRONGTYPE`) and
  `getRedisException()`. The client layer (`Resp3Client`, the cluster
  router, the sentinel client) still returns errors as values so redirects
  and READONLY routing keep working.
- `exec()` returns the `EXEC` array with nested `Resp3\RedisException`
  elements as is. `EXECABORT` and queue-time errors throw
  `ServerException`.
- `Resp3ClientInterface` gained `send()` (write only), `drainPushes()`
  and `setPushListener()`; `readNext()` returns queued pushes first.
  Custom implementations of the interface must add them.
- `Resp3Client` and `Resp3SentinelClient` take a trailing
  `array $features = []` constructor argument.
- `putMany()` now returns the real result. Before, it always returned
  false.
- Key prefixing is the cache store `prefix`. The connection `options.prefix`
  setting was never applied and is not supported; move it to the store.
- `disable()` of a feature does not survive a sentinel failover:
  re-detection runs `HELLO` again and the fallback fires once more.
- `drainPushes()` returns what it received when the server closes the
  connection mid-drain, closes the socket and does not throw; the next
  command reconnects.
- Cluster connections detect capabilities from the first live master;
  `features` overrides are not supported there.
- A feature is disabled on `NOPERM` only when the error message contains
  "command".

### Added

- `resp3` cache driver (`'driver' => 'resp3'`): `Resp3\Laravel\Cache\Resp3Store`
  and `Resp3\Laravel\Cache\Resp3Lock`, registered by the service provider.
  It takes the same options as the `redis` driver.
- `Resp3\Laravel\Client\ServerCapabilities`: server identity and feature
  gates read from the `HELLO 3` reply, with `fromHello(array $hello, array $overrides = [], ?Closure $infoServer = null)`,
  `delex()`, `setIfEq()`, `msetex()`, `increx()`, `has()`, `disable()`,
  `server()`, `version()`, `id()`, `mode()`, `isRedis()`, `isValkey()` and
  `toArray()`. Gates: `DELEX` Redis 8.4.0; `SET ... IFEQ` Redis 8.4.0 and
  Valkey 8.1.0; `MSETEX` Redis 8.4.4 and Valkey 9.1.0; `INCREX` Redis
  8.8.0. Valkey answering `HELLO` as redis 7.2.x triggers one
  `INFO server` read of `valkey_version`. A `features` option overrides
  detection with `true`, `false` or `'auto'`.
  `Resp3Connection::capabilities()` exposes it.
- Self-healing: a feature command answered with `ERR unknown command`
  (or `NOPERM` mentioning the command) disables the feature on that
  connection and the call falls back once.
- `Resp3Store::putIfEquals(string $key, mixed $expected, mixed $value, ?int $seconds = null): bool`,
  compare-and-set on `SET ... IFEQ`. Throws `BadMethodCallException` when
  the server lacks it.
- `Resp3Lock::refresh(?int $seconds = null): bool`. `Resp3Lock::release()`
  uses `DELEX` when available, else the Lua script.
- `add()` uses a single `SET ... EX ... NX`. `putMany()` uses `MSETEX`
  when available, `SETEX` per key on a cluster when the keys span slots,
  else `MULTI`/`SETEX`/`EXEC`.
- Client-side caching on `CLIENT TRACKING` (opt in with the
  `client_tracking` block of a `resp3` store; single node and sentinel
  only). Modes `optin` and `bcast`, local stores `array` (per-process LRU)
  and `apcu`, `local_store => 'auto'`, `local_ttl`, `max_entries`,
  `max_value_bytes`, `noloop`. A tracked `GET` is one pipeline of
  `CLIENT CACHING YES`, `GET`, `PTTL` (`MGET` plus one `PTTL` per key);
  a local entry lives for the smaller of `local_ttl` and the server TTL.
  Pushes are drained before every local hit, an invalidation that arrives
  with a reply blocks the local store, `FLUSHDB` and `FLUSHALL` clear the
  namespace, and `tracking-redir-broken` disables tracking for the
  connection. Classes live in `Resp3\Laravel\Tracking`. See the README
  for the persistent-connection requirement under PHP-FPM and the memory
  bound. The `max_bytes` key (default 33554432, 32 MiB, key plus value
  bytes) caps the array store with LRU eviction; `max_entries` and
  `max_value_bytes` still apply.
- Client-side caching details: the `apcu` store needs `local_ttl` of at
  least 1 and `auto` picks it only when APCu is loaded, the connection is
  persistent and `local_ttl` is at least 1. APCu entry names are scoped
  with an HMAC keyed by `app.key`, so `APP_KEY` must be set for the
  `apcu` store. A store attaching after another store's handshake, or to
  a persistent socket that already ran a command this request, starts with
  an empty namespace. `mode` and `local_store` are validated
  only when `enabled` is true. Stores on one connection with the same
  tracking arguments share a namespace; a store with different arguments
  gets no client-side cache there. `noloop` is incompatible with cache
  tags and raw writes on the same connection. A push drain is capped at
  8 MiB (the connection closes, the read falls back to a plain `GET`).
  The tracking listener and a user `setPushListener()` are chained.
- Persistent sockets are keyed per database, username and
  `options.persistent_id`, so `default` and `cache` connections no longer
  share one socket. A clean persistent socket is kept open at request end
  (it used to be closed), and on reuse the handshake pipelines `HELLO`
  and a `PING` nonce to verify the stream is aligned, reconnecting once
  if not. This is what makes client-side cache reuse work under PHP-FPM.
- `examples/client_tracking.php`.
- `RESP3_TEST_HOST` and `RESP3_TEST_PORT` select the server for the
  feature tests. CI runs Valkey 8.1, Valkey 9.1 and Redis 8.10 on PHP 8.4
  and 8.5, plus the cluster job.

### Changed

- Every parser is created with the push queue on, so pushes and replies
  are kept apart.
- README: new "Cache driver", "Server features", "Client-side caching" and
  "Testing" sections; limitations updated.

### Fixed

- `putMany()` always returned false: inside `MULTI` the connection
  returned itself and `setex()` compared it to `'OK'`.
- `putMany()` and `many()` raised `CROSSSLOT` on a cluster; they now group
  keys per slot.
- Protocol errors from the parser are fatal for the stream: the client
  closes the socket and throws `ConnectionException`, and the next command
  reconnects.
- Nested errors inside an `EXEC` reply no longer leak as raw values;
  `EXECABORT` and queue-time errors throw `ServerException`.
- `SUBSCRIBE` blocked forever with the push queue on, because its
  confirmation is itself a push. Subscribing now uses the write-only
  `send()`.
- The test cluster never formed: every node announced 127.0.0.1 on the
  cluster bus. The harness (`tests/cluster/setup.sh`) now needs only
  Docker, forms the cluster on every host and fails loudly with the node
  logs when it does not.

## [0.6.1] - 2026-08-21

### Fixed

- Operator precedence bug in `Resp3Connector` and `Resp3ClusterConnector`
  that prevented `scheme=tls` from actually enabling TLS.

### Changed

- Updated Laravel/Symfony dev dependencies to patch the May 2026 CVEs.

## [0.6.0] - 2026-05-04

Sharded pub/sub on cluster mode. `Redis::connection()->ssubscribe()` and
`SPUBLISH` now route by CRC16 slot just like keys, so subscribers and
publishers land on the same shard without going through the cluster
bus. Closes the v0.2 "Cluster pub/sub not supported" limitation.

### Added

- `Resp3\Laravel\Connections\Resp3ClusterConnection::ssubscribe(array $channels, Closure $cb)`:
  validates that every channel hashes to the same CRC16 slot (CROSSSLOT
  with hash-tag hint otherwise), asks the router for the slot's master,
  opens a dedicated subscriber socket on it, and runs the existing
  `SubscriptionLoop` with method `'ssubscribe'`. `SUNSUBSCRIBE` runs on
  every exit path, mirroring the single-node pub/sub teardown.
- `Resp3\Laravel\Cluster\Resp3ClusterRouter::subscriberAddrForChannel(string $channel)`:
  CRC16 slot lookup that returns the master `host:port` for a channel.
- `Resp3\Laravel\Cluster\Resp3ClusterRouter::getDataPlaneOptions()`:
  exposes the router's auth/tls/timeout settings so the cluster
  connection can spin up subscriber sockets without duplicating the
  constructor wiring.
- `SPUBLISH` entry in `Resp3ClusterRouter::extractKeys()`: routes
  `Redis::command('SPUBLISH', $channel, $msg)` to the channel's slot
  master.
- `Resp3\Laravel\PubSub\SubscriptionLoop` accepts `'ssubscribe'` as a
  method, dispatches `smessage` frames to the same `($payload, $channel)`
  callback shape as `message`, and sends `SUNSUBSCRIBE` on cleanup.
- 4 new unit tests for the SubscriptionLoop sharded path (method
  acceptance, smessage dispatch, SUNSUBSCRIBE on exit, unknown method
  rejected).
- 4 new unit tests for the cluster router (subscriber addr via CRC16,
  hash-tag colocation, data-plane options snapshot, SPUBLISH extractKeys).
- 4 new unit tests for the cluster connection (same-slot validation,
  cross-slot rejection, hint message, empty channels guard).
- `tests/Feature/ClusterShardedPubSubTest`: end-to-end SSUBSCRIBE +
  SPUBLISH round trip via a `proc_open` subscriber child against the
  local 6-node cluster, plus the cross-slot rejection assertion and
  the regular-subscribe still-blocked assertion.

### Changed

- `Resp3\Laravel\Connections\Resp3ClusterConnection::createSubscription()`
  rejection message now points users at `ssubscribe()` for sharded
  delivery and the single-node `Redis::connection()` workaround for
  global pub/sub. The `BadMethodCallException` itself stays.
- `tests/Feature/ClusterPubSubTest` hint matcher updated to accept the
  new wording.
- README gains a "Sharded pub/sub on cluster" subsection under Pub/Sub.
  The Cluster mode pub/sub paragraph is rewritten to describe both the
  global rejection and the sharded option. Limitations updated:
  cluster pub/sub no longer listed; replaced with a note about the
  missing `SPSUBSCRIBE` (Redis spec limitation).

[0.6.0]: https://github.com/webpatser/laravel-resp3-cache/releases/tag/v0.6.0

## [0.5.0] - 2026-05-04

Sentinel replica reads. Set `'sentinel_read_replicas' => true` on the
sentinel config and read commands route to a random healthy replica
discovered via `SENTINEL replicas`. Writes, MULTI-buffered commands, and
pool-exhausted reads continue to land on the master. Default is `false`,
so v0.4 behaviour is unchanged for users who do not opt in.

### Added

- `Resp3\Laravel\Sentinel\SentinelDiscovery::discoverReplicas()`: queries
  seed sentinels for the live replica list. Filters out replicas flagged
  `s_down` or `o_down`. Returns an empty list (not an exception) when no
  healthy replicas are reported, so the connection layer can fall back
  to master-only routing without surfacing an error.
- `Resp3\Laravel\Sentinel\Resp3SentinelReplicaPool`: lazy connection pool
  to discovered replicas. Picks one randomly per command (mirrors the
  cluster mode `cluster_read_replicas` flag), sends `READONLY` one-shot
  per fresh connection, and refreshes the replica list when the pool
  exhausts. Throws `NoReplicasAvailableException` if a refresh still
  yields nothing.
- `Resp3\Laravel\Sentinel\NoReplicasAvailableException`: marker exception
  caught by the connection layer to trigger the silent master fallback.
- `Resp3\Laravel\Connections\Resp3SentinelConnection::command()` override
  classifies via `CommandClassifier::isReadOnly()` and routes reads to
  the replica pool when one is configured. MULTI-buffered commands and
  writes always go to the master.
- `sentinel_read_replicas` option on `Resp3SentinelConnector`. When true,
  the connector instantiates a replica pool with the same data-plane
  options as the master client and hands it to the connection.
- 6 new unit tests for `SentinelDiscovery::discoverReplicas` (healthy
  list, s_down / o_down filtering, empty reply, seed fallback, all-seeds
  dead, RESP3 map shape).
- 7 new unit tests for `Resp3SentinelReplicaPool` (read routes, retry on
  socket failure, refresh on pool exhaustion, throws when refresh empty,
  READONLY-once invariant, close drops clients, knownReplicas snapshot).
- 5 new unit tests for `Resp3SentinelConnectionRoutingTest` (read goes
  to pool, write to master, exhaustion falls back, no-pool acts like
  v0.4, MULTI keeps reads on master).
- `tests/Feature/SentinelReplicaReadsTest`: end-to-end test against the
  local sentinel docker compose. Includes a MONITOR-based assertion that
  reads actually land on a replica, plus a replicas-down fallback test.

### Changed

- `Resp3\Laravel\Client\Resp3ClientInterface` gains `pipeline(array)` so
  Sentinel-managed connections can route MULTI/EXEC through the wrapper
  without losing the existing pipeline path. Test stubs updated.
- `Resp3\Laravel\Connections\Resp3Connection` constructor now types its
  client argument as `Resp3ClientInterface` (was concretely `Resp3Client`).
  v0.4 had a latent runtime TypeError when `Resp3SentinelConnection`
  instantiated the parent; that path was masked by the docker-skip in
  the feature suite. No change for single-node or cluster users.
- `Resp3\Laravel\Sentinel\Resp3SentinelClient` gains `pipeline()` with
  the same retry-on-ConnectionException semantics as `command()`.
- README gains a "Replica reads" subsection under Sentinel mode.
  Limitations updated: replica routing is now wired up; the remaining
  caveat is that v0.5 only supports random selection.

[0.5.0]: https://github.com/webpatser/laravel-resp3-cache/releases/tag/v0.5.0

## [0.4.0] - 2026-05-04

Sentinel support. Standard Predis-compatible
`'replication' => 'sentinel'` config now Just Works against a
Sentinel-managed Valkey topology. Existing v0.1-v0.3 single-node,
cluster, and pub/sub behaviour unchanged.

### Added

- `Resp3\Laravel\Sentinel\SentinelDiscovery`: queries seed sentinels
  for the current master via `SENTINEL get-master-addr-by-name`.
  Round-robins on failure so a dead seed does not block subsequent
  tries. Throws `ConnectionException` only if no seed responds.
- `Resp3\Laravel\Sentinel\Resp3SentinelClient` (implements
  `Resp3ClientInterface`): wraps the discovery + a lazy data-plane
  `Resp3Client`. On `ConnectionException` or `READONLY ...` reply
  from a demoted master, drops the cached client, re-discovers, and
  retries the command exactly once. Subscribe and pipeline calls
  delegate transparently.
- `Resp3\Laravel\Connections\Resp3SentinelConnection` extends
  `Resp3Connection`. Inherits `command()`, `multi`/`exec`,
  `flushdb`, etc. Overrides `newSubscribeClient()` so subscriber
  loops also benefit from re-discovery.
- `Resp3\Laravel\Connectors\Resp3SentinelConnector`: implements
  `Connector`. Reads `service`, `sentinel_password`, `sentinel_timeout`
  options plus the standard data-plane password / database / TLS
  settings. `connectToCluster()` raises with a clear "sentinel and
  cluster are mutually exclusive" message.
- 6 new unit tests for `SentinelDiscovery` (master discovery, fallback
  on dead seed, all-seeds-down, malformed reply, empty service guard).
- 4 new unit tests for `Resp3SentinelClient` (route to discovered
  master, ConnectionException triggers rediscovery + retry, discovery
  failure propagates, close drops the underlying client). The
  READONLY-on-demoted-master path is covered in the feature suite.
- `tests/cluster/sentinel-compose.yml`: 1 master + 2 replicas + 3
  sentinels (quorum 2) on 127.0.0.1:6500-6502 + 26500-26502.
- `tests/cluster/sentinel-setup.sh` / `sentinel-teardown.sh` plus
  `make sentinel-up` / `sentinel-down` / `sentinel-test` targets.
- `tests/Feature/SentinelTest`: end-to-end Cache::* round trips
  through the discovered master, plus a `@group failover` test that
  kills the master container, waits for sentinel re-election, and
  verifies the next call lands on the new master.

### Changed

- `Resp3\Laravel\Connectors\Resp3Connector::connect()` detects
  `'replication' => 'sentinel'` and dispatches to the new sentinel
  connector. Single-node behaviour without that option is unchanged.
- `Resp3\Laravel\Sentinel\SentinelDiscovery::openSentinel()` returns
  `Resp3ClientInterface` so unit tests can inject a stub. Same change
  applied internally in `Resp3SentinelClient`. No public surface
  difference.
- README gains a Sentinel mode section between Pub/Sub and
  Limitations. Limitations updated: "No Sentinel" replaced with
  "Sentinel reads from replicas not yet wired up".

[0.4.0]: https://github.com/webpatser/laravel-resp3-cache/releases/tag/v0.4.0

## [0.3.0] - 2026-05-04

Pub/Sub support. Standard `Redis::subscribe()` and `Redis::psubscribe()`
work on single-node connections; cluster connections raise a clear
error pointing at the single-node workaround. v0.1 / v0.2 behaviour
unchanged.

### Added

- `Resp3\Laravel\Client\Resp3ClientInterface`: minimal contract
  (`command`, `readNext`, `close`, `isConnected`) so consumers and
  tests can swap implementations without inheriting the final
  `Resp3Client`.
- `Resp3\Laravel\Client\Resp3Client::readNext()`: blocks on the socket
  for one complete reply or push frame. Pub/sub consumes server
  pushes through this without writing new commands.
- `Resp3\Laravel\PubSub\SubscriptionLoop`: blocking loop that drives
  SUBSCRIBE/PSUBSCRIBE on a dedicated socket. Dispatches `message`
  frames as `($payload, $channel)` and `pmessage` frames as
  `($payload, $channel, $pattern)`. Returning `false` from the
  callback exits cleanly with UNSUBSCRIBE. Subscribe and unsubscribe
  acks are silently filtered.
- Signal handling: with `pcntl` loaded, SIGTERM and SIGINT exit the
  loop and run UNSUBSCRIBE before close. Subscribers under Horizon /
  Supervisor / systemd shut down without orphan connections.
- `Resp3\Laravel\Connections\Resp3Connection::createSubscription()`:
  builds a fresh subscribe-only `Resp3Client` (no persistent flag,
  no read timeout) and runs the loop. The original connection stays
  free for normal commands.
- 6 unit tests in `SubscriptionLoopTest` exercising dispatch paths
  with a stub client.
- Live publish/subscribe round trip tests in
  `tests/Feature/PubSubTest` using `proc_open` to start a subscriber
  child and publishing from the parent. Covers both subscribe and
  psubscribe.
- `tests/Feature/ClusterPubSubTest` confirms cluster pub/sub raises
  `BadMethodCallException` with a hint pointing at the single-node
  workaround.

### Changed

- `Resp3\Laravel\Client\Resp3Client` implements `Resp3ClientInterface`.
  No public API change.
- Connection setup: `timeout: 0.0` on the constructor now means
  "block forever between reads" rather than "fail immediately on
  connect". The connect step clamps to a 5s minimum so subscribe
  loops can negotiate the initial socket. On macOS,
  `stream_set_timeout(socket, 0, 0)` flips to immediate timeout, so
  the timeout is left at the OS default when 0 is requested.
- `Resp3\Laravel\Connections\Resp3ClusterConnection::createSubscription()`
  error message now points at the single-node workaround and the
  v0.4 sharded pub/sub plan instead of just refusing.

### Removed

- "No Pub/Sub" from the Limitations list. Cluster pub/sub
  (SSUBSCRIBE) remains out of scope for v0.3.

[0.3.0]: https://github.com/webpatser/laravel-resp3-cache/releases/tag/v0.3.0

## [0.2.0] - 2026-05-04

Redis Cluster mode. Existing Laravel apps drop in by setting
`'client' => 'resp3'` on the standard `clusters` config shape; no API
changes for v0.1 single-node users.

### Added

- `Resp3\Laravel\Cluster\CRC16`: XMODEM polynomial CRC + hash tag
  extraction. Slot calculation matches phpredis and Predis bit-for-bit.
- `Resp3\Laravel\Cluster\RedirectionParser`: parses `MOVED` and `ASK`
  error messages, IPv4 and bracketed IPv6 hosts.
- `Resp3\Laravel\Cluster\Resp3ClusterRouter`: routes commands by slot
  across multiple `Resp3Client` instances. Lazy topology via
  `CLUSTER SHARDS` (Redis 7+) with `CLUSTER SLOTS` fallback. Cached
  slot map refreshed on MOVED, one-shot ASK redirects without map
  updates. 5x retry with exponential backoff capped at 200ms.
- `Resp3\Laravel\Cluster\CommandClassifier`: read-only command set used
  by replica routing.
- `Resp3\Laravel\Connections\Resp3ClusterConnection`: extends the
  single-node Connection. `flushdb`/`flushall` broadcast to all
  masters; `multi`/`exec` validate all queued commands hash to the
  same slot before sending.
- `Resp3\Laravel\Connectors\Resp3ClusterConnector`: implements
  `Illuminate\Contracts\Redis\Connector::connectToCluster()`.
  `Resp3Connector::connectToCluster()` now delegates here instead of
  throwing `RuntimeException`.
- `cluster_read_replicas` cluster option: when true, reads route to a
  random replica for the slot. Fresh replica connections get a
  one-shot `READONLY`.
- `tests/cluster/docker-compose.yml`: 6-node Valkey 8 cluster (3
  masters + 3 replicas) on 127.0.0.1:7100-7105 with cluster bus on
  17100-17105. `make cluster-up` / `cluster-down` / `cluster-test`
  targets in the new Makefile.
- `bench/cluster_cache_many.php` measuring `Cache::many()` with hash
  tag-grouped keys against the cluster.
- CI `cluster-test` job that boots the 6-node cluster on the GitHub
  runner and runs the cluster suite on PHP 8.4 and 8.5.

### Changed

- `Resp3\Laravel\Connectors\Resp3Connector::connectToCluster()` now
  delegates to `Resp3ClusterConnector` instead of throwing. Single-node
  callers see no behaviour change.
- README gains a "Cluster mode" section with config example, hash tag
  guidance, replica routing, and local cluster setup notes.

### Removed

- "No Redis Cluster" from the Limitations list. Sentinel and Pub/Sub
  remain out of scope.

### Compatibility notes

- v0.1.x single-node behaviour is unchanged.
- Cross-slot multi-key commands now raise a `RuntimeException` with
  "CROSSSLOT" in the message instead of silently failing on the
  server. Use `{hash tag}` syntax to colocate keys.
- Local cluster boot via Docker compose works on Linux; macOS Docker
  Desktop sometimes fails the cluster-bus handshake when nodes
  announce 127.0.0.1. CI is the source of truth for cluster tests.

[0.2.0]: https://github.com/webpatser/laravel-resp3-cache/releases/tag/v0.2.0

## [0.1.0] - 2026-05-04

First public release. Drop-in synchronous Redis client and Laravel cache
driver backed by the [ext-resp3][php-resp3] C parser.

### Added

- `Resp3\Laravel\Client\Resp3Client`: synchronous TCP client.
  Constructor accepts host, port, username (Redis 6 ACL), password,
  database, TLS toggle, timeout, persistent flag, and optional TLS
  context. Public `command(string $name, ...$args): mixed` plus
  `pipeline(array $commands): array`. Lazy connect on first call.
  HELLO 3 negotiates RESP3; SELECT runs when database > 0.
- `Resp3\Laravel\Client\CommandEncoder`: RESP2 array encoder for
  command serialisation (works on both RESP2 and RESP3 servers).
- `Resp3\Laravel\Connections\Resp3Connection`: extends Laravel's
  `Illuminate\Redis\Connections\Connection`. Implements get, mget,
  set, setex, setnx, del, eval, flushdb, multi/exec/discard, plus
  `__call` dispatch for any other command.
- `Resp3\Laravel\Connectors\Resp3Connector`: implements
  `Illuminate\Contracts\Redis\Connector`. Single-node only; cluster
  throws a clear "not yet supported" error.
- `Resp3\Laravel\Resp3ServiceProvider`: registers the `resp3` client
  via `RedisManager::extend()` on resolve. Apps opt in by setting
  `'redis' => ['client' => 'resp3', ...]` in config/database.php.
- 17 tests in two suites: 8 unit tests for `CommandEncoder`, 9 feature
  tests against a live Valkey covering `Cache::*` and direct
  `Redis::connection()` calls (including MULTI/EXEC pipelining).
- `bench/laravel_cache_many.php` measuring `Cache::many()` of 1,000
  keys per call against the same workload via predis. Median over 5
  runs. First run on macOS ARM64 with Valkey 9.0.3: **+27.5%** versus
  predis.

### Compatibility

- PHP 8.4 or 8.5
- Laravel 11, 12, or 13
- Redis or Valkey 6.0+ (RESP3 capable; HELLO 3 must succeed)

### Limitations in v0.1

- No Redis Cluster (smart routing, MOVED/ASK followups).
- No Sentinel failover.
- No Pub/Sub (`subscribe`, `psubscribe` throw `BadMethodCallException`).
- No connection pooling beyond `STREAM_CLIENT_PERSISTENT`.

[Unreleased]: https://github.com/webpatser/laravel-resp3-cache/compare/v0.7.0...HEAD
[0.7.0]: https://github.com/webpatser/laravel-resp3-cache/compare/v0.6.1...v0.7.0
[0.6.1]: https://github.com/webpatser/laravel-resp3-cache/compare/v0.6.0...v0.6.1
[0.1.0]: https://github.com/webpatser/laravel-resp3-cache/releases/tag/v0.1.0

[kac]: https://keepachangelog.com/en/1.1.0/
[semver]: https://semver.org/spec/v2.0.0.html
[php-resp3]: https://github.com/webpatser/php-resp3
