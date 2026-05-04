<?php declare(strict_types=1);

namespace Resp3\Laravel\Cluster;

/**
 * Classifies Redis commands as read-only or write so the cluster router can
 * route reads to replicas (when `cluster_read_replicas` is enabled).
 *
 * The set is intentionally conservative: anything not listed here is treated
 * as a write and routed to the master. Adding more read commands later is a
 * non-breaking change.
 */
final class CommandClassifier
{
    /** @var array<string, true> upper-cased command names */
    private const READ_ONLY = [
        // strings
        'GET' => true, 'MGET' => true, 'STRLEN' => true, 'GETRANGE' => true,
        'SUBSTR' => true, 'BITCOUNT' => true, 'BITPOS' => true,
        // hashes
        'HGET' => true, 'HMGET' => true, 'HGETALL' => true, 'HKEYS' => true,
        'HVALS' => true, 'HLEN' => true, 'HEXISTS' => true, 'HSTRLEN' => true,
        // lists
        'LRANGE' => true, 'LLEN' => true, 'LINDEX' => true, 'LPOS' => true,
        // sets
        'SMEMBERS' => true, 'SCARD' => true, 'SISMEMBER' => true,
        'SMISMEMBER' => true, 'SRANDMEMBER' => true, 'SDIFF' => true,
        'SINTER' => true, 'SUNION' => true,
        // sorted sets
        'ZRANGE' => true, 'ZRANGEBYSCORE' => true, 'ZRANGEBYLEX' => true,
        'ZREVRANGE' => true, 'ZSCORE' => true, 'ZCARD' => true,
        'ZCOUNT' => true, 'ZRANK' => true, 'ZREVRANK' => true,
        'ZLEXCOUNT' => true,
        // generic
        'EXISTS' => true, 'TYPE' => true, 'TTL' => true, 'PTTL' => true,
        'OBJECT' => true, 'DEBUG' => true,
    ];

    public static function isReadOnly(string $command): bool
    {
        return isset(self::READ_ONLY[strtoupper($command)]);
    }
}
