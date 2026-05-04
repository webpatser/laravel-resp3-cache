<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Resp3\Laravel\Client\Resp3ClientInterface;
use Resp3\Laravel\PubSub\SubscriptionLoop;

/**
 * SubscriptionLoop's sharded pub/sub support: ssubscribe is a valid method,
 * smessage frames dispatch like message frames, and SUNSUBSCRIBE runs on
 * cleanup.
 */
final class SubscriptionLoopShardedTest extends TestCase
{
    public function test_ssubscribe_method_is_accepted(): void
    {
        $client = new SharedFakeClient();
        $loop = new SubscriptionLoop($client, ['ch'], fn () => false, 'ssubscribe');
        // No exception means construction passed validation.
        $this->assertInstanceOf(SubscriptionLoop::class, $loop);
    }

    public function test_smessage_frame_dispatches_to_callback(): void
    {
        $received = null;
        $client = new SharedFakeClient(replies: [
            ['smessage', 'orders.{u42}', 'payload-here'],
        ]);
        $loop = new SubscriptionLoop(
            $client,
            ['orders.{u42}'],
            function ($msg, $ch) use (&$received) {
                $received = ['msg' => $msg, 'ch' => $ch];
                return false;   // exit loop after first message
            },
            'ssubscribe',
        );
        $loop->run();

        $this->assertSame(['msg' => 'payload-here', 'ch' => 'orders.{u42}'], $received);
    }

    public function test_cleanup_sends_sunsubscribe_for_ssubscribe(): void
    {
        $client = new SharedFakeClient(replies: [
            ['smessage', 'ch', 'm'],   // first frame triggers exit
        ]);
        $loop = new SubscriptionLoop($client, ['ch'], fn () => false, 'ssubscribe');
        $loop->run();

        $sent = array_map(fn ($c) => $c[0], $client->commandsSent);
        $this->assertContains('SSUBSCRIBE', $sent);
        $this->assertContains('SUNSUBSCRIBE', $sent);
    }

    public function test_unknown_method_still_rejected(): void
    {
        $client = new SharedFakeClient();
        $this->expectException(\InvalidArgumentException::class);
        new SubscriptionLoop($client, ['ch'], fn () => null, 'xsubscribe');
    }
}

/** Records every command and serves a queue of replies on readNext(). */
final class SharedFakeClient implements Resp3ClientInterface
{
    /** @var list<array> */
    public array $commandsSent = [];
    public bool $closed = false;

    /** @param list<mixed> $replies */
    public function __construct(public array $replies = []) {}

    public function command(string $name, mixed ...$args): mixed
    {
        $this->commandsSent[] = [strtoupper($name), ...$args];
        return null;
    }

    public function readNext(): mixed
    {
        return array_shift($this->replies);
    }

    public function pipeline(array $commands): array { return []; }

    public function close(): void { $this->closed = true; }
    public function isConnected(): bool { return !$this->closed; }
}
