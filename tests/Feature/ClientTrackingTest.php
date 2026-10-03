<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Feature;

use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\TestCase;
use Resp3\Laravel\Cache\Resp3Store;
use Resp3\Laravel\Client\Resp3Client;
use Resp3\Laravel\Connections\Resp3Connection;
use Resp3\Laravel\Resp3ServiceProvider;
use Resp3\Laravel\Tests\Support\Env;
use Resp3\Laravel\Tracking\ArrayLocalStore;
use Resp3\Laravel\Tracking\ClientSideCache;
use Resp3\Laravel\Tracking\TrackingConfig;
use Resp3\RedisException;

/**
 * Client-side caching end to end: the `resp3` cache driver with a
 * `client_tracking` block against a live server. The default store tracks
 * on connection `default` with an array local store, `apcu` on connection
 * `apcu` with APCu, and `plain` leaves tracking off. A second raw client
 * plays the other application server that writes behind our back.
 *
 * Skips when ext-resp3 is missing, no server is reachable or the server has
 * no CLIENT TRACKING. Runs on Valkey (RESP3_TEST_PORT=6379) and Redis (6380).
 */
final class ClientTrackingTest extends TestCase
{
    /** @var array<string, list<string>> command names per connection name, uppercased */
    private array $commands = [];

    private ?Resp3Client $raw = null;

