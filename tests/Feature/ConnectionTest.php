<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\TestCase;
use Resp3\Laravel\Tests\Support\Env;
use Resp3\Laravel\Client\ServerException;
use Resp3\Laravel\Resp3ServiceProvider;
use Resp3\RedisException;

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
                'host' => Env::host(),
                'port' => Env::port(),
                'database' => 0,
            ],
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

    public function test_exec_containing_wrongtype_yields_nested_redis_exception_element(): void
    {
        $c = Redis::connection();
        $c->set('ex:string', 'plain');

        $c->multi();
        $c->command('LPUSH', ['ex:string', 'a']);
        $c->set('ex:after', 'ran');
        $replies = $c->exec();

        $this->assertIsArray($replies);
        $this->assertCount(2, $replies);
        $this->assertInstanceOf(RedisException::class, $replies[0]);
        $this->assertSame('WRONGTYPE', $replies[0]->prefix);
        $this->assertSame('OK', $replies[1]);
        $this->assertSame('ran', $c->get('ex:after'), 'the other queued command still ran');
    }

    public function test_get_on_a_hash_throws_server_exception_with_wrongtype_prefix(): void
    {
        $c = Redis::connection();
        $c->command('HSET', ['ex:hash', 'field', 'value']);

        try {
            $c->get('ex:hash');
            $this->fail('GET on a hash did not throw');
        } catch (ServerException $e) {
            $this->assertSame('WRONGTYPE', $e->prefix);
            $this->assertInstanceOf(RedisException::class, $e->getRedisException());
            $this->assertStringStartsWith('WRONGTYPE', $e->getMessage());
        }

        $this->assertSame('PONG', $c->command('PING'), 'the connection is still usable');
    }
}
