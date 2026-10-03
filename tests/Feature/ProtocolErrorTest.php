<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Resp3\Laravel\Client\ConnectionException;
use Resp3\Laravel\Client\Resp3Client;
use Resp3\Laravel\Tests\Support\FakeRespServer;
use Resp3\PushMessage;

/**
 * Wire faults against a scripted fake server (no real Redis involved):
 * malformed replies drop the connection with a ConnectionException, the
 * next command reconnects, and a push frame injected into a pipeline does
 * not shift the replies.
 */
final class ProtocolErrorTest extends TestCase
{
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

    public function test_garbage_reply_throws_and_the_next_command_reconnects(): void
    {
        $client = $this->client([
            [FakeRespServer::HELLO, "Zgarbage\r\n"],
            [FakeRespServer::HELLO, "+PONG\r\n"],
        ]);

        try {
            $client->command('PING');
            $this->fail('A malformed reply did not throw');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('protocol error', $e->getMessage());
        }
        $this->assertFalse($client->isConnected(), 'a protocol fault drops the socket');

        $this->assertSame('PONG', $client->command('PING'));
        $this->assertTrue($client->isConnected());
    }

    public function test_garbage_in_the_middle_of_a_pipeline_throws_and_recovers(): void
    {
        $client = $this->client([
            [FakeRespServer::HELLO, "+PONG\r\nZgarbage\r\n"],
            [FakeRespServer::HELLO, "+PONG\r\n"],
        ]);

        try {
            $client->pipeline([['PING'], ['PING']]);
            $this->fail('A malformed pipeline reply did not throw');
        } catch (ConnectionException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('PONG', $client->command('PING'));
    }

    public function test_truncated_reply_times_out_and_recovers(): void
    {
        $client = $this->client([
            [FakeRespServer::HELLO, "\$10\r\nabc"],
            [FakeRespServer::HELLO, "+PONG\r\n"],
        ]);

        // The fake keeps the socket open, so the read ends in the client's
        // 3 second timeout, which must surface as a ConnectionException too.
        try {
            $client->command('GET', 'k');
            $this->fail('A truncated reply did not throw');
        } catch (ConnectionException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('PONG', $client->command('PING'));
    }

    public function test_push_injected_into_a_pipeline_keeps_replies_aligned(): void
    {
        $client = $this->client([[
            FakeRespServer::HELLO,
            "+PONG\r\n>2\r\n\$4\r\nnote\r\n\$1\r\nx\r\n+PONG\r\n",
            "+PONG\r\n",
        ]]);
        $pushes = [];
        $client->setPushListener(function (PushMessage $push) use (&$pushes): void {
            $pushes[] = $push;
        });

        $replies = $client->pipeline([['PING'], ['PING']]);

        $this->assertSame(['PONG', 'PONG'], $replies);
        $this->assertCount(1, $pushes);
        $this->assertInstanceOf(PushMessage::class, $pushes[0]);
        $this->assertSame('PONG', $client->command('PING'), 'the stream is still in step');
    }

    public function test_push_before_a_reply_is_not_returned_as_the_reply(): void
    {
        $client = $this->client([[
            FakeRespServer::HELLO,
            ">2\r\n\$4\r\nnote\r\n\$1\r\nx\r\n+PONG\r\n",
        ]]);

        $this->assertSame('PONG', $client->command('PING'));
    }
}
