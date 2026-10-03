<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Unit;

use Closure;
use LogicException;
use PHPUnit\Framework\TestCase;
use Resp3\Laravel\Client\ConnectionException;
use Resp3\Laravel\Client\Resp3ClientInterface;
use Resp3\Laravel\Connections\Resp3Connection;
use Resp3\Laravel\Tests\Support\ClientStubDefaults;
use Resp3\Laravel\Tracking\ArrayLocalStore;
use Resp3\Laravel\Tracking\ClientSideCache;
use Resp3\Laravel\Tracking\TrackableClient;
use Resp3\Laravel\Tracking\TrackingConfig;
use Resp3\PushMessage;

/**
 * ClientSideCache against a scripted client: the stub answers tracked reads
 * from an array, and delivers invalidation pushes either together with a
 * reply (through the push listener, as Resp3Client does for pushes that
 * arrive while a command waits), queued for the drainPushes() that follows
 * the reply, or queued before the next lookup.
 *
 * Needs ext-resp3 only for Resp3\PushMessage.
 */
final class ClientSideCacheTest extends TestCase
{
    private TrackingStubClient $client;

    private Resp3Connection $connection;

    protected function setUp(): void
    {
        if (!extension_loaded('resp3')) {
            $this->markTestSkipped('ext-resp3 is not loaded');
        }
    }

    /** @param array<string, mixed> $config */
    private function cache(array $config = []): ClientSideCache
    {
        $this->client = new TrackingStubClient();
        $this->client->values = ['p:a' => 'x', 'p:b' => 'y'];
        $this->connection = new Resp3Connection($this->client);

        return new ClientSideCache(
            $this->client,
            TrackingConfig::fromArray($config + ['enabled' => true, 'local_store' => 'array']),
            new ArrayLocalStore(),
            'p:',
        );
    }

    /** @param list<string>|null $keys */
    private static function invalidate(?array $keys): PushMessage
    {
        return new PushMessage(['invalidate', $keys]);
    }

    public function test_the_handshake_enables_tracking_with_optin(): void
    {
        $cache = $this->cache();

        $this->assertTrue($cache->isActive());
        $this->assertContains(['CLIENT', 'TRACKING', 'ON', 'OPTIN'], $this->client->commands);
        $this->assertSame(getmypid().':7', $cache->localStore()->namespace());
    }

    public function test_a_miss_reads_through_and_stores_the_value(): void
    {
        $cache = $this->cache();

        $this->assertSame('x', $cache->get($this->connection, 'p:a'));
        $this->assertSame('x', $cache->localStore()->get('p:a'));
    }

    public function test_a_second_get_is_served_locally(): void
    {
        $cache = $this->cache();

        $cache->get($this->connection, 'p:a');
        $this->assertSame('x', $cache->get($this->connection, 'p:a'));

        $this->assertCount(1, $this->client->pipelines);
    }

    public function test_a_missing_key_is_null_and_not_stored(): void
    {
        $cache = $this->cache();

        $this->assertNull($cache->get($this->connection, 'p:missing'));
        $this->assertNull($cache->localStore()->get('p:missing'));
    }

    public function test_a_reply_with_an_invalidation_for_its_key_is_not_stored(): void
    {
        $cache = $this->cache();
        $this->client->pushesWithReply = [self::invalidate(['p:a'])];

        $this->assertSame('x', $cache->get($this->connection, 'p:a'), 'the caller still gets the value that was read');
        $this->assertNull($cache->localStore()->get('p:a'));
    }

    public function test_an_invalidation_for_another_key_leaves_the_value_stored(): void
    {
        $cache = $this->cache();
        $this->client->pushesWithReply = [self::invalidate(['p:other'])];

        $cache->get($this->connection, 'p:a');

        $this->assertSame('x', $cache->localStore()->get('p:a'));
    }

