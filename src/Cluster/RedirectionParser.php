<?php declare(strict_types=1);

namespace Resp3\Laravel\Cluster;

/**
 * Parses `MOVED <slot> <host:port>` and `ASK <slot> <host:port>` error
 * messages emitted by a Redis cluster node when a key lives elsewhere.
 *
 * Handles IPv4 and IPv6 (bracketed) host forms.
 */
final class RedirectionParser
{
    /**
     * @return array{kind:'MOVED'|'ASK',slot:int,host:string,port:int}|null
     *         null if the message is not a cluster redirect.
     */
    public static function parse(string $message): ?array
    {
        // Server-side messages come back without the leading '-' (the parser
        // strips the type byte). Strip leading "MOVED " / "ASK " then split.
        if (preg_match('/^(MOVED|ASK)\s+(\d+)\s+(\S+):(\d+)$/', $message, $m)) {
            $host = $m[3];
            // IPv6 hosts arrive bracketed: "[::1]"
            if ($host !== '' && $host[0] === '[' && str_ends_with($host, ']')) {
                $host = substr($host, 1, -1);
            }
            return [
                'kind' => $m[1] === 'MOVED' ? 'MOVED' : 'ASK',
                'slot' => (int) $m[2],
                'host' => $host,
                'port' => (int) $m[4],
            ];
        }
        return null;
    }
}
