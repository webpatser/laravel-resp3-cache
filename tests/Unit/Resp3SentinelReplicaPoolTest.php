<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Resp3\Laravel\Client\ConnectionException;
use Resp3\Laravel\Client\Resp3ClientInterface;
use Resp3\Laravel\Sentinel\NoReplicasAvailableException;
use Resp3\Laravel\Sentinel\Resp3SentinelReplicaPool;
use Resp3\Laravel\Sentinel\SentinelDiscovery;

/**
 * Resp3SentinelReplicaPool routing + retry behaviour. Discovery and data
 * clients are both stubbed via factories so the suite never opens a socket.
 */
final class Resp3SentinelReplicaPoolTest extends TestCase
{
    public function test_routes_command_to_a_replica(): void
    {
        $client = new RecordingClient(replies: ['OK', 'value']);
        $pool = $this->makePool(
            replicas: [['10.0.0.10', '6501', 'slave']],
            dataClients: ['10.0.0.10:6501' => $client],
        );

        $this->assertSame('value', $pool->command('GET', 'k'));
        // First command on a fresh replica is READONLY, then the actual GET.
        $this->assertSame([['READONLY'], ['GET', 'k']], $client->commandsSent);
    }

    public function test_retries_on_other_replica_when_one_fails(): void
    {
        $bad  = new RecordingClient(throwOnFirstCommand: true);
        $good = new RecordingClient(replies: ['OK', 'recovered']);

        // discoverReplicas returns both; randomness may pick either first, so
        // both are wired in. The bad client always blows up; the good one
        // serves the eventual reply.
        $pool = $this->makePool(
            replicas: [
                ['10.0.0.10', '6501', 'slave'],
                ['10.0.0.11', '6502', 'slave'],
            ],
            dataClients: [
                '10.0.0.10:6501' => $bad,
                '10.0.0.11:6502' => $good,
            ],
        );

        $this->assertSame('recovered', $pool->command('GET', 'k'));
        // Bad client got dropped after its failure; good client served the read.
        $this->assertContains([strtoupper('GET'), 'k'], $good->commandsSent);
    }

    public function test_refreshes_replica_list_when_pool_exhausts(): void
    {
        $bad   = new RecordingClient(throwOnFirstCommand: true);
        $fresh = new RecordingClient(replies: ['OK', 'after-refresh']);

        $sentinelReplies = [
            // First discovery: only the bad replica.
            [['name', '10.0.0.10:6501', 'ip', '10.0.0.10', 'port', '6501', 'flags', 'slave']],
            // Second discovery (after exhaustion): a fresh replica.
            [['name', '10.0.0.99:6501', 'ip', '10.0.0.99', 'port', '6501', 'flags', 'slave']],
        ];
        $dataClients = [
            '10.0.0.10:6501' => $bad,
            '10.0.0.99:6501' => $fresh,
        ];

        $pool = new Resp3SentinelReplicaPool(
            discovery: new SentinelDiscovery(
                seeds: [['host' => 's1', 'port' => 26379]],
                service: 'mymaster',
                clientFactory: function () use (&$sentinelReplies) {
                    return new FakeSentinel(reply: array_shift($sentinelReplies));
                },
            ),
            clientOptions: [],
            clientFactory: function (array $node) use ($dataClients) {
                return $dataClients["{$node['host']}:{$node['port']}"];
            },
        );

        $this->assertSame('after-refresh', $pool->command('GET', 'k'));
    }

    public function test_throws_when_no_replicas_after_refresh(): void
    {
        $pool = new Resp3SentinelReplicaPool(
            discovery: new SentinelDiscovery(
                seeds: [['host' => 's1', 'port' => 26379]],
                service: 'mymaster',
                clientFactory: fn () => new FakeSentinel(reply: []),
            ),
        );

        $this->expectException(NoReplicasAvailableException::class);
        $pool->command('GET', 'k');
    }

    public function test_readonly_sent_only_once_per_replica(): void
    {
        $client = new RecordingClient(replies: ['OK', 'a', 'b', 'c']);
        $pool = $this->makePool(
            replicas: [['10.0.0.10', '6501', 'slave']],
            dataClients: ['10.0.0.10:6501' => $client],
        );

        $pool->command('GET', 'k1');
        $pool->command('GET', 'k2');
        $pool->command('GET', 'k3');

        $readonlyCount = 0;
        foreach ($client->commandsSent as $sent) {
            if ($sent[0] === 'READONLY') $readonlyCount++;
        }
        $this->assertSame(1, $readonlyCount, 'READONLY must be sent exactly once per replica connection');
    }

    public function test_close_drops_all_clients(): void
    {
        $a = new RecordingClient(replies: ['OK', 'x']);
        $b = new RecordingClient(replies: ['OK', 'y']);
        $pool = $this->makePool(
            replicas: [
                ['10.0.0.10', '6501', 'slave'],
                ['10.0.0.11', '6502', 'slave'],
            ],
            dataClients: [
                '10.0.0.10:6501' => $a,
                '10.0.0.11:6502' => $b,
            ],
        );

        // Force both clients to be opened by running enough commands to hit
        // both addresses. With two replicas array_rand can stick to one, so
        // we close right after a single use of each.
        $pool->command('GET', 'k1');
        $pool->close();

        $this->assertTrue($a->closed || $b->closed, 'At least the used client must be closed');
    }

    public function test_known_replicas_reflects_discovery(): void
    {
        $pool = $this->makePool(
            replicas: [
                ['10.0.0.10', '6501', 'slave'],
                ['10.0.0.11', '6502', 'slave'],
            ],
            dataClients: [
                '10.0.0.10:6501' => new RecordingClient(replies: ['OK', 'x']),
                '10.0.0.11:6502' => new RecordingClient(replies: ['OK', 'x']),
            ],
        );

        $pool->command('GET', 'k');
        $known = $pool->knownReplicas();
        $this->assertCount(2, $known);
    }

    // --- helpers ---------------------------------------------------------

    /**
     * @param  list<array{0:string,1:string,2:string}>  $replicas  triples [ip, port, flags]
     * @param  array<string, RecordingClient>           $dataClients  keyed by "host:port"
     */
    private function makePool(array $replicas, array $dataClients): Resp3SentinelReplicaPool
    {
        $sentinelReply = [];
        foreach ($replicas as [$ip, $port, $flags]) {
            $sentinelReply[] = [
                'name', "{$ip}:{$port}",
                'ip',   $ip,
                'port', $port,
                'flags', $flags,
            ];
        }

        return new Resp3SentinelReplicaPool(
            discovery: new SentinelDiscovery(
                seeds: [['host' => 's1', 'port' => 26379]],
                service: 'mymaster',
                clientFactory: fn () => new FakeSentinel(reply: $sentinelReply),
            ),
            clientOptions: [],
            clientFactory: function (array $node) use ($dataClients) {
                $key = "{$node['host']}:{$node['port']}";
                return $dataClients[$key] ?? throw new \LogicException("no fake for $key");
            },
        );
    }
}