    public function test_an_invalidation_drained_right_after_the_reply_is_not_stored(): void
    {
        $cache = $this->cache();
        $this->client->pushesAfterReply = [self::invalidate(['p:a'])];

        $this->assertSame('x', $cache->get($this->connection, 'p:a'));
        $this->assertNull($cache->localStore()->get('p:a'));
    }

    public function test_a_flush_arriving_with_a_reply_is_not_stored(): void
    {
        $cache = $this->cache();
        $this->client->pushesWithReply = [self::invalidate(null)];

        $this->assertSame('x', $cache->get($this->connection, 'p:a'));
        $this->assertNull($cache->localStore()->get('p:a'));
    }

    public function test_an_mget_reply_with_an_invalidation_stores_only_the_untouched_keys(): void
    {
        $cache = $this->cache();
        $this->client->pushesWithReply = [self::invalidate(['p:a'])];

        $this->assertSame(['x', 'y'], $cache->many($this->connection, ['p:a', 'p:b']));

        $this->assertNull($cache->localStore()->get('p:a'));
        $this->assertSame('y', $cache->localStore()->get('p:b'));
    }

    public function test_many_fetches_only_the_local_misses_with_one_mget(): void
    {
        $cache = $this->cache();
        $cache->get($this->connection, 'p:a');

        $this->assertSame(['x', 'y'], $cache->many($this->connection, ['p:a', 'p:b']));

        $this->assertCount(2, $this->client->pipelines);
        $this->assertContains(['MGET', 'p:b'], end($this->client->pipelines));
        $this->assertSame('y', $cache->localStore()->get('p:b'));
    }

    public function test_a_queued_invalidation_is_applied_before_a_local_hit(): void
    {
        $cache = $this->cache();
        $cache->get($this->connection, 'p:a');

        $this->client->values['p:a'] = 'changed';
        $this->client->queuedPushes = [self::invalidate(['p:a'])];

        $this->assertSame('changed', $cache->get($this->connection, 'p:a'));
    }

    public function test_a_null_payload_invalidation_clears_the_namespace(): void
    {
        $cache = $this->cache();
        $cache->get($this->connection, 'p:a');
        $cache->get($this->connection, 'p:b');

        $cache->apply(self::invalidate(null));

        $this->assertNull($cache->localStore()->get('p:a'));
        $this->assertNull($cache->localStore()->get('p:b'));
        $this->assertTrue($cache->isActive(), 'a flush does not turn tracking off');
    }

    public function test_an_invalidation_with_keys_deletes_only_those_keys(): void
    {
        $cache = $this->cache();
        $cache->get($this->connection, 'p:a');
        $cache->get($this->connection, 'p:b');

        $cache->apply(self::invalidate(['p:a']));

        $this->assertNull($cache->localStore()->get('p:a'));
        $this->assertSame('y', $cache->localStore()->get('p:b'));
    }

    public function test_tracking_redir_broken_disables_tracking_and_clears(): void
    {
        $cache = $this->cache();
        $cache->get($this->connection, 'p:a');

        $cache->apply(new PushMessage(['tracking-redir-broken']));

        $this->assertFalse($cache->isActive());
        $this->assertFalse($cache->isEnabled());
        $this->assertNull($cache->localStore()->get('p:a'));
    }

    public function test_forget_deletes_the_local_entry(): void
    {
        $cache = $this->cache();
        $cache->get($this->connection, 'p:a');
        $cache->get($this->connection, 'p:b');

        $cache->forget('p:a');

        $this->assertNull($cache->localStore()->get('p:a'));
        $this->assertSame('y', $cache->localStore()->get('p:b'));
    }

    public function test_clear_drops_every_local_entry(): void
    {
        $cache = $this->cache();
        $cache->get($this->connection, 'p:a');

        $cache->clear();

        $this->assertNull($cache->localStore()->get('p:a'));
    }

