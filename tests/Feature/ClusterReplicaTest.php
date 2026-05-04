<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase;
use Resp3\Laravel\Resp3ServiceProvider;

/**
 * Verifies the cluster_read_replicas flag actually routes read commands to
 * a replica. We can't easily prove "this exact GET went to node X", but we
 * can verify the option is wired up end to end without breaking semantics.
 */
final class ClusterReplicaTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [Resp3ServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.redis', [
            'client' => 'resp3',
            'options' => ['cluster' => 'redis'],
            'clusters' => [
                'default' => [
                    ['host' => '127.0.0.1', 'port' => 7100],
                    ['host' => '127.0.0.1', 'port' => 7101],
                    ['host' => '127.0.0.1', 'port' => 7102],
                ],
                'options' => [
                    'timeout' => 2,
                    'cluster_read_replicas' => true,
                ],
            ],
        ]);
        $app['config']->set('cache.default', 'redis');
        $app['config']->set('cache.stores.redis', [
            'driver' => 'redis',
            'connection' => 'default',
        ]);
    }

    protected function setUp(): void
    {
        if (!extension_loaded('resp3')) {
            $this->markTestSkipped('ext-resp3 is not loaded');
        }
        if (!@fsockopen('127.0.0.1', 7100, $_, $_, 0.5)) {
            $this->markTestSkipped('No cluster reachable on 127.0.0.1:7100');
        }
        parent::setUp();
        Cache::flush();
    }

    public function test_writes_go_to_master_and_reads_succeed(): void
    {
        // Write a value (master), then read (replica when flag is on).
        Cache::put('replica-test', 'hello', 60);

        // Cluster replication is asynchronous; small wait to let it propagate.
        usleep(100_000);

        $this->assertSame('hello', Cache::get('replica-test'));
    }
}
