<?php declare(strict_types=1);

namespace Resp3\Laravel\Client;

/**
 * Encodes a Redis command as a RESP2 array of bulk strings.
 *
 * The command-side wire format is identical for RESP2 and RESP3, so this
 * encoder works regardless of which protocol the server negotiated.
 */
final class CommandEncoder
{
    /**
     * @param list<string|int|float> $argv Command name plus arguments.
     */
    public static function encode(array $argv): string
    {
        if ($argv === []) {
            throw new \InvalidArgumentException('Cannot encode an empty command');
        }

        $out = '*' . count($argv) . "\r\n";
        foreach ($argv as $arg) {
            $s = is_string($arg) ? $arg : (string) $arg;
            $out .= '$' . strlen($s) . "\r\n" . $s . "\r\n";
        }
        return $out;
    }
}
