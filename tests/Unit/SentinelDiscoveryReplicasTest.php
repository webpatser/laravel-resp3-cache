<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Resp3\Laravel\Client\ConnectionException;
use Resp3\Laravel\Sentinel\SentinelDiscovery;

/**
 * SentinelDiscovery::discoverReplicas behaviour. Same socket-free pattern as
 * SentinelDiscoveryTest: inject a client factory that hands back FakeSentinel
 * instances primed with the reply we want to test.
 */
final class SentinelDiscoveryReplicasTest extends TestCase
{
    public function test_returns_healthy_replicas(): void
    {
        $discovery = new SentinelDiscovery(
            seeds: [['host' => 's1', 'port' => 26379]],
            service: 'mymaster',
            clientFactory: fn () => new FakeSentinel(reply: [
                $this->replicaInfo('10.0.0.10', 6501, 'slave'),
                $this->replicaInfo('10.0.0.11', 6502, 'slave'),
            ]),
        );

        $this->assertSame(
            [
                ['host' => '10.0.0.10', 'port' => 6501],
                ['host' => '10.0.0.11', 'port' => 6502],
            ],
            $discovery->discoverReplicas(),
        );
    }

    public function test_filters_out_s_down_and_o_down_replicas(): void
    {
        $discovery = new SentinelDiscovery(
            seeds: [['host' => 's1', 'port' => 26379]],
            service: 'mymaster',
            clientFactory: fn () => new FakeSentinel(reply: [
                $this->replicaInfo('10.0.0.10', 6501, 'slave,s_down,disconnected'),
                $this->replicaInfo('10.0.0.11', 6502, 'slave'),
                $this->replicaInfo('10.0.0.12', 6503, 'slave,o_down'),
            ]),
        );

        $this->assertSame(
            [['host' => '10.0.0.11', 'port' => 6502]],
            $discovery->discoverReplicas(),
        );
    }

    public function test_returns_empty_list_when_no_replicas_configured(): void
    {
        $discovery = new SentinelDiscovery(
            seeds: [['host' => 's1', 'port' => 26379]],
            service: 'mymaster',
            clientFactory: fn () => new FakeSentinel(reply: []),
        );

        $this->assertSame([], $discovery->discoverReplicas());
    }

    public function test_falls_through_to_next_sentinel_on_connection_error(): void
    {
        $sequence = [
            new FakeSentinel(throwsOnConnect: true),
            new FakeSentinel(reply: [$this->replicaInfo('10.0.0.42', 6501, 'slave')]),
        ];
        $discovery = new SentinelDiscovery(
            seeds: [
                ['host' => 's1', 'port' => 26379],
                ['host' => 's2', 'port' => 26379],
            ],
            service: 'mymaster',
            clientFactory: function () use (&$sequence) {
                return array_shift($sequence) ?? throw new \LogicException('exhausted fakes');
            },
        );

        $this->assertSame(
            [['host' => '10.0.0.42', 'port' => 6501]],
            $discovery->discoverReplicas(),
        );
    }

    public function test_throws_when_no_seed_responds(): void
    {
        $discovery = new SentinelDiscovery(
            seeds: [
                ['host' => 's1', 'port' => 26379],
                ['host' => 's2', 'port' => 26379],
            ],
            service: 'mymaster',
            clientFactory: fn () => new FakeSentinel(throwsOnConnect: true),
        );

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessageMatches("/Could not query replicas for 'mymaster'/");

        $discovery->discoverReplicas();
    }

    public function test_accepts_resp3_map_shape(): void
    {
        $discovery = new SentinelDiscovery(
            seeds: [['host' => 's1', 'port' => 26379]],
            service: 'mymaster',
            clientFactory: fn () => new FakeSentinel(reply: [
                ['ip' => '10.0.0.20', 'port' => '6501', 'flags' => 'slave'],
                ['ip' => '10.0.0.21', 'port' => '6502', 'flags' => 'slave'],
            ]),
        );

        $this->assertSame(
            [
                ['host' => '10.0.0.20', 'port' => 6501],
                ['host' => '10.0.0.21', 'port' => 6502],
            ],
            $discovery->discoverReplicas(),
        );
    }

    /** @return list<string> A flat key/value list as Sentinel returns under RESP2. */
    private function replicaInfo(string $ip, int $port, string $flags): array
    {
        return [
            'name', "{$ip}:{$port}",
            'ip',   $ip,
            'port', (string) $port,
            'flags', $flags,
        ];
    }
}
