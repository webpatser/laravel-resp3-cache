<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Resp3\Laravel\Client\CommandEncoder;

final class CommandEncoderTest extends TestCase
{
    public function test_single_command(): void
    {
        $this->assertSame("*1\r\n\$4\r\nPING\r\n", CommandEncoder::encode(['PING']));
    }

    public function test_get_with_key(): void
    {
        $this->assertSame(
            "*2\r\n\$3\r\nGET\r\n\$3\r\nfoo\r\n",
            CommandEncoder::encode(['GET', 'foo']),
        );
    }

    public function test_set_with_value(): void
    {
        $this->assertSame(
            "*3\r\n\$3\r\nSET\r\n\$3\r\nkey\r\n\$5\r\nvalue\r\n",
            CommandEncoder::encode(['SET', 'key', 'value']),
        );
    }

    public function test_binary_safe_payload(): void
    {
        $payload = "ab\x00\x01cd";
        $expected = "*3\r\n\$3\r\nSET\r\n\$1\r\nk\r\n\$" . strlen($payload) . "\r\n" . $payload . "\r\n";
        $this->assertSame($expected, CommandEncoder::encode(['SET', 'k', $payload]));
    }

    public function test_integer_argument_serialised_as_string(): void
    {
        $this->assertSame(
            "*3\r\n\$5\r\nSETEX\r\n\$1\r\nk\r\n\$2\r\n60\r\n",
            CommandEncoder::encode(['SETEX', 'k', 60]),
        );
    }

    public function test_float_argument_serialised(): void
    {
        $encoded = CommandEncoder::encode(['SET', 'k', 3.14]);
        $this->assertStringContainsString("3.14", $encoded);
    }

    public function test_empty_string_argument(): void
    {
        $this->assertSame(
            "*3\r\n\$3\r\nSET\r\n\$1\r\nk\r\n\$0\r\n\r\n",
            CommandEncoder::encode(['SET', 'k', '']),
        );
    }

    public function test_empty_command_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CommandEncoder::encode([]);
    }
}
