<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\TestCase;
use Resp3\Laravel\Resp3ServiceProvider;
use Resp3\Laravel\Tests\Support\FakeRespServer;

/**
 * A server that advertises DELEX / MSETEX in HELLO but rejects the command
 * (an ACL or a proxy hiding it): the store disables the feature on that
 * connection and falls back once. Driven through a scripted fake server.
 */
final class SelfHealingTest extends TestCase
{
    private ?FakeRespServer $server = null;

    /** @var list<list<string>> */
    private array $script = [];

    protected function getPackageProviders($app): array
    {
        return [Resp3ServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.redis', [
            'client' => 'resp3',
            'default' => ['host' => '127.0.0.1', 'port' => $this->server->port, 'database' => 0],
        ]);
        $app['config']->set('cache.default', 'redis');
        $app['config']->set('cache.stores.redis', [
            'driver' => 'resp3',
            'prefix' => 'r3test:',
            'connection' => 'default',
            'lock_connection' => 'default',
        ]);
    }

    /** @param list<string> $replies replies after HELLO */
    private function boot(array $replies): void
    {
        $this->server = new FakeRespServer([[FakeRespServer::HELLO_REDIS, ...$replies]]);
        parent::setUp();
    }

    protected function setUp(): void
    {
        if (!extension_loaded('resp3')) {
            $this->markTestSkipped('ext-resp3 is not loaded');
        }
        // The app boots inside each test, once the script is known.
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            parent::tearDown();
        }
        $this->server?->stop();
    }

    public function test_lock_release_falls_back_to_eval_after_unknown_delex(): void
    {
        $this->boot([
            "+OK\r\n",                                              // SET NX EX (acquire)
            "-ERR unknown command 'DELEX', with args beginning with:\r\n", // DELEX
            ":1\r\n",                                               // EVAL (fallback release)
        ]);
        $connection = Redis::connection();
        $lock = Cache::lock('heal', 10);
        $this->assertTrue($lock->get());
        $this->assertTrue($connection->capabilities()->delex(), 'HELLO advertised DELEX');

        $this->assertTrue($lock->release());

        $received = $this->server->received();
        $this->assertStringContainsString('DELEX', $received);
        $this->assertStringContainsString('EVAL', $received);
        $this->assertFalse($connection->capabilities()->delex());
        $this->assertTrue($connection->capabilities()->msetex(), 'other features are untouched');
    }

    public function test_put_many_falls_back_to_multi_after_unknown_msetex(): void
    {
        $this->boot([
            "-ERR unknown command 'MSETEX', with args beginning with:\r\n", // MSETEX
            "+OK\r\n",                                                        // MULTI
            "+QUEUED\r\n+QUEUED\r\n*2\r\n+OK\r\n+OK\r\n",                  // SETEX, SETEX, EXEC
        ]);
        $connection = Redis::connection();

        $this->assertTrue(Cache::putMany(['a' => 1, 'b' => 2], 60));

        $received = $this->server->received();
        $this->assertStringContainsString('MSETEX', $received);
        $this->assertStringContainsString('MULTI', $received);
        $this->assertStringContainsString('SETEX', $received);
        $this->assertFalse($connection->capabilities()->msetex());
    }
}