    protected function getPackageProviders($app): array
    {
        return [Resp3ServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $node = ['host' => Env::host(), 'port' => Env::port(), 'database' => 0];

        $app['config']->set('database.redis', [
            'client' => 'resp3',
            'default' => $node,
            'apcu' => $node,
            'plain' => $node,
        ]);
        $app['config']->set('cache.default', 'redis');
        $app['config']->set('cache.stores.redis', [
            'driver' => 'resp3',
            'prefix' => 'r3test:',
            'connection' => 'default',
            'lock_connection' => 'default',
            'client_tracking' => [
                'enabled' => true,
                'local_store' => 'array',
                'local_ttl' => 60,
            ],
        ]);
        $app['config']->set('cache.stores.apcu', [
            'driver' => 'resp3',
            'prefix' => 'r3test:',
            'connection' => 'apcu',
            'lock_connection' => 'apcu',
            'client_tracking' => [
                'enabled' => true,
                'local_store' => 'apcu',
                'local_ttl' => 60,
            ],
        ]);
        // Siblings of the default store on the same connection: `second`
        // has the same tracking arguments (OPTIN), `bcast` has other ones.
        $app['config']->set('cache.stores.second', [
            'driver' => 'resp3',
            'prefix' => 'r3second:',
            'connection' => 'default',
            'lock_connection' => 'default',
            'client_tracking' => [
                'enabled' => true,
                'local_store' => 'array',
                'local_ttl' => 60,
            ],
        ]);
        $app['config']->set('cache.stores.bcast', [
            'driver' => 'resp3',
            'prefix' => 'r3bcast:',
            'connection' => 'default',
            'lock_connection' => 'default',
            'client_tracking' => [
                'enabled' => true,
                'mode' => 'bcast',
                'local_store' => 'array',
            ],
        ]);
        $app['config']->set('cache.stores.plain', [
            'driver' => 'resp3',
            'prefix' => 'r3test:',
            'connection' => 'plain',
            'lock_connection' => 'plain',
            'client_tracking' => ['enabled' => false],
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

        $this->raw = new Resp3Client(host: Env::host(), port: Env::port(), timeout: 3.0);
        if ($this->raw->command('CLIENT', 'TRACKINGINFO') instanceof RedisException) {
            $this->raw->close();
            $this->markTestSkipped('Server on ' . Env::address() . ' has no CLIENT TRACKING');
        }

        parent::setUp();

        $this->beforeApplicationDestroyed(fn () => $this->raw?->close());

        Cache::flush();
        $this->listen('default');
        Event::listen(CommandExecuted::class, function (CommandExecuted $e): void {
            $this->commands[$e->connectionName][] = strtoupper($e->command);
        });
    }

    private function listen(string $connection): void
    {
        Redis::connection($connection)->setEventDispatcher($this->app['events']);
    }

    private function connection(string $name = 'default'): Resp3Connection
    {
        $connection = Redis::connection($name);
        $this->assertInstanceOf(Resp3Connection::class, $connection);

        return $connection;
    }

    private function cache(string $store = 'redis'): ClientSideCache
    {
        $store = Cache::store($store)->getStore();
        $this->assertInstanceOf(Resp3Store::class, $store);
        $cache = $store->clientSideCache();
        $this->assertInstanceOf(ClientSideCache::class, $cache);

        return $cache;
    }

    /** @return list<string> */
    private function seen(string $connection = 'default'): array
    {
        return $this->commands[$connection] ?? [];
    }

    private function forgetSeen(): void
    {
        $this->commands = [];
    }

    /** Give the server a moment to deliver the invalidation push to our socket. */
    private function settle(): void
    {
        usleep(100_000);
    }

    private function rawSet(string $key, string $value): void
    {
        $this->raw->command('SET', 'r3test:' . $key, serialize($value));
        $this->settle();
    }

    // ------------------------------------------------------------------ read-through

    public function test_the_first_get_goes_to_the_server(): void
    {
        Cache::put('a', 'one', 60);
        $this->forgetSeen();

        $this->assertSame('one', Cache::get('a'));

        $this->assertContains('GET', $this->seen());
    }

    public function test_the_second_get_sends_no_get_to_the_server(): void
    {
        Cache::put('a', 'one', 60);
        Cache::get('a');
        $this->forgetSeen();

        $this->assertSame('one', Cache::get('a'));

        $this->assertNotContains('GET', $this->seen());
        $this->assertSame([], $this->seen());
    }

    public function test_the_store_uses_an_array_local_store(): void
    {
        $this->assertInstanceOf(ArrayLocalStore::class, $this->cache()->localStore());
    }

    public function test_many_serves_cached_keys_locally_and_reads_the_rest(): void
    {
        Cache::put('m1', 'one', 60);
        Cache::put('m2', 'two', 60);
        Cache::get('m1');
        $this->forgetSeen();

        $this->assertSame(['m1' => 'one', 'm2' => 'two', 'm3' => null], Cache::many(['m1', 'm2', 'm3']));

        $this->assertSame(1, count(array_keys($this->seen(), 'MGET', true)));
        $this->assertNotContains('GET', $this->seen());
    }

    // ------------------------------------------------------------------ invalidation

    public function test_a_write_from_another_client_invalidates_the_cached_value(): void
    {
        Cache::put('a', 'one', 60);
        Cache::get('a');

        $this->rawSet('a', 'two');
        $this->forgetSeen();

        $this->assertSame('two', Cache::get('a'));
        $this->assertContains('GET', $this->seen());
    }

    public function test_a_delete_from_another_client_invalidates_the_cached_value(): void
    {
        Cache::put('a', 'one', 60);
        Cache::get('a');

        $this->raw->command('DEL', 'r3test:a');
        $this->settle();

        $this->assertNull(Cache::get('a'));
    }

    public function test_flushdb_from_another_client_clears_the_local_store(): void
    {
        Cache::put('a', 'one', 60);
        Cache::put('b', 'two', 60);
        Cache::get('a');
        Cache::get('b');

        $this->raw->command('FLUSHDB');
        $this->settle();
        $this->forgetSeen();

        $this->assertNull(Cache::get('a'));
        $this->assertNull(Cache::get('b'));
        $this->assertContains('GET', $this->seen());
    }

    public function test_a_put_through_the_store_replaces_the_cached_value(): void
    {
        Cache::put('a', 'one', 60);
        Cache::get('a');

        Cache::put('a', 'two', 60);

        $this->assertSame('two', Cache::get('a'));
    }

    public function test_forget_through_the_store_drops_the_cached_value(): void
    {
        Cache::put('a', 'one', 60);
        Cache::get('a');

        Cache::forget('a');

        $this->assertNull(Cache::get('a'));
    }

    public function test_flush_through_the_store_drops_the_cached_values(): void
    {
        Cache::put('a', 'one', 60);
        Cache::get('a');

        Cache::flush();

        $this->assertNull(Cache::get('a'));
    }

    public function test_increment_through_the_store_drops_the_cached_value(): void
    {
        Cache::put('n', 5, 60);
        $this->assertEquals(5, Cache::get('n'));

        Cache::increment('n');

        $this->assertEquals(6, Cache::get('n'));
    }

    public function test_a_key_with_a_one_second_ttl_is_a_miss_after_it_expires(): void
    {
        Cache::put('short', 'one', 1);
        $this->assertSame('one', Cache::get('short'));

        usleep(1_300_000);

        $this->assertNull(Cache::get('short'));
    }

    // ------------------------------------------------------------------ connection loss

    public function test_after_client_kill_the_next_get_reconnects_with_a_new_namespace(): void
    {
        Cache::put('a', 'one', 60);
        Cache::get('a');
        $oldId = $this->connection()->trackingId();
        $this->assertNotNull($oldId);
        $this->assertSame(getmypid() . ':' . $oldId, $this->cache()->localStore()->namespace());

        $this->raw->command('CLIENT', 'KILL', 'ID', (string) $oldId);
        usleep(100_000);

        $this->assertSame('one', Cache::get('a'));

        $newId = $this->connection()->trackingId();
        $this->assertNotNull($newId);
        $this->assertNotSame($oldId, $newId);
        $this->assertSame(getmypid() . ':' . $newId, $this->cache()->localStore()->namespace());
        $this->assertTrue($this->cache()->isActive());
    }

    public function test_caching_resumes_after_a_reconnect(): void
    {
        Cache::put('a', 'one', 60);
        Cache::get('a');
        $this->raw->command('CLIENT', 'KILL', 'ID', (string) $this->connection()->trackingId());
        usleep(100_000);
        Cache::get('a');
        Cache::get('a');
        $this->forgetSeen();

        $this->assertSame('one', Cache::get('a'));

        $this->assertSame([], $this->seen());
    }

    public function test_an_invalidation_still_works_after_a_reconnect(): void
    {
        Cache::put('a', 'one', 60);
        Cache::get('a');
        $this->raw->command('CLIENT', 'KILL', 'ID', (string) $this->connection()->trackingId());
        usleep(100_000);
        Cache::get('a');
        Cache::get('a');

        $this->rawSet('a', 'two');

        $this->assertSame('two', Cache::get('a'));
    }

    // ------------------------------------------------------------------ attaching to a reused persistent socket

    public function test_an_invalidation_dropped_before_the_cache_attached_does_not_serve_a_stale_entry(): void
    {
        $key = 'r3test:late';
        $this->raw->command('SET', $key, 'one');

        // Request one: track the key on a persistent socket, then end cleanly
        // so the socket stays open with tracking on.
        $first = $this->persistentClient();
        $first->command('CLIENT', 'TRACKING', 'ON', 'OPTIN');
        $first->pipeline([['CLIENT', 'CACHING', 'YES'], ['GET', $key]]);
        $id = $first->connectionId();
        unset($first);
        gc_collect_cycles();

        // Another server changes the key: the invalidation is queued on the
        // idle persistent socket.
        $this->raw->command('SET', $key, 'two');
        $this->settle();

        // Request two: a command runs before the cache exists (throttle
        // middleware, say) and drops the queued push.
        $second = $this->persistentClient();
        $second->command('PING');
        $this->assertSame($id, $second->connectionId(), 'precondition: the persistent socket was reused');

        try {
            // The local store still holds request one's copy (as APCu would).
            $local = new ArrayLocalStore();
            $local->useNamespace(getmypid() . ':' . $id);
            $local->set($key, 'one');

            $cache = new ClientSideCache(
                $second,
                TrackingConfig::fromArray(['enabled' => true, 'local_store' => 'array']),
                $local,
                'r3test:',
            );

            $this->assertSame('two', $cache->get(new Resp3Connection($second), $key));
        } finally {
            $second->close();
            $this->raw->command('DEL', $key);
        }
    }

    private function persistentClient(): Resp3Client
    {
        return new Resp3Client(
            host: Env::host(),
            port: Env::port(),
            timeout: 3.0,
            persistent: true,
            persistentId: 'client-tracking-late',
        );
    }

    // ------------------------------------------------------------------ stores sharing a connection

    public function test_a_second_store_on_the_connection_shares_the_namespace(): void
    {
        Cache::get('a');
        Cache::store('second')->put('s', 'one', 60);
        Cache::store('second')->get('s');
        $this->forgetSeen();

        $this->assertSame('one', Cache::store('second')->get('s'));

        $this->assertSame([], $this->seen());
        $this->assertNotSame($this->cache()->localStore(), $this->cache('second')->localStore());
        $this->assertSame($this->cache()->localStore()->namespace(), $this->cache('second')->localStore()->namespace());
    }

    public function test_a_push_drained_by_one_store_reaches_the_sibling_store(): void
    {
        Cache::store('second')->put('s', 'one', 60);
        Cache::store('second')->get('s');

        $this->raw->command('SET', 'r3second:s', serialize('two'));
        $this->settle();
        // The default store drains the invalidation for r3second:s.
        Cache::get('a');

        $this->assertNull($this->cache('second')->localStore()->get('r3second:s'));
        $this->assertSame('two', Cache::store('second')->get('s'));
    }

    public function test_a_store_with_other_tracking_arguments_runs_without_client_side_caching(): void
    {
        $store = Cache::store('bcast')->getStore();
        $this->assertInstanceOf(Resp3Store::class, $store);
        $this->assertNull($store->clientSideCache());

        Cache::store('bcast')->put('b', 'one', 60);
        $this->forgetSeen();

        $this->assertSame('one', Cache::store('bcast')->get('b'));
        $this->assertSame('one', Cache::store('bcast')->get('b'));

        $this->assertSame(2, count(array_keys($this->seen(), 'GET', true)));
        $this->assertNotContains('PTTL', $this->seen());
    }

    // ------------------------------------------------------------------ apcu

    public function test_apcu_store_serves_the_second_get_locally(): void
    {
        $this->requireApcu();
        $this->listen('apcu');
        Cache::store('apcu')->put('ap', 'one', 60);
        Cache::store('apcu')->get('ap');
        $this->forgetSeen();

        $this->assertSame('one', Cache::store('apcu')->get('ap'));

        $this->assertSame([], $this->seen('apcu'));
    }

    public function test_apcu_store_sees_a_write_from_another_client(): void
    {
        $this->requireApcu();
        $this->listen('apcu');
        Cache::store('apcu')->put('ap', 'one', 60);
        Cache::store('apcu')->get('ap');

        $this->rawSet('ap', 'two');
        $this->forgetSeen();

        $this->assertSame('two', Cache::store('apcu')->get('ap'));
        $this->assertContains('GET', $this->seen('apcu'));
    }

    private function requireApcu(): void
    {
        if (!extension_loaded('apcu') || !ini_get('apc.enable_cli')) {
            $this->markTestSkipped('apcu is not loaded or apc.enable_cli is off');
        }
    }

    // ------------------------------------------------------------------ disabled

    public function test_a_store_with_tracking_disabled_sends_no_client_command(): void
    {
        $this->listen('plain');

        Cache::store('plain')->put('p', 'one', 60);
        Cache::store('plain')->get('p');
        Cache::store('plain')->get('p');

        $this->assertNotContains('CLIENT', $this->seen('plain'));
        $this->assertNotContains('PTTL', $this->seen('plain'));
        $this->assertSame(2, count(array_keys($this->seen('plain'), 'GET', true)));
    }

    public function test_a_store_with_tracking_disabled_has_no_client_side_cache(): void
    {
        $store = Cache::store('plain')->getStore();
        $this->assertInstanceOf(Resp3Store::class, $store);

        $this->assertNull($store->clientTracking());
        $this->assertNull($store->clientSideCache());
    }
}
