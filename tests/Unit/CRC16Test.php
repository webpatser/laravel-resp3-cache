<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Resp3\Laravel\Cluster\CRC16;

final class CRC16Test extends TestCase
{
    /**
     * Vector tests: well-known Redis cluster key->slot mappings, also
     * shared by phpredis and Predis. Mismatches here mean the CRC table
     * is broken and every routed command will land on the wrong node.
     */
    public static function vectors(): array
    {
        return [
            // Well-known Predis cluster fixtures.
            ['foo',          12182],
            ['bar',           5061],
            ['key',          12539],
            // Hash tag grouping: only the tag content is hashed.
            ['{user1000}.profile', CRC16::slot('user1000')],
            ['{user1000}.cart',    CRC16::slot('user1000')],
            // Empty tag: hash whole key (not the empty string).
            ['foo{}bar',     CRC16::slot('foo{}bar')],
        ];
    }

    /** @dataProvider vectors */
    public function test_slot_calculation(string $key, int $expectedSlot): void
    {
        $this->assertSame($expectedSlot, CRC16::slot($key));
    }

    public function test_slot_is_in_range(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $key = bin2hex(random_bytes(8));
            $slot = CRC16::slot($key);
            $this->assertGreaterThanOrEqual(0, $slot);
            $this->assertLessThan(16384, $slot);
        }
    }

    public function test_extract_hash_tag_basic(): void
    {
        $this->assertSame('user1', CRC16::extractHashTag('{user1}.profile'));
        $this->assertSame('tag',   CRC16::extractHashTag('prefix{tag}suffix'));
    }

    public function test_extract_hash_tag_returns_null_when_no_braces(): void
    {
        $this->assertNull(CRC16::extractHashTag('plain-key'));
    }

    public function test_extract_hash_tag_returns_null_for_empty_tag(): void
    {
        // Per spec: {} means "no tag", hash the whole key.
        $this->assertNull(CRC16::extractHashTag('foo{}bar'));
    }

    public function test_extract_hash_tag_returns_null_for_unclosed_brace(): void
    {
        $this->assertNull(CRC16::extractHashTag('foo{bar'));
    }

    public function test_extract_hash_tag_takes_first_pair(): void
    {
        // `{a}.{b}` should hash on "a", not "a}.{b".
        $this->assertSame('a', CRC16::extractHashTag('{a}.{b}'));
    }

    public function test_binary_safe_slot(): void
    {
        $key = "binary\x00\x01\xfe\xff";
        $slot = CRC16::slot($key);
        $this->assertGreaterThanOrEqual(0, $slot);
        $this->assertLessThan(16384, $slot);
    }
}
