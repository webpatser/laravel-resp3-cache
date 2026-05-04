# Changelog

All notable changes to this project go here. Format follows
[Keep a Changelog 1.1][kac]. Versions follow [Semantic Versioning 2.0][semver]
once the project hits 1.0; until then, breaking changes can land in any 0.x
minor and are called out in the entry.

## [Unreleased]

Nothing yet.

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

[Unreleased]: https://github.com/webpatser/laravel-resp3-cache/compare/v0.5.0...HEAD
[0.1.0]: https://github.com/webpatser/laravel-resp3-cache/releases/tag/v0.1.0

[kac]: https://keepachangelog.com/en/1.1.0/
[semver]: https://semver.org/spec/v2.0.0.html
[php-resp3]: https://github.com/webpatser/php-resp3
