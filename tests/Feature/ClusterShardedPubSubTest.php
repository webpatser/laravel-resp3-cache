<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\TestCase;
use Resp3\Laravel\Resp3ServiceProvider;

/**
 * Live sharded pub/sub round trip against the local 6-node Valkey cluster
 * (3 masters on 7100-7102, 3 replicas on 7103-7105). The subscriber blocks,
 * so we run it in a child process via proc_open and SPUBLISH from the
 * parent. The child writes the received message to stdout, the parent
 * reads and asserts.
 */
final class ClusterShardedPubSubTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [Resp3ServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.redis', [
            'client' => 'resp3',
            'options' => ['cluster' => 'redis'],
            'clusters' => [
                'default' => [
                    ['host' => '127.0.0.1', 'port' => 7100],
                    ['host' => '127.0.0.1', 'port' => 7101],
                    ['host' => '127.0.0.1', 'port' => 7102],
                ],
                'options' => ['timeout' => 2],
            ],
        ]);
    }

    protected function setUp(): void
    {
        if (!extension_loaded('resp3')) {
            $this->markTestSkipped('ext-resp3 is not loaded');
        }
        if (!@fsockopen('127.0.0.1', 7100, $_, $_, 0.5)) {
            $this->markTestSkipped('No cluster reachable on 127.0.0.1:7100 (run make cluster-up)');
        }
        parent::setUp();
    }

    public function test_ssubscribe_receives_spublish(): void
    {
        $tag     = bin2hex(random_bytes(4));
        $channel = "orders.{{$tag}}.created";
        $payload = 'sharded-' . bin2hex(random_bytes(4));

        $child = $this->startSubscriberChild($channel);
        $this->waitForReady($child['stdout']);

        $delivered = Redis::connection()->command('SPUBLISH', [$channel, $payload]);
        $this->assertGreaterThanOrEqual(1, $delivered, 'SPUBLISH should report at least one subscriber');

        $line = $this->readChildLine($child['stdout'], timeout: 3.0);
        $this->assertStringContainsString($payload, $line);
        $this->assertStringContainsString($channel, $line);

        $this->stopChild($child);
    }

    public function test_cross_slot_channels_rejected_with_hash_tag_hint(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/CROSSSLOT.*hash tag/i');

        Redis::connection()->ssubscribe(
            ['no-tag-a-' . str_repeat('x', 8), 'no-tag-b-' . str_repeat('y', 8)],
            fn () => null,
        );
    }

    public function test_regular_subscribe_still_blocked_with_ssubscribe_hint(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessageMatches('/ssubscribe/i');

        Redis::connection()->subscribe(['anything'], fn () => null);
    }

    /** @return array{proc:resource,stdout:resource,stderr:resource,tmp:string} */
    private function startSubscriberChild(string $channel): array
    {
        $script = $this->subscriberScript($channel);
        $tmp    = tempnam(sys_get_temp_dir(), 'r3-shard-sub-');
        file_put_contents($tmp, $script);

        $extPath = '/Users/christoph/Development/Github/php-resp3/modules/resp3.so';
        $cmd = ['php', '-d', "extension={$extPath}", $tmp];

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

    /**
     * Subscriber child resolves the channel's master via CLUSTER SHARDS, opens
     * a dedicated subscriber socket on it, and runs SubscriptionLoop with
     * method='ssubscribe'. We bypass the Laravel boot for the child to keep
     * it lean.
     */
    private function subscriberScript(string $channel): string
    {
        $chEsc = var_export($channel, true);
        return <<<PHP
        <?php
        require '/Users/christoph/Development/Github/laravel-resp3-cache/vendor/autoload.php';

        use Resp3\\Laravel\\Client\\Resp3Client;
        use Resp3\\Laravel\\Cluster\\CRC16;
        use Resp3\\Laravel\\Cluster\\Resp3ClusterRouter;
        use Resp3\\Laravel\\PubSub\\SubscriptionLoop;

        \$router = new Resp3ClusterRouter(
            seedNodes: [
                ['host' => '127.0.0.1', 'port' => 7100],
                ['host' => '127.0.0.1', 'port' => 7101],
                ['host' => '127.0.0.1', 'port' => 7102],
            ],
            timeout: 2.0,
        );
        \$addr = \$router->subscriberAddrForChannel({$chEsc});
        [\$host, \$port] = explode(':', \$addr, 2);

        \$client = new Resp3Client(host: \$host, port: (int) \$port, timeout: 0.0);
        \$client->command('PING');
        echo "READY\\n";
        @fflush(STDOUT);

        \$loop = new SubscriptionLoop(\$client, [{$chEsc}], function (\$msg, \$ch) {
            echo json_encode(['msg' => \$msg, 'ch' => \$ch]) . "\\n";
            @fflush(STDOUT);
            return false;
        }, 'ssubscribe');

        \$loop->run();
        PHP;
    }

    /** @param resource $stream */
    private function waitForReady($stream): void
    {
        $line = $this->readChildLine($stream, timeout: 3.0);
        $this->assertSame('READY', trim($line), "Sharded subscriber child did not signal READY");
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
