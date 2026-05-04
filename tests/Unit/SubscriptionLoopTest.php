<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Resp3\Laravel\Client\Resp3ClientInterface;
use Resp3\Laravel\PubSub\SubscriptionLoop;

/**
 * Drives SubscriptionLoop with a stub client that returns canned replies
 * for command() and a queue of pre-built frames for readNext().
 *
 * Frames are plain arrays (the loop accepts both Resp3\PushMessage and raw
 * arrays); using arrays here keeps unit tests free of the ext-resp3 load.
 */
final class SubscriptionLoopTest extends TestCase
{
    public function test_message_dispatch(): void
    {
        $client = new StubClient(replies: [
            ['subscribe', 'ch1', 1],
            ['message', 'ch1', 'hello'],
            ['message', 'ch1', 'world'],
        ]);
        $messages = [];
        $loop = new SubscriptionLoop($client, ['ch1'], function ($msg, $ch) use (&$messages) {
            $messages[] = [$msg, $ch];
            if (count($messages) === 2) return false;
            return null;
        }, 'subscribe');

        $loop->run();

        $this->assertSame([['hello', 'ch1'], ['world', 'ch1']], $messages);
        $this->assertSame(['SUBSCRIBE', 'UNSUBSCRIBE'], $client->commandsSent);
    }

    public function test_pmessage_dispatch_includes_pattern(): void
    {
        $client = new StubClient(replies: [
            ['psubscribe', 'user.*', 1],
            ['pmessage', 'user.*', 'user.42', 'payload'],
        ]);
        $captured = null;
        $loop = new SubscriptionLoop($client, ['user.*'], function ($msg, $ch, $pattern) use (&$captured) {
            $captured = [$msg, $ch, $pattern];
            return false;
        }, 'psubscribe');

        $loop->run();

        $this->assertSame(['payload', 'user.42', 'user.*'], $captured);
        $this->assertSame(['PSUBSCRIBE', 'PUNSUBSCRIBE'], $client->commandsSent);
    }

    public function test_subscribe_acks_are_ignored(): void
    {
        $client = new StubClient(replies: [
            ['subscribe', 'a', 1],
            ['subscribe', 'b', 2],
            ['message', 'a', 'first'],
        ]);
        $count = 0;
        $loop = new SubscriptionLoop($client, ['a', 'b'], function () use (&$count) {
            $count++;
            return false;
        }, 'subscribe');

        $loop->run();

        $this->assertSame(1, $count, 'callback only fires for message frames, not for subscribe acks');
    }

    public function test_callback_returning_false_exits_with_unsubscribe(): void
    {
        $client = new StubClient(replies: [
            ['subscribe', 'ch1', 1],
            ['message', 'ch1', 'hi'],
        ]);
        $loop = new SubscriptionLoop($client, ['ch1'], fn () => false, 'subscribe');

        $loop->run();

        $this->assertContains('UNSUBSCRIBE', $client->commandsSent);
        $this->assertTrue($client->closed);
    }

    public function test_invalid_method_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SubscriptionLoop(new StubClient([]), ['ch'], fn () => null, 'sniffsniff');
    }

    public function test_empty_channel_list_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SubscriptionLoop(new StubClient([]), [], fn () => null, 'subscribe');
    }
}

/** Test double implementing Resp3ClientInterface. */
final class StubClient implements Resp3ClientInterface
{
    /** @var list<string> */
    public array $commandsSent = [];
    public bool $closed = false;

    /** @param  list<mixed>  $replies */
    public function __construct(public array $replies) {}

    public function command(string $name, mixed ...$args): mixed
    {
        $this->commandsSent[] = strtoupper($name);
        return 'OK';
    }

    public function readNext(): mixed
    {
        return array_shift($this->replies);
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function isConnected(): bool
    {
        return !$this->closed;
    }
}
