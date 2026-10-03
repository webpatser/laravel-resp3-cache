<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\TestCase;
use Resp3\Laravel\Tests\Support\Env;
use Resp3\Laravel\Resp3ServiceProvider;

/**
 * Live publish/subscribe round trip against the local Redis or Valkey.
 *
 * The subscriber blocks, so we run it in a child process via proc_open
 * and have the parent publish a message after a short startup delay.
 * The child writes the received message to stdout, the parent reads and
 * asserts.
 */
final class PubSubTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [Resp3ServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.redis', [
            'client' => 'resp3',
            'default' => [
                'host' => Env::host(),
                'port' => Env::port(),
                'database' => 0,
            ],
        ]);
    }

    protected function setUp(): void
    {
        if (!extension_loaded('resp3')) {
            $this->markTestSkipped('ext-resp3 is not loaded');
        }
        if (!Env::reachable()) {
            $this->markTestSkipped('No Redis or Valkey reachable on ' . Env::address());
        }
        parent::setUp();
    }

    public function test_subscribe_receives_published_message(): void
    {
        $channel = 'r3-pubsub-test-' . bin2hex(random_bytes(4));
        $payload = 'hello-' . bin2hex(random_bytes(4));

        $child = $this->startSubscriberChild($channel, mode: 'subscribe');

        // Wait for the child to send "READY\n" to stdout, then publish.
        $this->waitForReady($child['stdout']);

        $delivered = Redis::connection()->command('PUBLISH', [$channel, $payload]);
        $this->assertGreaterThanOrEqual(1, $delivered, 'PUBLISH should report at least one subscriber');

        $line = $this->readChildLine($child['stdout'], timeout: 3.0);
        $this->assertStringContainsString($payload, $line);
        $this->assertStringContainsString($channel, $line);

        $this->stopChild($child);
    }

    public function test_psubscribe_pattern_match(): void
    {
        $prefix  = 'r3-pat-' . bin2hex(random_bytes(4));
        $pattern = $prefix . '.*';
        $channel = $prefix . '.greet';
        $payload = 'world';

        $child = $this->startSubscriberChild($pattern, mode: 'psubscribe');
        $this->waitForReady($child['stdout']);

        Redis::connection()->command('PUBLISH', [$channel, $payload]);

        $line = $this->readChildLine($child['stdout'], timeout: 3.0);
        $this->assertStringContainsString($payload, $line);
        $this->assertStringContainsString($channel, $line);
        $this->assertStringContainsString($pattern, $line);

        $this->stopChild($child);
    }

    /** @return array{proc:resource,stdout:resource,stderr:resource} */
    private function startSubscriberChild(string $channelOrPattern, string $mode): array
    {
        $script = $this->subscriberScript($channelOrPattern, $mode);
        $tmp    = tempnam(sys_get_temp_dir(), 'r3-sub-');
        file_put_contents($tmp, $script);

        // The test process already loaded ext-resp3; the child inherits the
        // same ini, so loading it again would print a warning on stdout.
        $cmd = [PHP_BINARY, $tmp];

        $proc = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return ['proc' => $proc, 'stdout' => $pipes[1], 'stderr' => $pipes[2], 'tmp' => $tmp];
    }

    private function subscriberScript(string $arg, string $mode): string
    {
        $argEsc = var_export($arg, true);
        $modeEsc = var_export($mode, true);
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $host = Env::host();
        $port = Env::port();
        return <<<PHP
        <?php
        require '{$autoload}';

        use Resp3\\Laravel\\Client\\Resp3Client;
        use Resp3\\Laravel\\PubSub\\SubscriptionLoop;

        \$client = new Resp3Client(host: '{$host}', port: {$port}, timeout: 0.0);
        // Open the connection up front so the parent's PUBLISH after READY hits a real subscriber.
        \$client->command('PING');
        echo "READY\\n";
        @fflush(STDOUT);

        \$loop = new SubscriptionLoop(\$client, [{$argEsc}], function (\$msg, \$ch, \$pattern = null) {
            echo json_encode(['msg' => \$msg, 'ch' => \$ch, 'pattern' => \$pattern]) . "\\n";
            @fflush(STDOUT);
            return false;
        }, {$modeEsc});

        \$loop->run();
        PHP;
    }

    /** @param resource $stream */
    private function waitForReady($stream): void
    {
        $line = $this->readChildLine($stream, timeout: 3.0);
        $this->assertSame('READY', trim($line), "Subscriber child did not signal READY in time");
    }

    /** @param resource $stream */
    private function readChildLine($stream, float $timeout): string
    {
        $deadline = microtime(true) + $timeout;
        $buffer = '';
        while (microtime(true) < $deadline) {
            $chunk = fread($stream, 1024);
            if ($chunk === '' || $chunk === false) {
                usleep(20_000);
                continue;
            }
            $buffer .= $chunk;
            if (str_contains($buffer, "\n")) {
                $line = strstr($buffer, "\n", true);
                return $line ?: '';
            }
        }
        $this->fail("Timed out waiting for child output. Buffer so far: " . var_export($buffer, true));
    }

    private function stopChild(array $child): void
    {
        if (is_resource($child['proc'])) {
            @proc_terminate($child['proc']);
            @proc_close($child['proc']);
        }
        if (isset($child['tmp']) && file_exists($child['tmp'])) {
            @unlink($child['tmp']);
        }
    }
}
