<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Feature;

use BadMethodCallException;
use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\TestCase;
use Resp3\Laravel\Resp3ServiceProvider;

/**
 * Confirms a cluster connection refuses pub/sub with a clear hint at the
 * single-node workaround.
 */
final class ClusterPubSubTest extends TestCase
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
                'options' => ['timeout' => 2],
            ],
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
    }

    public function test_subscribe_on_cluster_throws_with_hint(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessageMatches('/single-node/i');

        Redis::connection()->subscribe(['anything'], fn () => null);
    }

    public function test_psubscribe_on_cluster_throws_with_hint(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessageMatches('/single-node/i');

        Redis::connection()->psubscribe(['p.*'], fn () => null);
    }
}