    public function test_a_value_larger_than_max_value_bytes_is_not_stored(): void
    {
        $cache = $this->cache(['max_value_bytes' => 4]);
        $this->client->values['p:a'] = 'longer than four bytes';

        $this->assertSame('longer than four bytes', $cache->get($this->connection, 'p:a'));
        $this->assertNull($cache->localStore()->get('p:a'));
    }

    public function test_a_value_of_exactly_max_value_bytes_is_stored(): void
    {
        $cache = $this->cache(['max_value_bytes' => 4]);
        $this->client->values['p:a'] = 'four';

        $cache->get($this->connection, 'p:a');

        $this->assertSame('four', $cache->localStore()->get('p:a'));
    }

    public function test_disable_makes_get_fall_through_to_the_server_every_time(): void
    {
        $cache = $this->cache();
        $cache->get($this->connection, 'p:a');
        $cache->disable();

        $this->client->reads = [];
        $this->assertSame('x', $cache->get($this->connection, 'p:a'));
        $this->assertSame('x', $cache->get($this->connection, 'p:a'));

        $this->assertSame(['GET', 'GET'], $this->client->reads);
        $this->assertNull($cache->localStore()->get('p:a'));
    }

    public function test_a_new_connection_gets_a_new_namespace_and_drops_the_old_entries(): void
    {
        $cache = $this->cache();
        $cache->get($this->connection, 'p:a');

        $this->client->reconnect(8);

        $this->assertSame(getmypid().':8', $cache->localStore()->namespace());
        $this->assertNull($cache->localStore()->get('p:a'));
        $this->assertTrue($cache->isActive());
    }

    public function test_a_tracked_read_inside_multi_throws(): void
    {
        $cache = $this->cache();
        $this->connection->multi();

        $this->expectException(LogicException::class);

        $cache->get($this->connection, 'p:a');
    }

    // ------------------------------------------------------------------ attaching to an open socket

    public function test_attaching_to_an_open_socket_with_tracking_on_drops_the_entries_held(): void
    {
        // A reused persistent socket: tracking still on with our options and
        // an entry held under its namespace, but a command that ran before
        // the cache attached may have dropped the invalidation for it.
        $this->stubConnection();
        $this->client->trackingInfo = ['flags' => ['on', 'optin'], 'redirect' => -1, 'prefixes' => []];
        $local = new ArrayLocalStore();
        $local->useNamespace(getmypid().':7');
        $local->set('p:a', 'stale');

        $cache = new ClientSideCache($this->client, self::config(), $local, 'p:');

        $this->assertTrue($cache->isActive());
        $this->assertSame('x', $cache->get($this->connection, 'p:a'));
        $this->assertSame(0, $this->countCommands(['CLIENT', 'TRACKING', 'ON', 'OPTIN']), 'tracking is kept, only the entries go');
    }

    public function test_a_reconnect_to_a_socket_with_tracking_on_keeps_the_entries(): void
    {
        $cache = $this->cache();
        $cache->get($this->connection, 'p:a');
        $this->client->trackingInfo = ['flags' => ['on', 'optin'], 'redirect' => -1, 'prefixes' => []];

        // The hook runs on connect with the listener already set: nothing
        // was dropped, so the namespace and its entries stay.
        $this->client->reconnect(7);

        $this->assertSame('x', $cache->localStore()->get('p:a'));
        $this->assertTrue($cache->isActive());
    }

    // ------------------------------------------------------------------ failed drain

    public function test_a_failed_drain_before_a_local_hit_falls_back_to_a_plain_get(): void
    {
        $cache = $this->cache();
        $cache->get($this->connection, 'p:a');
        $this->client->reads = [];
        $this->client->failNextDrain = true;

        $this->assertSame('x', $cache->get($this->connection, 'p:a'));

        $this->assertSame(['GET'], $this->client->reads);
        $this->assertCount(1, $this->client->pipelines, 'no tracked read after the failed drain');
        $this->assertNull($cache->localStore()->get('p:a'));
        $this->assertFalse($cache->isActive());
    }

