<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Resp3\Laravel\Client\ConnectionException;
use Resp3\Laravel\Sentinel\SentinelDiscovery;

/**
 * Drives SentinelDiscovery with an injected client factory so we never
 * touch a real socket.
 */
final class SentinelDiscoveryTest extends TestCase
{
    public function test_first_sentinel_replies_with_master_address(): void
    {
        $discovery = new SentinelDiscovery(
            seeds: [['host' => 's1', 'port' => 26379]],
            service: 'mymaster',
            clientFactory: fn () => new FakeSentinel(reply: ['10.0.0.99', '6500']),
        );

        $this->assertSame(
            ['host' => '10.0.0.99', 'port' => 6500],
            $discovery->discoverMaster(),
        );
    }

    public function test_falls_through_to_next_sentinel_on_connection_error(): void
    {
        $sequence = [
            new FakeSentinel(throwsOnConnect: true),
            new FakeSentinel(reply: ['10.0.0.42', '6500']),
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
            ['host' => '10.0.0.42', 'port' => 6500],
            $discovery->discoverMaster(),
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
        $this->expectExceptionMessageMatches("/Could not discover master 'mymaster'/");

        $discovery->discoverMaster();
    }

    public function test_empty_seeds_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SentinelDiscovery(seeds: [], service: 'mymaster');
    }

    public function test_empty_service_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SentinelDiscovery(seeds: [['host' => 's', 'port' => 26379]], service: '');
    }

    public function test_unexpected_reply_shape_falls_through(): void
    {
        $sequence = [
            new FakeSentinel(reply: ['only-one-element']),       // bad shape
            new FakeSentinel(reply: ['10.0.0.7', '6500']),       // good
        ];
        $discovery = new SentinelDiscovery(
            seeds: [
                ['host' => 's1', 'port' => 26379],
                ['host' => 's2', 'port' => 26379],
            ],
            service: 'mymaster',
            clientFactory: function () use (&$sequence) {
                return array_shift($sequence);
            },
        );

        $this->assertSame(
            ['host' => '10.0.0.7', 'port' => 6500],
            $discovery->discoverMaster(),
        );
    }
}

/** Stand-in for a Resp3ClientInterface, never opens a socket. */
final class FakeSentinel implements \Resp3\Laravel\Client\Resp3ClientInterface
{
    use \Resp3\Laravel\Tests\Support\ClientStubDefaults;

    public function send(string $name, mixed ...$args): void {}

    public function __construct(
        public mixed $reply = null,
        public bool $throwsOnConnect = false,
    ) {}

    public function command(string $name, mixed ...$args): mixed
    {
        if ($this->throwsOnConnect) {
            throw new ConnectionException('simulated socket failure');
        }
        return $this->reply;
    }

    public function close(): void {}
    public function isConnected(): bool { return !$this->throwsOnConnect; }
    public function readNext(): mixed { return null; }
    public function pipeline(array $commands): array { return []; }
}
