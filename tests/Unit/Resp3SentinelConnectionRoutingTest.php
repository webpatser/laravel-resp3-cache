<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Resp3\Laravel\Connections\Resp3SentinelConnection;
use Resp3\Laravel\Sentinel\NoReplicasAvailableException;
use Resp3\Laravel\Sentinel\Resp3SentinelClient;
use Resp3\Laravel\Sentinel\Resp3SentinelReplicaPool;
use Resp3\Laravel\Sentinel\SentinelDiscovery;

/**
 * Resp3SentinelConnection routing: reads go to the replica pool, writes go to
 * the master, MULTI buffer routes everything to master, and a missing pool
 * is invisible (v0.4 behaviour).
 */
final class Resp3SentinelConnectionRoutingTest extends TestCase
{
    public function test_read_routes_to_replica_pool_when_pool_present(): void
    {
        $masterClient = new RecordingClient(replies: ['unused']);
        $replicaClient = new RecordingClient(replies: ['OK', 'replica-value']);

        $connection = $this->makeConnection(
            masterClient: $masterClient,
            replicaClients: ['10.0.0.10:6501' => $replicaClient],
            replicas: [['10.0.0.10', '6501', 'slave']],
        );

        $reply = $connection->command('GET', ['k']);
        $this->assertSame('replica-value', $reply);

        // Master never saw the GET.
        $this->assertEmpty(array_filter(
            $masterClient->commandsSent,
            fn ($c) => ($c[0] ?? '') === 'GET',
        ));
        // Replica saw READONLY then GET.
        $this->assertSame([['READONLY'], ['GET', 'k']], $replicaClient->commandsSent);
    }

    public function test_write_routes_to_master_even_with_pool(): void
    {
        $masterClient = new RecordingClient(replies: ['OK']);
        $replicaClient = new RecordingClient(replies: ['unused']);

        $connection = $this->makeConnection(
            masterClient: $masterClient,
            replicaClients: ['10.0.0.10:6501' => $replicaClient],
            replicas: [['10.0.0.10', '6501', 'slave']],
        );

        $reply = $connection->command('SET', ['k', 'v']);
        $this->assertSame('OK', $reply);

        $this->assertSame([['SET', 'k', 'v']], $masterClient->commandsSent);
        $this->assertEmpty($replicaClient->commandsSent);
    }

    public function test_pool_exhaustion_falls_back_to_master(): void
    {
        $masterClient = new RecordingClient(replies: ['fallback-value']);
        // No replicas in discovery: pool is empty after refresh, throws
        // NoReplicasAvailable which the connection catches.
        $connection = $this->makeConnection(
            masterClient: $masterClient,
            replicaClients: [],
            replicas: [],
        );

        $reply = $connection->command('GET', ['k']);
        $this->assertSame('fallback-value', $reply);
        $this->assertSame([['GET', 'k']], $masterClient->commandsSent);
    }

    public function test_no_pool_behaves_exactly_like_v04(): void
    {
        $masterClient = new RecordingClient(replies: ['v0.4-value']);

        $sentinelDiscovery = new SentinelDiscovery(
            seeds: [['host' => 's1', 'port' => 26379]],
            service: 'mymaster',
            clientFactory: fn () => new FakeSentinel(reply: ['10.0.0.99', '6500']),
        );
        $client = new Resp3SentinelClient(
            discovery: $sentinelDiscovery,
            clientFactory: fn () => $masterClient,
        );

        $connection = new Resp3SentinelConnection($client, [], replicaPool: null);

        $reply = $connection->command('GET', ['k']);
        $this->assertSame('v0.4-value', $reply);
        $this->assertSame([['GET', 'k']], $masterClient->commandsSent);
    }

    public function test_read_inside_multi_routes_to_master(): void
    {
        $masterClient = new RecordingClient(replies: [
            'OK',                 // MULTI ack
            'OK',                 // EXEC pipeline ack (irrelevant; we'll only assert MULTI/queued behaviour)
        ]);
        $replicaClient = new RecordingClient();

        $connection = $this->makeConnection(
            masterClient: $masterClient,
            replicaClients: ['10.0.0.10:6501' => $replicaClient],
            replicas: [['10.0.0.10', '6501', 'slave']],
        );

        $connection->multi();
        // GET inside the MULTI buffer should NOT touch the replica pool.
        $connection->command('GET', ['k']);
        $this->assertEmpty($replicaClient->commandsSent, 'Replica must not see commands queued inside MULTI');
    }

    // --- helpers ---------------------------------------------------------

    /**
     * @param  array<string, RecordingClient>           $replicaClients keyed by "host:port"
     * @param  list<array{0:string,1:string,2:string}>  $replicas       triples [ip, port, flags]
     */
    private function makeConnection(
        RecordingClient $masterClient,
        array $replicaClients,
        array $replicas,
    ): Resp3SentinelConnection {
        $sentinelReply = [];
        foreach ($replicas as [$ip, $port, $flags]) {
            $sentinelReply[] = [
                'name', "{$ip}:{$port}",
                'ip',   $ip,
                'port', $port,
                'flags', $flags,
            ];
        }

        // The same SentinelDiscovery instance backs both the master client
        // (get-master-addr-by-name) and the replica pool (replicas). The
        // FakeSentinel returns whatever reply we hand it; for tests we only
        // exercise one path at a time, so a stable reply is fine.
        $discoveryForMaster = new SentinelDiscovery(
            seeds: [['host' => 's1', 'port' => 26379]],
            service: 'mymaster',
            clientFactory: fn () => new FakeSentinel(reply: ['10.0.0.99', '6500']),
        );
        $discoveryForReplicas = new SentinelDiscovery(
            seeds: [['host' => 's1', 'port' => 26379]],
            service: 'mymaster',
            clientFactory: fn () => new FakeSentinel(reply: $sentinelReply),
        );

        $client = new Resp3SentinelClient(
            discovery: $discoveryForMaster,
            clientFactory: fn () => $masterClient,
        );

        $pool = new Resp3SentinelReplicaPool(
            discovery: $discoveryForReplicas,
            clientOptions: [],
            clientFactory: function (array $node) use ($replicaClients) {
                $key = "{$node['host']}:{$node['port']}";
                return $replicaClients[$key] ?? throw new \LogicException("no replica fake for $key");
            },
        );

        return new Resp3SentinelConnection($client, [], $pool);
    }
}