    public function test_a_failed_drain_after_a_tracked_read_returns_the_value_and_stores_nothing(): void
    {
        $cache = $this->cache();
        $this->client->failDrainAfterPipeline = true;

        $this->assertSame('x', $cache->get($this->connection, 'p:a'));

        $this->assertNull($cache->localStore()->get('p:a'));
        $this->assertFalse($cache->isActive());
    }

    public function test_a_failed_drain_clears_the_local_store_of_every_sibling(): void
    {
        [$first, $second] = $this->siblings();
        $first->get($this->connection, 'p:a');
        $second->get($this->connection, 'q:a');
        $this->client->failNextDrain = true;

        $first->get($this->connection, 'p:a');

        $this->assertNull($second->localStore()->get('q:a'));
        $this->assertFalse($second->isActive());
    }

    // ------------------------------------------------------------------ one cache per config

    public function test_two_stores_with_the_same_config_and_prefix_share_one_instance(): void
    {
        $this->stubConnection();
        $config = self::config();

        $first = ClientSideCache::for($this->connection, $config, 'p:');

        $this->assertNotNull($first);
        $this->assertSame($first, ClientSideCache::for($this->connection, self::config(), 'p:'));
    }

    public function test_a_sibling_shares_the_namespace_without_another_handshake(): void
    {
        [$first, $second] = $this->siblings();

        $this->assertNotSame($first, $second);
        $this->assertNotSame($first->localStore(), $second->localStore());
        $this->assertTrue($second->isActive());
        $this->assertSame($first->localStore()->namespace(), $second->localStore()->namespace());
        $this->assertSame(1, $this->countCommands(['CLIENT', 'TRACKINGINFO']));
        $this->assertSame(1, $this->countCommands(['CLIENT', 'TRACKING', 'ON', 'OPTIN']));
    }

    public function test_siblings_keep_separate_local_entries(): void
    {
        [$first, $second] = $this->siblings();

        $first->get($this->connection, 'p:a');

        $this->assertSame('x', $first->localStore()->get('p:a'));
        $this->assertNull($second->localStore()->get('p:a'));
    }

    public function test_a_push_drained_by_one_sibling_reaches_the_other(): void
    {
        [$first, $second] = $this->siblings();
        $first->get($this->connection, 'p:a');
        $this->client->queuedPushes = [self::invalidate(['p:a'])];

        $second->get($this->connection, 'q:a');

        $this->assertNull($first->localStore()->get('p:a'));
    }

    public function test_a_push_delivered_with_a_reply_reaches_every_sibling(): void
    {
        [$first, $second] = $this->siblings();
        $first->get($this->connection, 'p:a');
        $this->client->pushesWithReply = [self::invalidate(['p:a'])];

        $second->get($this->connection, 'q:a');

        $this->assertNull($first->localStore()->get('p:a'));
        $this->assertSame('qx', $second->localStore()->get('q:a'));
    }

    public function test_a_reconnect_moves_every_sibling_to_the_new_namespace(): void
    {
        [$first, $second] = $this->siblings();
        $second->get($this->connection, 'q:a');

        $this->client->reconnect(8);

        $this->assertSame(getmypid().':8', $second->localStore()->namespace());
        $this->assertNull($second->localStore()->get('q:a'));
        $this->assertTrue($second->isActive());
        $this->assertSame(2, $this->countCommands(['CLIENT', 'TRACKINGINFO']), 'one handshake per connect, not per sibling');
    }

