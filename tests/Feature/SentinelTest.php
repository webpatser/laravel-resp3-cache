<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Group;
use Resp3\Laravel\Resp3ServiceProvider;

/**
 * End-to-end Sentinel test against the local docker compose topology
 * (1 master on 6500, 2 replicas on 6501-6502, 3 sentinels on
 * 26500-26502, service name 'mymaster'). Skips if no sentinel reachable.
 */
final class SentinelTest extends TestCase
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
                'replication' => 'sentinel',
                'service'     => 'mymaster',
                'timeout'     => 2.0,
                'sentinel_timeout' => 1.0,
            ],
            'default' => [
                ['host' => '127.0.0.1', 'port' => 26500],
                ['host' => '127.0.0.1', 'port' => 26501],
                ['host' => '127.0.0.1', 'port' => 26502],
            ],
        ]);
        $app['config']->set('cache.default', 'redis');
        $app['config']->set('cache.stores.redis', [
            'driver' => 'resp3',
            'prefix' => 'r3sent:',
            'connection' => 'default',
        ]);
    }

    protected function setUp(): void
    {
        if (!extension_loaded('resp3')) {
            $this->markTestSkipped('ext-resp3 is not loaded');
        }
        if (!@fsockopen('127.0.0.1', 26500, $_, $_, 0.5)) {
            $this->markTestSkipped('No sentinel reachable on 127.0.0.1:26500 (run make sentinel-up)');
        }
        parent::setUp();
        Cache::flush();
    }

    public function test_put_and_get_via_discovered_master(): void
    {
        Cache::put('hello', 'world', 60);
        $this->assertSame('world', Cache::get('hello'));
    }

    public function test_increment_via_master(): void
    {
        Cache::put('counter', 5, 60);
        $this->assertSame(6, Cache::increment('counter'));
        $this->assertSame(8, Cache::increment('counter', 2));
    }

    public function test_many_round_trip(): void
    {
        Cache::put('a', '1', 60);
        Cache::put('b', '2', 60);
        Cache::put('c', '3', 60);

        $this->assertSame(['a' => '1', 'b' => '2', 'c' => '3'], Cache::many(['a', 'b', 'c']));
    }

    #[Group('failover')]
    public function test_failover_picks_up_new_master(): void
    {
        Cache::put('before', 'one', 60);
        $this->assertSame('one', Cache::get('before'));

        // Kill the current master to force a failover.
        exec('docker compose -f tests/cluster/sentinel-compose.yml kill valkey-master 2>/dev/null', $_, $exit);
        $this->assertSame(0, $exit, 'Could not kill master container');

        // Sentinels need ~5s (down-after-milliseconds) + election to promote.
        sleep(8);

        Cache::put('after', 'two', 60);
        $this->assertSame('two', Cache::get('after'));

        // Restore for any follow-up tests.
        exec('docker compose -f tests/cluster/sentinel-compose.yml start valkey-master 2>/dev/null');
    }
}
