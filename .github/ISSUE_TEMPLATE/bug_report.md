---
name: Bug report
about: Something behaved differently than you expected.
title: ''
labels: bug
assignees: ''
---

## What happened

One or two sentences. What did you expect from a Cache or Redis call,
what did you get?

## Reproducer

```php
// minimal Laravel snippet that triggers the issue
Cache::put('foo', 'bar');
$got = Cache::get('foo');
var_dump($got);
```

## Expected output

What `var_dump` should have printed, or which exception type you
expected.

## Actual output

What you got. If an exception, the message and stack trace.

## Environment

- Laravel version
- PHP version and SAPI: `php -v`
- ext-resp3 version: `php -r 'echo resp3_version();'`
- Operating system and architecture: `uname -srm`
- Redis or Valkey version on the wire
- Relevant `config/database.php` redis section

## Anything else
