# Pull request

## What this changes

One paragraph. What did you do, why does it belong in this repo.

## Checklist

- [ ] `composer validate` is clean.
- [ ] Unit tests pass: `vendor/bin/phpunit --testsuite=Unit`.
- [ ] Feature tests pass against a local Redis or Valkey:
      `php -d extension=/path/to/resp3.so vendor/bin/phpunit --testsuite=Feature`.
- [ ] If the change touches the Redis client surface, new feature
      tests cover the new code paths including the unhappy paths.
- [ ] If the change shifts performance, `bench/laravel_cache_many.php`
      is rerun and the README numbers are refreshed (or you note the
      gap).
- [ ] No AI attribution in commit messages.

## Notes for the reviewer

Anything you want a second pair of eyes on. Trade-offs you considered.

## Linked issue

Closes #...
