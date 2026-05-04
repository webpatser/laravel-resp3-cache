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
