<?php declare(strict_types=1);

namespace Resp3\Laravel\Cluster;

/**
 * CRC16 / XMODEM (poly 0x1021) with Redis Cluster slot semantics.
 *
 * `slot($key)` returns 0..16383. If $key contains a `{tag}` substring with
 * non-empty content, only the bytes between the first `{` and the next `}`
 * are hashed, per the Redis cluster spec. This matches how phpredis and
 * Predis distribute keys, so apps that already use hash tags keep working.
 */
final class CRC16
{
    /** @var array<int, int>|null Lazy-initialised CRC table, 256 entries. */
    private static ?array $table = null;

    public static function slot(string $key): int
    {
        $tag = self::extractHashTag($key);
        $hashable = $tag ?? $key;
        return self::crc16($hashable) & 0x3FFF;
    }

    /**
     * Returns the substring between the first `{` and the next `}` if both
     * exist and the substring is non-empty, otherwise null.
     *
     *   {user1}.profile      -> "user1"
     *   prefix{tag}suffix    -> "tag"
     *   no-tag-key           -> null
     *   foo{}bar             -> null  (empty tag, hash whole key)
     *   foo{bar              -> null  (no closing brace)
     */
    public static function extractHashTag(string $key): ?string
    {
        $open = strpos($key, '{');
        if ($open === false) return null;
        $close = strpos($key, '}', $open + 1);
        if ($close === false || $close === $open + 1) return null;
        return substr($key, $open + 1, $close - $open - 1);
    }

    private static function crc16(string $data): int
    {
        $table = self::$table ??= self::buildTable();
        $crc = 0;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $crc = (($crc << 8) & 0xFFFF) ^ $table[(($crc >> 8) ^ ord($data[$i])) & 0xFF];
        }
        return $crc & 0xFFFF;
    }

    /** @return array<int, int> */
    private static function buildTable(): array
    {
        $table = [];
        for ($byte = 0; $byte < 256; $byte++) {
            $crc = $byte << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
            $table[$byte] = $crc;
        }
        return $table;
    }
}
