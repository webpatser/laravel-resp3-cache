<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Resp3\Laravel\Client\ConnectionException;
use Resp3\Laravel\Client\Resp3Client;
use Resp3\Laravel\Tests\Support\FakeRespServer;
use Resp3\PushMessage;

/**
 * Push handling of Resp3Client against a scripted fake server. Replies that
 * start with "!" are sent unprompted, "~" closes the connection.
 */
final class PushHandlingTest extends TestCase
{
    private const PUSH = ">2\r\n\$4\r\nnote\r\n\$1\r\nx\r\n";

    private ?FakeRespServer $server = null;

    protected function setUp(): void
    {
        if (!extension_loaded('resp3')) {
            $this->markTestSkipped('ext-resp3 is not loaded');
        }
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    /** @param list<list<string>> $connections */
    private function client(array $connections): Resp3Client
    {
        $this->server = new FakeRespServer($connections);

        return new Resp3Client(host: '127.0.0.1', port: $this->server->port, timeout: 3.0);
    }

    public function test_drain_pushes_returns_queued_pushes_without_blocking(): void
    {
        $client = $this->client([[
            FakeRespServer::HELLO, "+PONG\r\n", '!' . self::PUSH, '!' . self::PUSH,
        ]]);
        $this->assertSame('PONG', $client->command('PING'));
        usleep(500_000);

        $pushes = $client->drainPushes();

        $this->assertCount(2, $pushes);
        $this->assertContainsOnlyInstancesOf(PushMessage::class, $pushes);

        $start = microtime(true);
        $this->assertSame([], $client->drainPushes(), 'nothing pending');
        $this->assertLessThan(1.0, microtime(true) - $start, 'an empty drain must not wait for the server');
        $this->assertTrue($client->isConnected());
    }

    public function test_drain_pushes_when_server_closes_returns_received_pushes_and_reconnects(): void
    {
        $client = $this->client([
            [FakeRespServer::HELLO, "+PONG\r\n", '!' . self::PUSH, '~'],
            [FakeRespServer::HELLO, "+PONG\r\n"],
        ]);
        $this->assertSame('PONG', $client->command('PING'));
        usleep(500_000);

        // The pushes that arrived before the hang-up are delivered, then the
        // dead socket is dropped instead of being reused.
        $pushes = $client->drainPushes();

        $this->assertCount(1, $pushes);
        $this->assertFalse($client->isConnected());
        $this->assertSame([], $client->drainPushes());
        $this->assertSame('PONG', $client->command('PING'), 'the next command reconnects');
    }

    public function test_read_next_returns_a_queued_push_before_the_pending_reply(): void
    {
        $client = $this->client([[
            FakeRespServer::HELLO, self::PUSH . "+PONG\r\n",
        ]]);
        $client->send('PING');

        $first = $client->readNext();
        $second = $client->readNext();

        $this->assertInstanceOf(PushMessage::class, $first);
        $this->assertSame('PONG', $second);
    }

    public function test_stray_reply_during_drain_closes_the_connection(): void
    {
        $client = $this->client([
            [FakeRespServer::HELLO, "+PONG\r\n", "!+STRAY\r\n"],
            [FakeRespServer::HELLO, "+PONG\r\n"],
        ]);
        $this->assertSame('PONG', $client->command('PING'));
        usleep(500_000);

        try {
            $client->drainPushes();
            $this->fail('A stray reply did not throw');
        } catch (ConnectionException) {
            $this->assertFalse($client->isConnected());
        }

        $this->assertSame('PONG', $client->command('PING'));
    }
}
