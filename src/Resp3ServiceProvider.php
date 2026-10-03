<?php declare(strict_types=1);

namespace Resp3\Laravel;

use Illuminate\Cache\CacheManager;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\ServiceProvider;
use Resp3\Laravel\Cache\Resp3Store;
use Resp3\Laravel\Connectors\Resp3Connector;

/**
 * Registers the 'resp3' Redis client with Laravel's RedisManager.
 *
 * Apps opt in via `'redis' => ['client' => 'resp3', ...]` in
 * config/database.php. The standard `redis` cache driver picks up the new
 * client through Laravel's existing extension hook.
 *
 * Also registers the `resp3` cache driver (Resp3Store): a cache store with
 * `'driver' => 'resp3'` takes the same options as the `redis` driver
 * (connection, lock_connection, prefix, serializable_classes).
 */
final class Resp3ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving('redis', function (RedisManager $redis): void {
            $redis->extend('resp3', fn () => new Resp3Connector());
        });

        $this->callAfterResolving('cache', function (CacheManager $cache): void {
            // CacheManager binds the creator to itself, so $this below is the
            // manager. Mirrors CacheManager::createRedisDriver().
            $cache->extend('resp3', function ($app, array $config) {
                $connection = $config['connection'] ?? 'default';

                $arguments = [$app['redis'], $this->getPrefix($config), $connection];

                // Laravel 11 has no serializable classes option.
                if (method_exists($this, 'getSerializableClasses')) {
                    $arguments[] = $this->getSerializableClasses($config);
                }

                $store = new Resp3Store(...$arguments);

                return $this->repository(
                    $store->setLockConnection($config['lock_connection'] ?? $connection),
                    $config,
                );
            });
        });
    }

    public function boot(): void
    {
        // Nothing to do at boot time; the connector kicks in lazily when the
        // app first asks for Redis::connection().
    }
}
