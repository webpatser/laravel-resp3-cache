<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Unit;

use BadMethodCallException;
use PHPUnit\Framework\TestCase;
use Resp3\Laravel\Cluster\CRC16;
use Resp3\Laravel\Cluster\Resp3ClusterRouter;
use Resp3\Laravel\Connections\Resp3ClusterConnection;
use ReflectionClass;
use RuntimeException;

/**
 * Resp3ClusterConnection sharded pub/sub plumbing: same-slot validation,
 * router lookup, and the regular-subscribe rejection hint update. Real
 * subscriber sockets need a live cluster; the proper feature coverage lives
 * in tests/Feature/ClusterShardedPubSubTest.
 */
final class Resp3ClusterConnectionShardedTest extends TestCase
{
    public function test_validate_same_slot_accepts_hash_tagged_channels(): void
    {
        $connection = $this->makeConnection();
        $reflection = new ReflectionClass($connection);
        $validate = $reflection->getMethod('validateSameSlotChannels');

        $first = $validate->invoke($connection, [
            'orders.{u42}.created',
            'orders.{u42}.updated',
            'orders.{u42}.deleted',
        ]);

        $this->assertSame('orders.{u42}.created', $first);
    }

    public function test_validate_same_slot_rejects_cross_slot_channels(): void
    {
        $connection = $this->makeConnection();
        $reflection = new ReflectionClass($connection);
        $validate = $reflection->getMethod('validateSameSlotChannels');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/CROSSSLOT.*hash tag/i');

        // No hash tag, so each channel hashes by full name; very unlikely
        // these collide on the same slot.
        $validate->invoke($connection, [
            'channel-a-' . str_repeat('x', 16),
            'channel-b-' . str_repeat('y', 16),
        ]);
    }

    public function test_create_subscription_hint_mentions_ssubscribe(): void
    {
        $connection = $this->makeConnection();

        try {
            $connection->createSubscription(['ch'], fn () => null);
            $this->fail('Expected BadMethodCallException');
        } catch (BadMethodCallException $e) {
            $this->assertStringContainsString('ssubscribe', $e->getMessage());
        }
    }

    public function test_ssubscribe_with_no_channels_rejected(): void
    {
        $connection = $this->makeConnection();
        $this->expectException(\InvalidArgumentException::class);
        $connection->ssubscribe([], fn () => null);
    }

    private function makeConnection(): Resp3ClusterConnection
    {
        $router = new Resp3ClusterRouter(
            seedNodes: [['host' => '127.0.0.1', 'port' => 7100]],
        );
        // Seed the slot map so subscriberAddrForChannel does not try to load
        // topology from a real socket.
        $r = new ReflectionClass($router);
        $r->getProperty('masters')->setValue($router, array_fill_keys(range(0, 16383), '127.0.0.1:7100'));
        $r->getProperty('topologyLoaded')->setValue($router, true);

        return new Resp3ClusterConnection($router, []);
    }
}
