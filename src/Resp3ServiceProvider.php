<?php declare(strict_types=1);

namespace Resp3\Laravel;

use Illuminate\Redis\RedisManager;
use Illuminate\Support\ServiceProvider;
use Resp3\Laravel\Connectors\Resp3Connector;

/**
 * Registers the 'resp3' Redis client with Laravel's RedisManager.
 *
 * Apps opt in via `'redis' => ['client' => 'resp3', ...]` in
 * config/database.php. The standard `redis` cache driver picks up the new
 * client through Laravel's existing extension hook.
 */
final class Resp3ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving('redis', function (RedisManager $redis): void {
            $redis->extend('resp3', fn () => new Resp3Connector());
        });
    }

    public function boot(): void
    {
        // Nothing to do at boot time; the connector kicks in lazily when the
        // app first asks for Redis::connection().
    }
}
