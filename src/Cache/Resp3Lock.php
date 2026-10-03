<?php declare(strict_types=1);

namespace Resp3\Laravel\Cache;

use Illuminate\Cache\RedisLock;
use InvalidArgumentException;
use Resp3\Laravel\Client\ServerCapabilities;
use Resp3\Laravel\Client\ServerException;
use Resp3\Laravel\Connections\Resp3Connection;

/**
 * Redis lock that uses the conditional commands of Redis 8.4+ / Valkey 8.1+
 * when the server has them:
 *
 * - release(): `DELEX name IFEQ owner` instead of the Lua compare-and-delete.
 * - refresh(): `SET name owner IFEQ owner EX seconds`, with a Lua fallback.
 *
 * @property Resp3Connection $redis
 */
class Resp3Lock extends RedisLock
{
    /**
     * Lua fallback for refresh(): extend the TTL only while we own the lock.
     */
    private const REFRESH_SCRIPT = <<<'LUA'
if redis.call("get", KEYS[1]) == ARGV[1] then
    return redis.call("expire", KEYS[1], ARGV[2])
else
    return 0
end
LUA;

    public function __construct(Resp3Connection $redis, $name, $seconds, $owner = null)
    {
        parent::__construct($redis, $name, $seconds, $owner);
    }

    public function release()
    {
        if (Resp3Store::supports($this->redis, ServerCapabilities::DELEX)) {
            try {
                return (int) $this->redis->command('DELEX', [$this->name, 'IFEQ', $this->owner]) === 1;
            } catch (ServerException $e) {
                Resp3Store::disableIfUnsupported($this->redis, ServerCapabilities::DELEX, $e);
            }
        }

        return parent::release();
    }

    /**
     * Extend the lock's TTL to $seconds (default: the TTL it was created
     * with) while it is still held by this owner.
     *
     * @return bool false when the lock expired or is held by another owner
     */
    public function refresh(?int $seconds = null): bool
    {
        $seconds ??= $this->seconds;

        if ($seconds <= 0) {
            throw new InvalidArgumentException('A lock can only be refreshed with a positive number of seconds.');
        }

        if (Resp3Store::supports($this->redis, ServerCapabilities::SET_IF_EQ)) {
            try {
                return $this->redis->command('SET', [
                    $this->name, $this->owner, 'IFEQ', $this->owner, 'EX', (string) $seconds,
                ]) === 'OK';
            } catch (ServerException $e) {
                Resp3Store::disableIfUnsupported(
                    $this->redis, ServerCapabilities::SET_IF_EQ, $e, syntaxErrorMeansUnsupported: true,
                );
            }
        }

        return (int) $this->redis->eval(self::REFRESH_SCRIPT, 1, $this->name, $this->owner, (string) $seconds) === 1;
    }
}
