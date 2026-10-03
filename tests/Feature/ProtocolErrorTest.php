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
        $this->stopNonceServer();
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

    public function test_stray_reply_before_the_hello_reply_on_a_persistent_socket_reconnects_once(): void
    {
        // The handshake of a persistent socket is HELLO, SELECT and PING
        // <nonce> in one write. The first socket answers with a stray reply
        // ahead of the HELLO map (the tail of a push cut off when the
        // previous request ended); the client must drop it and redo the
        // handshake on a fresh socket.
        $port = $this->startNonceServer([
            ["+STRAY\r\n" . FakeRespServer::HELLO . "+OK\r\n{nonce}"],
            [FakeRespServer::HELLO . "+OK\r\n{nonce}", "+PONG\r\n"],
        ]);
        $client = $this->persistentClient($port);

        $this->assertSame('PONG', $client->command('PING'));
        $this->assertSame(2, substr_count($this->nonceServerReceived(), "HELLO\r\n"), 'one reconnect');
        $client->close();
    }

    public function test_push_queued_before_the_hello_reply_is_not_out_of_step(): void
    {
        // Pushes waiting on a reused socket (tracking invalidations) are not
        // replies: they reach the listener and the socket is kept.
        $port = $this->startNonceServer([
            [">2\r\n\$4\r\nnote\r\n\$1\r\nx\r\n" . FakeRespServer::HELLO . "+OK\r\n{nonce}", "+PONG\r\n"],
        ]);
        $client = $this->persistentClient($port);
        $pushes = [];
        $client->setTrackingListener(function (PushMessage $push) use (&$pushes): void {
            $pushes[] = $push;
        });

        $this->assertSame('PONG', $client->command('PING'));
        $this->assertCount(1, $pushes);
        $this->assertSame(1, substr_count($this->nonceServerReceived(), "HELLO\r\n"), 'no reconnect');
        $client->close();
    }

    public function test_persistent_socket_out_of_step_twice_throws(): void
    {
        $port = $this->startNonceServer([
            ["+STRAY\r\n" . FakeRespServer::HELLO . "+OK\r\n{nonce}"],
            ["+STRAY\r\n" . FakeRespServer::HELLO . "+OK\r\n{nonce}"],
        ]);
        $client = $this->persistentClient($port);

        try {
            $client->command('PING');
            $this->fail('A handshake out of step twice did not throw');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('HELLO failed', $e->getMessage());
        }
        $this->assertFalse($client->isConnected());
    }

    // ------------------------------------------------------------------ nonce-echo server

    /** @var resource|null */
    private $nonceProcess = null;

    /** @var array<int, resource> */
    private array $noncePipes = [];

    private ?string $nonceScript = null;

    private ?string $nonceLog = null;

    private function persistentClient(int $port): Resp3Client
    {
        return new Resp3Client(
            host: '127.0.0.1',
            port: $port,
            timeout: 3.0,
            persistent: true,
            persistentId: 'protocol-' . bin2hex(random_bytes(6)),
        );
    }

    /**
     * A scripted server like FakeRespServer (one reply per chunk received,
     * one list of replies per connection) that also replaces `{nonce}` in a
     * reply with the bulk string the client sent as the PING argument in
     * that chunk, which the persistent handshake needs echoed.
     *
     * @param  list<list<string>>  $connections
     */
    private function startNonceServer(array $connections): int
    {
        $this->nonceScript = tempnam(sys_get_temp_dir(), 'r3-nonce-');
        $this->nonceLog = tempnam(sys_get_temp_dir(), 'r3-noncelog-');
        file_put_contents($this->nonceScript, <<<'PHP'
        <?php
        $connections = json_decode(base64_decode($argv[1]), true);
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) { fwrite(STDERR, "bind failed: $errstr\n"); exit(1); }
        $name = stream_socket_get_name($server, false);
        echo substr($name, strrpos($name, ':') + 1), "\n";
        fflush(STDOUT);

        $deadline = time() + 15;
        foreach ($connections as $replies) {
            $client = @stream_socket_accept($server, max(1, $deadline - time()));
            if ($client === false) { exit(2); }
            foreach ($replies as $reply) {
                $chunk = fread($client, 65536);
                if ($chunk === '' || $chunk === false) { break; }
                file_put_contents($argv[2], $chunk, FILE_APPEND);
                if (preg_match('/PING\r\n\$\d+\r\n([0-9a-f]+)\r\n/', $chunk, $m)) {
                    $reply = str_replace('{nonce}', '$' . strlen($m[1]) . "\r\n" . $m[1] . "\r\n", $reply);
                }
                fwrite($client, $reply);
            }
            stream_set_timeout($client, max(1, $deadline - time()));
            while (($chunk = fread($client, 65536)) !== '' && $chunk !== false) {}
            fclose($client);
        }
        PHP);

        $this->nonceProcess = proc_open(
            [PHP_BINARY, $this->nonceScript, base64_encode(json_encode($connections, JSON_THROW_ON_ERROR)), $this->nonceLog],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->noncePipes,
        );
        $line = is_resource($this->nonceProcess) ? fgets($this->noncePipes[1]) : false;
        if ($line === false || !ctype_digit(trim($line))) {
            $this->fail('Nonce server did not report a port');
        }

        return (int) trim($line);
    }

    private function nonceServerReceived(): string
    {
        clearstatcache(true, (string) $this->nonceLog);

        return (string) @file_get_contents((string) $this->nonceLog);
    }

    private function stopNonceServer(): void
    {
        if (is_resource($this->nonceProcess)) {
            @proc_terminate($this->nonceProcess);
            foreach ($this->noncePipes as $pipe) {
                if (is_resource($pipe)) {
                    @fclose($pipe);
                }
            }
            @proc_close($this->nonceProcess);
        }
        $this->nonceProcess = null;
        foreach ([$this->nonceScript, $this->nonceLog] as $path) {
            if ($path !== null && file_exists($path)) {
                @unlink($path);
            }
        }
    }
}
