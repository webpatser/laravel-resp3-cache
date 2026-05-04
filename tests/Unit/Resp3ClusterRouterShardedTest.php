<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Resp3\Laravel\Cluster\CRC16;
use Resp3\Laravel\Cluster\Resp3ClusterRouter;
use ReflectionClass;

/**
 * Sharded pub/sub additions to the cluster router. The router opens real
 * sockets via connectionFor() so we seed its private slot map via reflection
 * to keep these tests pure.
 */
final class Resp3ClusterRouterShardedTest extends TestCase
{
    public function test_subscriber_addr_for_channel_routes_via_crc16_slot(): void
    {
        $router = new Resp3ClusterRouter(
            seedNodes: [['host' => '127.0.0.1', 'port' => 7100]],
        );

        // Pre-populate masters so pickAddrForSlot does not try to reload topology.
        $this->seedMasters($router, [
            // Two contiguous slot ranges across two masters.
            ...array_fill_keys(range(0, 8191),     '127.0.0.1:7100'),
            ...array_fill_keys(range(8192, 16383), '127.0.0.1:7101'),
        ]);

        $channel = 'orders.{user42}.created';
        $expectedSlot = CRC16::slot($channel);
        $expectedAddr = $expectedSlot < 8192 ? '127.0.0.1:7100' : '127.0.0.1:7101';

        $this->assertSame($expectedAddr, $router->subscriberAddrForChannel($channel));
    }

    public function test_hash_tag_colocates_channels_on_same_addr(): void
    {
        $router = new Resp3ClusterRouter(
            seedNodes: [['host' => '127.0.0.1', 'port' => 7100]],
        );
        $this->seedMasters($router, [
            ...array_fill_keys(range(0, 8191),     '127.0.0.1:7100'),
            ...array_fill_keys(range(8192, 16383), '127.0.0.1:7101'),
        ]);

        $a = $router->subscriberAddrForChannel('orders.{u42}.created');
        $b = $router->subscriberAddrForChannel('orders.{u42}.updated');

        $this->assertSame($a, $b, 'Hash-tagged channels must land on the same master');
    }

    public function test_get_data_plane_options_exposes_constructor_settings(): void
    {
        $router = new Resp3ClusterRouter(
            seedNodes: [['host' => '127.0.0.1', 'port' => 7100]],
            username: 'alice',
            password: 'secret',
            tls: true,
            timeout: 2.5,
            persistent: true,
            tlsOptions: ['verify_peer' => true],
        );

        $opts = $router->getDataPlaneOptions();
        $this->assertSame('alice',  $opts['username']);
        $this->assertSame('secret', $opts['password']);
        $this->assertTrue($opts['tls']);
        $this->assertSame(2.5,      $opts['timeout']);
        $this->assertTrue($opts['persistent']);
        $this->assertSame(['verify_peer' => true], $opts['tlsOptions']);
    }

    public function test_extract_keys_returns_first_arg_for_spublish(): void
    {
        $router = new Resp3ClusterRouter(
            seedNodes: [['host' => '127.0.0.1', 'port' => 7100]],
        );
        $reflection = new ReflectionClass($router);
        $extract = $reflection->getMethod('extractKeys');

        $this->assertSame(
            ['orders.{u42}'],
            $extract->invoke($router, 'SPUBLISH', ['orders.{u42}', 'payload']),
        );
        $this->assertSame([], $extract->invoke($router, 'SPUBLISH', []));
    }

    /** @param array<int, string> $masters slot => "host:port" */
    private function seedMasters(Resp3ClusterRouter $router, array $masters): void
    {
        $reflection = new ReflectionClass($router);
        $reflection->getProperty('masters')->setValue($router, $masters);
        $reflection->getProperty('topologyLoaded')->setValue($router, true);
    }
}
