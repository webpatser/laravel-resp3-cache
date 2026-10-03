<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Feature;

use BadMethodCallException;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\TestCase;
use Resp3\Laravel\Cache\Resp3Store;
use Resp3\Laravel\Client\ServerCapabilities;
use Resp3\Laravel\Resp3ServiceProvider;
use Resp3\Laravel\Tests\Support\Env;

/**
 * Newer-server code paths of Resp3Store and Resp3Lock (MSETEX, DELEX,
 * SET IFEQ) and the fallbacks used when a server lacks them. Each test
 * skips itself when the target server does not advertise the feature, so
 * the suite runs on Valkey (RESP3_TEST_PORT=6379) and Redis (6380) alike.
 */
final class Resp3StoreFeaturesTest extends TestCase
{
    /** @var list<string> command names seen by the connection, uppercased */
    private array $commands = [];

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

        Cache::flush();
        $this->connection()->setEventDispatcher($this->app['events']);
        Event::listen(CommandExecuted::class, function (CommandExecuted $e): void {
            $this->commands[] = strtoupper($e->command);
        });
    }

    private function connection(): Connection
    {
        return Redis::connection();
    }

    private function caps(): ServerCapabilities
    {
        return $this->connection()->capabilities();
    }

    private function requireFeature(string $feature): void
    {
        if (!$this->caps()->has($feature)) {
            $this->markTestSkipped("Server {$this->caps()->server()} {$this->caps()->version()} lacks {$feature}");
        }
    }

    private function requireMissing(string $feature): void
    {
        if ($this->caps()->has($feature)) {
            $this->markTestSkipped("Server supports {$feature}; the fallback test needs one that does not");
        }
    }

    public function test_capabilities_describe_the_connected_server(): void
    {
        $caps = $this->caps();

        $this->assertContains($caps->server(), ['redis', 'valkey']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $caps->version());
        $this->assertSame('standalone', $caps->mode());
    }

    // ------------------------------------------------------------------ MSETEX

    public function test_put_many_uses_one_msetex(): void
    {
        $this->requireFeature(ServerCapabilities::MSETEX);

        $this->assertTrue(Cache::putMany(['mx:a' => 'one', 'mx:b' => 'two', 'mx:c' => 'three'], 60));

        $this->assertSame(['MSETEX'], array_values(array_filter($this->commands, fn ($c) => in_array($c, ['MSETEX', 'SETEX', 'MULTI', 'EXEC'], true))));
        $this->assertSame('two', Cache::get('mx:b'));
        $ttl = $this->connection()->command('TTL', ['r3test:mx:a']);
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(60, $ttl);
    }

    public function test_put_many_falls_back_to_multi_when_msetex_is_off(): void
    {
        $this->caps()->disable(ServerCapabilities::MSETEX);

        $this->assertTrue(Cache::putMany(['fb:a' => 'one', 'fb:b' => 'two'], 60));

        $this->assertNotContains('MSETEX', $this->commands);
        $this->assertContains('MULTI', $this->commands);
        $this->assertSame(['fb:a' => 'one', 'fb:b' => 'two'], Cache::many(['fb:a', 'fb:b']));
    }

    // ------------------------------------------------------------------ DELEX lock

    public function test_lock_release_uses_delex(): void
    {
        $this->requireFeature(ServerCapabilities::DELEX);
        $lock = Cache::lock('fx:delex', 10);
        $this->assertTrue($lock->get());

        $this->assertFalse(Cache::restoreLock('fx:delex', 'other-owner')->release());
        $this->assertSame(1, $this->connection()->command('EXISTS', ['r3test:fx:delex']), 'a foreign owner cannot delete it');

        $this->assertTrue($lock->release());
        $this->assertSame(0, $this->connection()->command('EXISTS', ['r3test:fx:delex']));
        $this->assertContains('DELEX', $this->commands);
        $this->assertNotContains('EVAL', $this->commands);
    }

    public function test_lock_release_falls_back_to_eval_when_delex_is_off(): void
    {
        $this->caps()->disable(ServerCapabilities::DELEX);
        $lock = Cache::lock('fx:eval', 10);
        $this->assertTrue($lock->get());

        $this->assertFalse(Cache::restoreLock('fx:eval', 'other-owner')->release());
        $this->assertTrue($lock->release());

        $this->assertNotContains('DELEX', $this->commands);
        $this->assertContains('EVAL', $this->commands);
        $this->assertSame(0, $this->connection()->command('EXISTS', ['r3test:fx:eval']));
    }

    public function test_lock_refresh_uses_set_ifeq(): void
    {
        $this->requireFeature(ServerCapabilities::SET_IF_EQ);
        $lock = Cache::lock('fx:refresh', 5);
        $this->assertTrue($lock->get());

        $this->assertTrue($lock->refresh(120));

        $this->assertGreaterThan(5, $this->connection()->command('TTL', ['r3test:fx:refresh']));
        $this->assertNotContains('EVAL', $this->commands);
    }

    public function test_lock_refresh_falls_back_to_eval_when_set_ifeq_is_off(): void
    {
        $this->caps()->disable(ServerCapabilities::SET_IF_EQ);
        $lock = Cache::lock('fx:refresh-eval', 5);
        $this->assertTrue($lock->get());

        $this->assertTrue($lock->refresh(120));
        $this->assertFalse(Cache::restoreLock('fx:refresh-eval', 'other-owner')->refresh(300));

        $this->assertGreaterThan(5, $this->connection()->command('TTL', ['r3test:fx:refresh-eval']));
        $this->assertLessThanOrEqual(120, $this->connection()->command('TTL', ['r3test:fx:refresh-eval']));
        $this->assertContains('EVAL', $this->commands);
    }

    // ------------------------------------------------------------------ putIfEquals

    private function store(): Resp3Store
    {
        $store = Cache::getStore();
        $this->assertInstanceOf(Resp3Store::class, $store);

        return $store;
    }

    public function test_put_if_equals_swaps_only_on_a_match(): void
    {
        $this->requireFeature(ServerCapabilities::SET_IF_EQ);
        Cache::put('cas:k', 'v1', 60);

        $this->assertFalse($this->store()->putIfEquals('cas:k', 'wrong', 'v2'));
        $this->assertSame('v1', Cache::get('cas:k'));

        $this->assertTrue($this->store()->putIfEquals('cas:k', 'v1', 'v2'));
        $this->assertSame('v2', Cache::get('cas:k'));
    }

    public function test_put_if_equals_never_matches_a_missing_key(): void
    {
        $this->requireFeature(ServerCapabilities::SET_IF_EQ);

        $this->assertFalse($this->store()->putIfEquals('cas:missing', 'x', 'y'));
        $this->assertNull(Cache::get('cas:missing'));
    }

    public function test_put_if_equals_handles_arrays_and_ttl(): void
    {
        $this->requireFeature(ServerCapabilities::SET_IF_EQ);
        Cache::put('cas:arr', ['n' => 1], 60);

        $this->assertTrue($this->store()->putIfEquals('cas:arr', ['n' => 1], ['n' => 2], 30));

        $this->assertSame(['n' => 2], Cache::get('cas:arr'));
        $ttl = $this->connection()->command('TTL', ['r3test:cas:arr']);
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(30, $ttl);
    }

    public function test_put_if_equals_throws_on_a_server_without_set_ifeq(): void
    {
        $this->requireMissing(ServerCapabilities::SET_IF_EQ);

        $this->expectException(BadMethodCallException::class);
        $this->store()->putIfEquals('cas:k', 'a', 'b');
    }

    public function test_put_if_equals_throws_when_the_feature_is_switched_off(): void
    {
        $this->caps()->disable(ServerCapabilities::SET_IF_EQ);
        Cache::put('cas:off', 'a', 60);

        try {
            $this->store()->putIfEquals('cas:off', 'a', 'b');
            $this->fail('putIfEquals did not throw');
        } catch (BadMethodCallException) {
            $this->assertSame('a', Cache::get('cas:off'), 'no silent unconditional write');
        }
    }
}
