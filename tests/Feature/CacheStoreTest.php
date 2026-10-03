<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Resp3\Laravel\Cache\Resp3Lock;
use Resp3\Laravel\Cache\Resp3Store;
use Orchestra\Testbench\TestCase;
use Resp3\Laravel\Tests\Support\Env;
use Resp3\Laravel\Resp3ServiceProvider;

/**
 * End-to-end test: Laravel Cache facade -> RedisStore -> Resp3Connection ->
 * Resp3Client -> ext-resp3 parser -> live server (RESP3_TEST_HOST:RESP3_TEST_PORT).
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
            'default' => [
                'host' => Env::host(),
                'port' => Env::port(),
                'database' => 0,
            ],
        ]);
        $app['config']->set('cache.default', 'redis');
        $app['config']->set('cache.stores.redis', [
            'driver' => 'resp3',
            'prefix' => 'r3test:',
            'connection' => 'default',
            'lock_connection' => 'default',
        ]);
    }

    protected function setUp(): void
    {
        if (!extension_loaded('resp3')) {
            $this->markTestSkipped('ext-resp3 is not loaded');
        }
        if (!Env::reachable()) {
            $this->markTestSkipped('No Redis or Valkey reachable on ' . Env::address());
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

    public function test_driver_resolves_resp3_store(): void
    {
        $store = Cache::getStore();

        $this->assertInstanceOf(Resp3Store::class, $store);
        $this->assertInstanceOf(Resp3Lock::class, $store->lock('probe', 5));
    }

    public function test_put_many_returns_true_and_round_trips(): void
    {
        $this->assertTrue(Cache::putMany(['pm:a' => 'one', 'pm:b' => ['x' => 2], 'pm:c' => 3], 60));

        $this->assertSame('one', Cache::get('pm:a'));
        $this->assertSame(['x' => 2], Cache::get('pm:b'));
        $this->assertEquals(3, Cache::get('pm:c'));
        $this->assertSame(['pm:a' => 'one', 'pm:c' => '3'], Cache::many(['pm:a', 'pm:c']));
    }

    public function test_put_many_sets_a_ttl(): void
    {
        Cache::putMany(['ttl:a' => 'x', 'ttl:b' => 'y'], 60);

        $ttl = \Illuminate\Support\Facades\Redis::connection()->command('TTL', ['r3test:ttl:a']);
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(60, $ttl);
    }

    public function test_add_only_stores_when_missing(): void
    {
        $this->assertTrue(Cache::add('add:k', 'first', 60));
        $this->assertFalse(Cache::add('add:k', 'second', 60));
        $this->assertSame('first', Cache::get('add:k'));
    }

    public function test_add_returns_false_while_the_key_exists(): void
    {
        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = Cache::add('add:repeat', "v{$i}", 60);
        }

        $this->assertSame([true, false, false, false, false], $results);
        $this->assertSame('v0', Cache::get('add:repeat'));
    }

    public function test_lock_acquire_and_release(): void
    {
        $lock = Cache::lock('lk:a', 10);

        $this->assertTrue($lock->get());
        $this->assertFalse(Cache::lock('lk:a', 10)->get(), 'held lock cannot be taken again');

        $this->assertTrue($lock->release());
        $this->assertTrue(Cache::lock('lk:a', 10)->get(), 'released lock can be taken');
    }

    public function test_lock_release_by_another_owner_does_nothing(): void
    {
        $lock = Cache::lock('lk:b', 10);
        $this->assertTrue($lock->get());

        $this->assertFalse(Cache::restoreLock('lk:b', 'someone-else')->release());
        $this->assertFalse(Cache::lock('lk:b', 10)->get(), 'still held by the original owner');
        $this->assertTrue(Cache::restoreLock('lk:b', $lock->owner())->release());
    }

    public function test_lock_refresh_extends_the_ttl_for_the_owner_only(): void
    {
        $lock = Cache::lock('lk:c', 5);
        $this->assertTrue($lock->get());
        $key = 'r3test:lk:c';
        $redis = \Illuminate\Support\Facades\Redis::connection();

        $this->assertTrue($lock->refresh(100));
        $this->assertGreaterThan(5, $redis->command('TTL', [$key]));

        $this->assertFalse(Cache::restoreLock('lk:c', 'someone-else')->refresh(200));
        $this->assertLessThanOrEqual(100, $redis->command('TTL', [$key]));

        $lock->release();
        $this->assertFalse($lock->refresh(100), 'a released lock cannot be refreshed');
    }

    public function test_lock_block_callback_runs_and_releases(): void
    {
        $result = Cache::lock('lk:d', 10)->get(fn () => 'ran');

        $this->assertSame('ran', $result);
        $this->assertTrue(Cache::lock('lk:d', 10)->get());
    }
}
