<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Resp3\Laravel\Cluster\RedirectionParser;

final class RedirectionParserTest extends TestCase
{
    public function test_parse_moved_ipv4(): void
    {
        $r = RedirectionParser::parse('MOVED 1234 127.0.0.1:6380');
        $this->assertSame('MOVED', $r['kind']);
        $this->assertSame(1234, $r['slot']);
        $this->assertSame('127.0.0.1', $r['host']);
        $this->assertSame(6380, $r['port']);
    }

    public function test_parse_ask_ipv4(): void
    {
        $r = RedirectionParser::parse('ASK 4567 redis-node-2.internal:6379');
        $this->assertSame('ASK', $r['kind']);
        $this->assertSame(4567, $r['slot']);
        $this->assertSame('redis-node-2.internal', $r['host']);
        $this->assertSame(6379, $r['port']);
    }

    public function test_parse_moved_ipv6(): void
    {
        $r = RedirectionParser::parse('MOVED 1 [::1]:6379');
        $this->assertNotNull($r);
        $this->assertSame('::1', $r['host']);
        $this->assertSame(6379, $r['port']);
    }

    public function test_parse_returns_null_for_non_redirect_message(): void
    {
        $this->assertNull(RedirectionParser::parse('WRONGTYPE Operation against a key'));
        $this->assertNull(RedirectionParser::parse('ERR random error'));
        $this->assertNull(RedirectionParser::parse(''));
    }

    public function test_parse_returns_null_for_malformed_redirect(): void
    {
        $this->assertNull(RedirectionParser::parse('MOVED notaslot 127.0.0.1:6380'));
        $this->assertNull(RedirectionParser::parse('MOVED 1234 127.0.0.1'));
    }
}
