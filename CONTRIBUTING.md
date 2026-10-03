# Contributing

Thanks for taking a look. This file explains how to build, test, and
ship a change. The [code of conduct][coc] applies in every interaction
here.

## Local setup

You need PHP 8.4 or 8.5 and the [ext-resp3][php-resp3] extension
loaded.

```bash
git clone git@github.com:webpatser/laravel-resp3-cache.git
cd laravel-resp3-cache
composer install --ignore-platform-req=ext-resp3
```

The `--ignore-platform-req` flag tells Composer to install the dev
deps even when ext-resp3 is loaded only via `-d extension=...` rather
than php.ini. Once it is in php.ini you can drop the flag.

## Tests

Two suites:

```bash
# Unit (no Redis needed)
vendor/bin/phpunit --testsuite=Unit

# Feature (needs Redis or Valkey on 127.0.0.1:6379)
php -d extension=/path/to/resp3.so vendor/bin/phpunit --testsuite=Feature

# Or both at once
php -d extension=/path/to/resp3.so vendor/bin/phpunit
```

Feature tests use Orchestra Testbench to spin up a minimal Laravel
container per test class and skip themselves cleanly if Redis is not
reachable.

### Choosing the server

Feature tests connect to `127.0.0.1:6379`. Override with environment
variables, which `tests/Support/Env.php` reads:

```bash
RESP3_TEST_HOST=127.0.0.1 RESP3_TEST_PORT=6380 \
  php -d extension=/path/to/resp3.so vendor/bin/phpunit --testsuite=Feature
```

A common local setup is Valkey on 6379 and a Redis container on 6380, then
one run per port. Tests for a feature the server lacks (`MSETEX`,
`SET IFEQ`, `CLIENT TRACKING`) skip themselves.

CI runs the suite on Valkey 8.1, Valkey 9.1 and Redis 8.10, each on PHP 8.4
and 8.5, plus the cluster job. The APCu local store tests need
`extension=apcu` and `apc.enable_cli=1`, and a `local_ttl` of at least 1.

### Cluster and sentinel suites

```bash
make cluster-up      # tests/cluster/setup.sh, 6 Valkey nodes on 7100-7105
make cluster-test    # cluster suite, then tear down
make sentinel-test   # sentinel suite, then tear down
```

`tests/cluster/setup.sh` needs only Docker (`valkey-cli` runs inside the
node containers). It is idempotent and exits non-zero, printing the
cluster-init log and each node's `cluster info`, when the cluster does not
reach `cluster_state:ok` with all 16384 slots. Run it before the cluster
tests; they skip when the cluster is not running.

## Running the bench

```bash
php -d extension=/path/to/resp3.so bench/laravel_cache_many.php
```

Bench writes a per-run report to `bench/results/`.

## Pull requests

Open one PR per logical change. Use the PR template; tick what
applies and explain anything you skipped.

Commits in this repo do not include AI attribution (no
`Co-Authored-By: Claude`, `Generated with`, etc). Keep the message
focused on the why, not the what; the diff already shows the what.

## Reporting bugs and security issues

Open a GitHub issue using the bug report template. For security
issues, follow `SECURITY.md` and email `oss@downsized.nl` instead
of opening a public issue.

[coc]: ./CODE_OF_CONDUCT.md
[php-resp3]: https://github.com/webpatser/php-resp3
