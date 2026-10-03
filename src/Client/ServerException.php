<?php declare(strict_types=1);

namespace Resp3\Laravel\Client;

use Resp3\RedisException;
use RuntimeException;
use Throwable;

/**
 * A Redis error reply surfaced to application code.
 *
 * The client layer (Resp3Client, cluster router, sentinel client) returns
 * error replies as Resp3\RedisException values so redirects and failover can
 * be handled in place. Once a reply reaches a Laravel Connection it is
 * thrown as this exception instead. The message is the Redis error message
 * and $prefix is its first token (ERR, WRONGTYPE, NOAUTH, EXECABORT, ...).
 */
class ServerException extends RuntimeException
{
    public readonly string $prefix;

    public function __construct(
        private readonly RedisException $redisException,
        ?Throwable $previous = null,
    ) {
        $this->prefix = $redisException->prefix;

        parent::__construct($redisException->getMessage(), 0, $previous ?? $redisException);
    }

    /** The error reply as the parser produced it. */
    public function getRedisException(): RedisException
    {
        return $this->redisException;
    }
}
