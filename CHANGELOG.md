# Changelog

All notable changes to this project go here. Format follows
[Keep a Changelog 1.1][kac]. Versions follow [Semantic Versioning 2.0][semver]
once the project hits 1.0; until then, breaking changes can land in any 0.x
minor and are called out in the entry.

## [Unreleased]

Nothing yet.

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

[Unreleased]: https://github.com/webpatser/laravel-resp3-cache/compare/v0.3.0...HEAD
[0.1.0]: https://github.com/webpatser/laravel-resp3-cache/releases/tag/v0.1.0

[kac]: https://keepachangelog.com/en/1.1.0/
[semver]: https://semver.org/spec/v2.0.0.html
[php-resp3]: https://github.com/webpatser/php-resp3
