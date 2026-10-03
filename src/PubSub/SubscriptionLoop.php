<?php declare(strict_types=1);

namespace Resp3\Laravel\PubSub;

use Closure;
use Resp3\Laravel\Client\Resp3ClientInterface;
use Resp3\Laravel\Client\ServerException;
use Resp3\PushMessage;
use Resp3\RedisException;

/**
 * Blocking subscribe loop that drives a dedicated Resp3Client.
 *
 * Sends SUBSCRIBE or PSUBSCRIBE on construction-time-supplied channels,
 * then reads server-pushed message frames until the callback returns false,
 * SIGTERM/SIGINT arrives (when the pcntl extension is loaded), or the
 * underlying connection drops. UNSUBSCRIBE + close runs on every exit path.
 */
final class SubscriptionLoop
{
    private bool $stop = false;

    /**
     * @param  list<string>  $channels  Channel names (or patterns for psubscribe).
     */
    public function __construct(
        private readonly Resp3ClientInterface $client,
        private readonly array $channels,
        private readonly Closure $callback,
        private readonly string $method,
    ) {
        if (!in_array(strtolower($method), ['subscribe', 'psubscribe', 'ssubscribe'], true)) {
            throw new \InvalidArgumentException("Unknown subscribe method: {$method}");
        }
        if ($channels === []) {
            throw new \InvalidArgumentException('At least one channel is required');
        }
    }

    public function run(): void
    {
        $this->installSignalHandlers();
        $sub = strtoupper($this->method);

        try {
            // Write only: in RESP3 the subscribe confirmation is itself a
            // push frame, so it arrives through readNext() below and is
            // skipped there. command() would wait for a regular reply forever.
            $this->client->send($sub, ...$this->channels);

            while (!$this->stop) {
                $this->dispatchSignals();

                $reply = $this->client->readNext();

                // An error reply (NOPERM on SUBSCRIBE, MOVED on SSUBSCRIBE)
                // means no frames will ever arrive: surface it, do not spin.
                if ($reply instanceof RedisException) {
                    throw new ServerException($reply);
                }

                $payload = $this->extractPayload($reply);
                if ($payload === null) continue;

                $kind = is_string($payload[0] ?? null) ? strtolower($payload[0]) : null;

                if ($kind === 'message' || $kind === 'smessage') {
                    // [message|smessage, channel, payload]
                    $result = ($this->callback)($payload[2] ?? '', $payload[1] ?? '');
                    if ($result === false) break;
                } elseif ($kind === 'pmessage') {
                    // [pmessage, pattern, channel, payload]
                    $result = ($this->callback)(
                        $payload[3] ?? '',
                        $payload[2] ?? '',
                        $payload[1] ?? '',
                    );
                    if ($result === false) break;
                }
                // Subscribe / unsubscribe acks are silently ignored.
            }
        } finally {
            $this->cleanup();
        }
    }

    /** Mostly for tests; lets a caller stop the loop cooperatively. */
    public function stop(): void
    {
        $this->stop = true;
    }

    // ------------------------------------------------------------------ private

    /**
     * readNext() yields PushMessage frames; the plain array branch keeps
     * unit-test stubs that hand back raw arrays working.
     *
     * @return list<mixed>|null
     */
    private function extractPayload(mixed $reply): ?array
    {
        if ($reply instanceof PushMessage)  return $reply->payload;
        if (is_array($reply))                return $reply;
        // Scalars, null: not a pub/sub frame, skip.
        return null;
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) return;

        $stop = function (): void {
            $this->stop = true;
        };

        if (defined('SIGTERM')) pcntl_signal(SIGTERM, $stop);
        if (defined('SIGINT'))  pcntl_signal(SIGINT,  $stop);
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }
    }

    private function dispatchSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }

    private function cleanup(): void
    {
        try {
            $unsub = match (strtolower($this->method)) {
                'subscribe'  => 'UNSUBSCRIBE',
                'psubscribe' => 'PUNSUBSCRIBE',
                'ssubscribe' => 'SUNSUBSCRIBE',
            };
            // Write only, for the same reason as the subscribe in run().
            $this->client->send($unsub);
        } catch (\Throwable) {
            // Socket may already be dead; we are tearing down anyway.
        }
        $this->client->close();
    }
}
