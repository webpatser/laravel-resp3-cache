# Security policy

## Reporting a vulnerability

Send an email to `oss@downsized.nl` with subject line
`[laravel-resp3-cache security]`. Include a short description, a
minimal reproducer, and your assessment of impact.

The maintainer acknowledges within seven days. For confirmed issues,
expect a fix and coordinated disclosure within thirty days.

Please do not open a public GitHub issue for security reports until a
fix is available.

## Scope

In scope:

- Anything in this package's `src/` that lets crafted server input
  cause undefined behaviour in the host Laravel application
  (memory corruption, infinite loops, secret disclosure).
- Auth or TLS handling defects in `Resp3Client` (sending plaintext
  credentials when TLS is requested, accepting invalid certificates
  silently, etc).
- Logic flaws in `Resp3Connection::multi()` / `exec()` that could
  break transaction atomicity from the consumer's perspective.

Out of scope (open a regular issue instead):

- Performance regressions on benign input.
- Documentation gaps.
- Issues in the underlying [ext-resp3][php-resp3] parser; report
  those upstream.

[php-resp3]: https://github.com/webpatser/php-resp3/blob/main/SECURITY.md
