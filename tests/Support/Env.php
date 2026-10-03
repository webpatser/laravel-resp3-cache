<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Support;

/**
 * Single-node test target, overridable via RESP3_TEST_HOST / RESP3_TEST_PORT
 * so the same suite runs against Valkey and Redis.
 */
final class Env
{
    public static function host(): string
    {
        $host = getenv('RESP3_TEST_HOST');

        return $host === false || $host === '' ? '127.0.0.1' : $host;
    }

    public static function port(): int
    {
        $port = getenv('RESP3_TEST_PORT');

        return $port === false || $port === '' ? 6379 : (int) $port;
    }

    public static function address(): string
    {
        return self::host() . ':' . self::port();
    }

    public static function reachable(): bool
    {
        return (bool) @fsockopen(self::host(), self::port(), $errno, $errstr, 0.5);
    }
}
