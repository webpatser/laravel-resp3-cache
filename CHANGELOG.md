# Changelog

All notable changes to this project go here. Format follows
[Keep a Changelog 1.1][kac]. Versions follow [Semantic Versioning 2.0][semver]
once the project hits 1.0; until then, breaking changes can land in any 0.x
minor and are called out in the entry.

## [Unreleased]

Nothing yet.

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

[Unreleased]: https://github.com/webpatser/laravel-resp3-cache/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/webpatser/laravel-resp3-cache/releases/tag/v0.1.0

[kac]: https://keepachangelog.com/en/1.1.0/
[semver]: https://semver.org/spec/v2.0.0.html
[php-resp3]: https://github.com/webpatser/php-resp3