    public function test_a_sibling_with_other_local_settings_but_the_same_arguments_follows(): void
    {
        $this->stubConnection();

        $first = ClientSideCache::for($this->connection, self::config(), 'p:');
        $second = ClientSideCache::for($this->connection, self::config(['local_ttl' => 5]), 'p:');

        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);
        $this->assertTrue($second->isActive());
    }

    public function test_a_store_with_different_tracking_arguments_gets_null(): void
    {
        $this->stubConnection();
        $bcast = self::config(['mode' => 'bcast']);

        $this->assertNotNull(ClientSideCache::for($this->connection, $bcast, 'p:'));
        $this->assertNull(ClientSideCache::for($this->connection, $bcast, 'q:'), 'BCAST PREFIX q: differs');
        $this->assertNull(ClientSideCache::for($this->connection, $bcast, 'q:'), 'and stays null');
        $this->assertNull(ClientSideCache::for($this->connection, self::config(), 'p:'), 'OPTIN differs');
    }

    public function test_noloop_makes_the_tracking_arguments_differ(): void
    {
        $this->stubConnection();

        $this->assertNotNull(ClientSideCache::for($this->connection, self::config(), 'p:'));
        $this->assertNull(ClientSideCache::for($this->connection, self::config(['noloop' => true]), 'q:'));
    }

    // ------------------------------------------------------------------ local store choice

    public function test_auto_uses_the_array_store_when_local_ttl_is_below_one_second(): void
    {
        $this->stubConnection();
        $this->client->persistent = true;

        $cache = ClientSideCache::for($this->connection, self::config(['local_store' => 'auto', 'local_ttl' => 0]), 'p:');

        $this->assertInstanceOf(ArrayLocalStore::class, $cache?->localStore());
    }

    public function test_the_array_store_gets_max_bytes_from_the_config(): void
    {
        $this->stubConnection();
        // 'p:a' + 'x' is 4 bytes; two entries do not fit in 6.
        $cache = ClientSideCache::for($this->connection, self::config(['max_bytes' => 6]), 'p:');

        $cache?->get($this->connection, 'p:a');
        $cache?->get($this->connection, 'p:b');

        $this->assertNull($cache?->localStore()->get('p:a'));
        $this->assertSame('y', $cache?->localStore()->get('p:b'));
    }

    // ------------------------------------------------------------------ helpers

    private function stubConnection(): void
    {
        $this->client = new TrackingStubClient();
        $this->client->values = ['p:a' => 'x', 'p:b' => 'y', 'q:a' => 'qx'];
        $this->connection = new Resp3Connection($this->client);
    }

    /** @param array<string, mixed> $config */
    private static function config(array $config = []): TrackingConfig
    {
        return TrackingConfig::fromArray($config + ['enabled' => true, 'local_store' => 'array']);
    }

    /**
     * Two OPTIN stores on one connection with prefixes p: and q:.
     *
     * @return array{0: ClientSideCache, 1: ClientSideCache}
     */
    private function siblings(): array
    {
        $this->stubConnection();

        $first = ClientSideCache::for($this->connection, self::config(), 'p:');
        $second = ClientSideCache::for($this->connection, self::config(), 'q:');
        $this->assertNotNull($first);
        $this->assertNotNull($second);

        return [$first, $second];
    }

    /** @param list<string> $command */
    private function countCommands(array $command): int
    {
        return count(array_filter($this->client->commands, fn (array $sent) => $sent === $command));
    }
}

/**
 * Client double that is also a TrackableClient. Tracked reads come from
 * $values; $pushesWithReply reach the tracking listener (then the user's
 * push listener) after the replies of the next pipeline, $queuedPushes come
 * out of the next drainPushes().
 */
final class TrackingStubClient implements Resp3ClientInterface, TrackableClient
{
    use ClientStubDefaults;

    /** @var array<string, string> */
    public array $values = [];

    /** @var list<PushMessage> */
    public array $pushesWithReply = [];

    /** @var list<PushMessage> Queued for drainPushes() when the next pipeline ends. */
    public array $pushesAfterReply = [];

    /** @var list<PushMessage> */
    public array $queuedPushes = [];

    /** @var list<list<list<string>>> */
    public array $pipelines = [];

    /** @var list<list<string>> */
    public array $commands = [];

    /** @var list<string> GET and MGET names that reached the server */
    public array $reads = [];

