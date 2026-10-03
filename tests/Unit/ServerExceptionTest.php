<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Resp3\Laravel\Client\ServerException;
use Resp3\RedisException;
use RuntimeException;

final class ServerExceptionTest extends TestCase
{
    private function redisError(string $message, string $prefix): RedisException
    {
        $error = new RedisException($message);
        $error->prefix = $prefix;

        return $error;
    }

    public function test_carries_prefix_message_and_original_exception(): void
    {
        $error = $this->redisError('WRONGTYPE Operation against a key holding the wrong kind of value', 'WRONGTYPE');

        $e = new ServerException($error);

        $this->assertInstanceOf(RuntimeException::class, $e);
        $this->assertSame('WRONGTYPE', $e->prefix);
        $this->assertSame($error->getMessage(), $e->getMessage());
        $this->assertSame($error, $e->getRedisException());
    }

    public function test_previous_defaults_to_the_redis_exception(): void
    {
        $error = $this->redisError('NOAUTH Authentication required.', 'NOAUTH');

        $e = new ServerException($error);

        $this->assertSame($error, $e->getPrevious());
    }

    public function test_explicit_previous_wins(): void
    {
        $error = $this->redisError('ERR boom', 'ERR');
        $previous = new RuntimeException('cause');

        $e = new ServerException($error, $previous);

        $this->assertSame($previous, $e->getPrevious());
        $this->assertSame($error, $e->getRedisException());
    }

    public function test_empty_prefix_is_kept(): void
    {
        $e = new ServerException($this->redisError('weird', ''));

        $this->assertSame('', $e->prefix);
    }
}
