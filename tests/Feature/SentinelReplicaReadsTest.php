<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\TestCase;
use Resp3\Laravel\Resp3ServiceProvider;

/**
 * End-to-end Sentinel replica-reads test against the local docker compose
 * topology (1 master 6500, 2 replicas 6501-6502, 3 sentinels 26500-26502,
 * service name 'mymaster'). Skips if no sentinel reachable.
 *
 * The "did the read actually land on a replica?" assertion uses MONITOR on
 * each replica container; the test issues a batch of GETs and asserts at
 * least one was observed by a replica. Random selection means a single GET
 * is a coin flip; ten gets makes the false-negative rate negligible.
 */
final class SentinelReplicaReadsTest extends TestCase
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
                'sentinel_read_replicas' => true,
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
            'prefix' => 'r3rep:',
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

    public function test_writes_go_to_master_and_reads_eventually_see_them(): void
    {
        Cache::put('hello', 'world', 60);
        // Replication lag on a local docker compose is sub-millisecond but
        // not zero; give it a moment so the replica catches up.
        usleep(50_000);
        $this->assertSame('world', Cache::get('hello'));
    }

    public function test_many_round_trip_via_replica(): void
    {
        Cache::put('a', '1', 60);
        Cache::put('b', '2', 60);
        Cache::put('c', '3', 60);
        usleep(50_000);

        $this->assertSame(['a' => '1', 'b' => '2', 'c' => '3'], Cache::many(['a', 'b', 'c']));
    }

    public function test_replica_actually_serves_reads(): void
    {
        // Open a MONITOR pipe on each replica BEFORE issuing any GETs.
        $monitors = [];
        foreach ([6501, 6502] as $port) {
            $cmd = sprintf(
                "valkey-cli -p %d --timeout 2 monitor 2>/dev/null",
                $port,
            );
            $proc = popen($cmd, 'r');
            if ($proc === false) {
                $this->markTestSkipped("Could not popen monitor on $port");
            }
            stream_set_blocking($proc, false);
            $monitors[$port] = $proc;
        }

        try {
            Cache::put('rep-read', 'present', 60);
            usleep(100_000);
            for ($i = 0; $i < 20; $i++) {
                Cache::get('rep-read');
            }
            // Drain the monitor streams briefly.
            usleep(200_000);

            $sawReplicaRead = false;
            foreach ($monitors as $port => $proc) {
                $buf = '';
                while (($chunk = fread($proc, 4096)) !== false && $chunk !== '') {
                    $buf .= $chunk;
                }
                if (str_contains($buf, 'GET') && str_contains($buf, 'rep-read')) {
                    $sawReplicaRead = true;
                    break;
                }
            }

            $this->assertTrue(
                $sawReplicaRead,
                'Expected at least one GET to land on a replica (6501 or 6502) under random selection across 20 reads'
            );
        } finally {
            foreach ($monitors as $proc) {
                @pclose($proc);
            }
        }
    }

    public function test_replica_outage_falls_back_to_master(): void
    {
        Cache::put('fb', 'master-served', 60);
        usleep(50_000);

        // Kill both replicas. Reads should silently fall back to master.
        exec('docker compose -f tests/cluster/sentinel-compose.yml kill valkey-replica-1 valkey-replica-2 2>/dev/null');

        try {
            $this->assertSame('master-served', Cache::get('fb'));
        } finally {
            exec('docker compose -f tests/cluster/sentinel-compose.yml start valkey-replica-1 valkey-replica-2 2>/dev/null');
        }
    }
}