    public ?Closure $trackingListener = null;

    /** The next drainPushes() throws ConnectionException and closes. */
    public bool $failNextDrain = false;

    /** The drainPushes() right after the next pipeline fails. */
    public bool $failDrainAfterPipeline = false;

    public int $drains = 0;

    public bool $persistent = false;

    /** @var array<string, mixed> The CLIENT TRACKINGINFO reply. */
    public array $trackingInfo = ['flags' => ['off'], 'redirect' => -1, 'prefixes' => []];

    private int $id = 7;

    private bool $connected = true;

    private ?Closure $hook = null;

    public function command(string $name, mixed ...$args): mixed
    {
        $name = strtoupper($name);
        $this->commands[] = [$name, ...array_map('strval', $args)];

        if ($name === 'CLIENT' && strtoupper((string) $args[0]) === 'TRACKINGINFO') {
            return $this->trackingInfo;
        }
        if ($name === 'GET') {
            return $this->read((string) $args[0], 'GET');
        }

        return 'OK';
    }

    public function pipeline(array $commands): array
    {
        $this->pipelines[] = $commands;

        $replies = [];
        foreach ($commands as $command) {
            $replies[] = match ($command[0]) {
                'GET' => $this->read($command[1], 'GET'),
                'MGET' => $this->readMany(array_slice($command, 1)),
                'PTTL' => array_key_exists($command[1], $this->values) ? -1 : -2,
                default => 'OK',
            };
        }

        $pushes = $this->pushesWithReply;
        $this->pushesWithReply = [];
        foreach ($pushes as $push) {
            // Tracking listener first, then the user's (the client contract).
            if ($this->trackingListener !== null) {
                ($this->trackingListener)($push);
            }
            if ($this->pushListener !== null) {
                ($this->pushListener)($push);
            }
        }

        array_push($this->queuedPushes, ...$this->pushesAfterReply);
        $this->pushesAfterReply = [];

        if ($this->failDrainAfterPipeline) {
            $this->failDrainAfterPipeline = false;
            $this->failNextDrain = true;
        }

        return $replies;
    }

    /** @return list<PushMessage> */
    public function drainPushes(): array
    {
        $this->drains++;

        if ($this->failNextDrain) {
            // Like Resp3Client on a drain overflow or parse error: the
            // socket is closed, the queued pushes are gone.
            $this->failNextDrain = false;
            $this->queuedPushes = [];
            $this->connected = false;
            throw new ConnectionException('drain overflow');
        }

        $pushes = $this->queuedPushes;
        $this->queuedPushes = [];

        return $pushes;
    }

    /** @param (Closure(PushMessage): void)|null $listener */
    public function setTrackingListener(?Closure $listener): void
    {
        $this->trackingListener = $listener;
    }

    public function send(string $name, mixed ...$args): void
    {
        $this->command($name, ...$args);
    }

    public function readNext(): mixed
    {
        return null;
    }

    public function close(): void
    {
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function connectionId(): ?int
    {
        return $this->connected ? $this->id : null;
    }

    public function onConnect(?Closure $hook): void
    {
        $this->hook = $hook;
        if ($hook !== null && $this->connected) {
            $hook($this);
        }
    }

    public function isPersistent(): bool
    {
        return $this->persistent;
    }

    /** Simulate a new socket with HELLO id $id: the connect hook runs again. */
    public function reconnect(int $id): void
    {
        $this->id = $id;
        $this->connected = true;
        if ($this->hook !== null) {
            ($this->hook)($this);
        }
    }

    private function read(string $key, string $name): ?string
    {
        $this->reads[] = $name;

        return $this->values[$key] ?? null;
    }

    /**
     * @param  list<string>  $keys
     * @return list<string|null>
     */
    private function readMany(array $keys): array
    {
        $this->reads[] = 'MGET';

        return array_map(fn (string $key) => $this->values[$key] ?? null, $keys);
    }
}
