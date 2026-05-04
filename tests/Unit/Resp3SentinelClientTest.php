<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Resp3\Laravel\Client\ConnectionException;
use Resp3\Laravel\Client\Resp3ClientInterface;
use Resp3\Laravel\Sentinel\Resp3SentinelClient;
use Resp3\Laravel\Sentinel\SentinelDiscovery;

/**
 * Resp3SentinelClient routing + retry behaviour with stub data-plane
 * clients. Discovery is provided through the same factory pattern so
 * unit tests stay socket-free.
 */
final class Resp3SentinelClientTest extends TestCase
{
    public function test_command_routes_to_discovered_master(): void
    {
        $dataClient = new RecordingClient(replies: ['PONG']);
        $sentinel = $this->makeSentinel(
            masterAddr: ['10.0.0.5', '6500'],
            dataClient: $dataClient,
        );

        $this->assertSame('PONG', $sentinel->command('PING'));
        $this->assertSame([['PING']], $dataClient->commandsSent);
    }

    public function test_connection_exception_triggers_rediscovery_and_retry(): void
    {
        $first  = new RecordingClient(throwOnFirstCommand: true);
        $second = new RecordingClient(replies: ['after-failover']);

        $sentinel = $this->makeSentinelWithSequence(
            masterAddrs: [['10.0.0.5', '6500'], ['10.0.0.6', '6500']],
            dataClients: [$first, $second],
        );

        $this->assertSame('after-failover', $sentinel->command('GET', 'k'));
        $this->assertTrue($first->closed);
    }

    // The READONLY-on-demoted-master rediscovery path needs a real
    // Resp3\RedisException (provided by ext-resp3) and is exercised in
    // tests/Feature/SentinelTest::test_failover_picks_up_new_master.

    public function test_discovery_failure_propagates(): void
    {
        $sentinel = new Resp3SentinelClient(
            discovery: new SentinelDiscovery(
                seeds: [['host' => 's1', 'port' => 26379]],
                service: 'mymaster',
                clientFactory: fn () => new FakeSentinel(throwsOnConnect: true),
            ),
            clientFactory: fn () => new RecordingClient(),
        );

        $this->expectException(ConnectionException::class);
        $sentinel->command('PING');
    }

    public function test_close_drops_underlying_client(): void
    {
        $client = new RecordingClient(replies: ['PONG']);
        $sentinel = $this->makeSentinel(['10.0.0.5', '6500'], $client);

        $sentinel->command('PING');
        $sentinel->close();

        $this->assertTrue($client->closed);
    }

    // --- helpers ---------------------------------------------------------

    /** @param array{0:string,1:string|int} $masterAddr */
    private function makeSentinel(array $masterAddr, RecordingClient $dataClient): Resp3SentinelClient
    {
        return new Resp3SentinelClient(
            discovery: new SentinelDiscovery(
                seeds: [['host' => 's1', 'port' => 26379]],
                service: 'mymaster',
                clientFactory: fn () => new FakeSentinel(reply: $masterAddr),
            ),
            clientFactory: fn () => $dataClient,
        );
    }

    /**
     * @param  list<array{0:string,1:string|int}>  $masterAddrs
     * @param  list<RecordingClient>               $dataClients
     */
    private function makeSentinelWithSequence(array $masterAddrs, array $dataClients): Resp3SentinelClient
    {
        $sentinelReplies = $masterAddrs;
        $dataSequence    = $dataClients;

        return new Resp3SentinelClient(
            discovery: new SentinelDiscovery(
                seeds: [['host' => 's1', 'port' => 26379]],
                service: 'mymaster',
                clientFactory: function () use (&$sentinelReplies) {
                    return new FakeSentinel(reply: array_shift($sentinelReplies));
                },
            ),
            clientFactory: function () use (&$dataSequence) {
                return array_shift($dataSequence);
            },
        );
    }
}

/** Records every command and can be primed with a queue of replies. */
final class RecordingClient implements Resp3ClientInterface
{
    /** @var list<array> */
    public array $commandsSent = [];
    public bool $closed = false;

    public function __construct(
        public array $replies = [],
        public bool $throwOnFirstCommand = false,
    ) {}

    public function command(string $name, mixed ...$args): mixed
    {
        $this->commandsSent[] = [strtoupper($name), ...$args];
        if ($this->throwOnFirstCommand) {
            $this->throwOnFirstCommand = false;
            throw new ConnectionException('simulated socket failure');
        }
        return array_shift($this->replies);
    }

    public function readNext(): mixed { return array_shift($this->replies); }
    public function pipeline(array $commands): array
    {
        $out = [];
        foreach ($commands as $_) {
            $out[] = array_shift($this->replies);
        }
        return $out;
    }
    public function close(): void { $this->closed = true; }
    public function isConnected(): bool { return !$this->closed; }
}
