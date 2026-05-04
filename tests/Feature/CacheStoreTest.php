<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase;
use Resp3\Laravel\Resp3ServiceProvider;

/**
 * End-to-end test: Laravel Cache facade -> RedisStore -> Resp3Connection ->
 * Resp3Client -> ext-resp3 parser -> live Valkey on 127.0.0.1:6379.
 *
 * Skips if the parser extension is missing or the server cannot be reached.
 */
final class CacheStoreTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [Resp3ServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.redis', [
            'client' => 'resp3',
            'options' => ['prefix' => 'r3test:'],
            'default' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'database' => 0,
            ],
        ]);
        $app['config']->set('cache.default', 'redis');
        $app['config']->set('cache.stores.redis', [
            'driver' => 'redis',
            'connection' => 'default',
            'lock_connection' => 'default',
        ]);
    }

    protected function setUp(): void
    {
        if (!extension_loaded('resp3')) {
            $this->markTestSkipped('ext-resp3 is not loaded');
        }
        if (!@fsockopen('127.0.0.1', 6379, $_, $_, 0.5)) {
            $this->markTestSkipped('No Redis or Valkey reachable on 127.0.0.1:6379');
        }

        parent::setUp();

        // Clean slate per test.
        Cache::flush();
    }

    public function test_put_and_get(): void
    {
        Cache::put('hello', 'world', 60);
        $this->assertSame('world', Cache::get('hello'));
    }

    public function test_get_returns_null_for_missing(): void
    {
        $this->assertNull(Cache::get('does-not-exist'));
    }

    public function test_many_returns_one_round_trip(): void
    {
        Cache::put('k1', 'v1', 60);
        Cache::put('k2', 'v2', 60);
        Cache::put('k3', 'v3', 60);

        $got = Cache::many(['k1', 'k2', 'missing', 'k3']);
        $this->assertSame('v1', $got['k1']);
        $this->assertSame('v2', $got['k2']);
        $this->assertNull($got['missing']);
        $this->assertSame('v3', $got['k3']);
    }

    public function test_increment_decrement(): void
    {
        Cache::put('counter', 5, 60);
        $this->assertSame(6, Cache::increment('counter'));
        $this->assertSame(8, Cache::increment('counter', 2));
        $this->assertSame(7, Cache::decrement('counter'));
    }

    public function test_forever_and_forget(): void
    {
        Cache::forever('persistent', 'stay');
        $this->assertSame('stay', Cache::get('persistent'));
        Cache::forget('persistent');
        $this->assertNull(Cache::get('persistent'));
    }
}
