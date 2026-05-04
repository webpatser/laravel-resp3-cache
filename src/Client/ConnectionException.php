<?php declare(strict_types=1);

namespace Resp3\Laravel\Client;

/**
 * Raised on socket errors (connect failure, broken pipe, read timeout).
 * Protocol-level errors from the server come back as Resp3\RedisException
 * via the ext-resp3 parser.
 */
final class ConnectionException extends \RuntimeException
{
}
