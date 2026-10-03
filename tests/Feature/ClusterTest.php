<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\TestCase;
use Resp3\Laravel\Resp3ServiceProvider;
use RuntimeException;

/**
 * End-to-end cluster tests: Cache facade -> RedisStore -> ClusterConnection
 * -> Resp3ClusterRouter -> Resp3Client per node -> ext-resp3 -> 6-node Valkey
 * cluster on 127.0.0.1:7100-7105 (boot it with tests/cluster/setup.sh).
 *
 * Skips if the cluster is not running.
 */
final class ClusterTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [Resp3ServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.redis', [
            'client' => 'resp3',
            'options' => [
                'cluster' => 'redis',
            ],
            'clusters' => [
                'default' => [
                    ['host' => '127.0.0.1', 'port' => 7100],
                    ['host' => '127.0.0.1', 'port' => 7101],
                    ['host' => '127.0.0.1', 'port' => 7102],
                ],
                'options' => [
                    'timeout' => 2,
                    'read_timeout' => 2,
                ],
            ],
        ]);
        $app['config']->set('cache.default', 'redis');
        $app['config']->set('cache.stores.redis', [
            'driver' => 'resp3',
            'prefix' => 'r3clust:',
            'connection' => 'default',
        ]);
    }

    protected function setUp(): void
    {
        if (!extension_loaded('resp3')) {
            $this->markTestSkipped('ext-resp3 is not loaded');
        }
        if (!@fsockopen('127.0.0.1', 7100, $_, $_, 0.5)) {
            $this->markTestSkipped('No cluster reachable on 127.0.0.1:7100 (run make cluster-up)');
        }
        parent::setUp();
        Cache::flush();
    }

    public function test_put_and_get_across_slots(): void
    {
        // Different keys land in different slots; the router handles routing.
        Cache::put('alpha', 'one', 60);
        Cache::put('bravo', 'two', 60);
        Cache::put('charlie', 'three', 60);

        $this->assertSame('one',   Cache::get('alpha'));
        $this->assertSame('two',   Cache::get('bravo'));
        $this->assertSame('three', Cache::get('charlie'));
    }

    public function test_many_with_same_slot_via_hash_tag(): void
    {
        // Hash tag {user1} forces all three keys into the same slot.
        Cache::put('{user1}.name', 'Alice', 60);
        Cache::put('{user1}.email', 'alice@example.com', 60);
        Cache::put('{user1}.role', 'admin', 60);

        $got = Cache::many(['{user1}.name', '{user1}.email', '{user1}.role']);
        $this->assertSame('Alice', $got['{user1}.name']);
        $this->assertSame('alice@example.com', $got['{user1}.email']);
        $this->assertSame('admin', $got['{user1}.role']);
    }

    public function test_many_cross_slot_groups_keys_by_slot(): void
    {
        // Resp3Store::many() sends one MGET per slot instead of a CROSSSLOT error.
        Cache::put('alpha', '1', 60);
        Cache::put('bravo', '2', 60);

        $this->assertSame(
            ['alpha' => '1', 'bravo' => '2', 'missing' => null],
            Cache::many(['alpha', 'bravo', 'missing']),
        );
    }

    public function test_raw_mget_cross_slot_throws_crossslot(): void
    {
        // "foo" (slot 12182) and "bar" (slot 5061) live on different masters.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/CROSSSLOT/i');
        Redis::connection()->mget(['foo', 'bar']);
    }

    public function test_flush_broadcasts_to_all_masters(): void
    {
        Cache::put('alpha', '1', 60);
        Cache::put('bravo', '2', 60);
        Cache::put('charlie', '3', 60);
        Cache::put('delta', '4', 60);

        Cache::flush();

        $this->assertNull(Cache::get('alpha'));
        $this->assertNull(Cache::get('bravo'));
        $this->assertNull(Cache::get('charlie'));
        $this->assertNull(Cache::get('delta'));
    }

    public function test_multi_exec_same_slot(): void
    {
        $c = Redis::connection();
        $c->multi();
        $c->set('{txn}.a', '1');
        $c->set('{txn}.b', '2');
        $c->incr('{txn}.counter');
        $replies = $c->exec();

        $this->assertIsArray($replies);
        $this->assertCount(3, $replies);
        $this->assertSame('OK', $replies[0]);
        $this->assertSame('OK', $replies[1]);
        $this->assertSame(1,    $replies[2]);
    }

    public function test_multi_exec_cross_slot_throws(): void
    {
        $c = Redis::connection();
        $c->multi();
        $c->set('xx.a', '1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/CROSSSLOT/i');
        $c->set('yy.b', '2');                 // different slot triggers the guard
    }
}
