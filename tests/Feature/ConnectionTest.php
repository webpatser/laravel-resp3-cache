<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\TestCase;
use Resp3\Laravel\Resp3ServiceProvider;

/**
 * Direct Redis::connection() calls through the resp3 client.
 */
final class ConnectionTest extends TestCase
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
                'host' => '127.0.0.1',
                'port' => 6379,
                'database' => 0,
            ],
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
        Redis::connection()->flushdb();
    }

    public function test_get_set(): void
    {
        $c = Redis::connection();
        $c->set('foo', 'bar');
        $this->assertSame('bar', $c->get('foo'));
    }

    public function test_mget_with_missing(): void
    {
        $c = Redis::connection();
        $c->set('a', '1');
        $c->set('c', '3');
        $this->assertSame(['1', null, '3'], $c->mget(['a', 'b', 'c']));
    }

    public function test_eval_returns_value(): void
    {
        $c = Redis::connection();
        $reply = $c->eval("return 'pong'", 0);
        $this->assertSame('pong', $reply);
    }

    public function test_multi_exec_pipelines_commands(): void
    {
        $c = Redis::connection();
        $c->multi();
        $c->set('mx:a', '1');
        $c->set('mx:b', '2');
        $c->incr('mx:counter');
        $replies = $c->exec();

        $this->assertIsArray($replies);
        $this->assertCount(3, $replies);
        $this->assertSame('OK', $replies[0]);
        $this->assertSame('OK', $replies[1]);
        $this->assertSame(1,    $replies[2]);
    }
}
