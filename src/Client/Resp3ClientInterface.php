<?php declare(strict_types=1);

namespace Resp3\Laravel\Client;

use Closure;
use Resp3\PushMessage;

/**
 * Minimal contract a sync RESP client must satisfy for the rest of the
 * package to drive it. Resp3Client implements this; tests can swap in a
 * stub without subclassing the (final) concrete class.
 *
 * Server errors (`-` and `!` replies) come back as Resp3\RedisException
 * values, never thrown, so callers can route on MOVED, ASK or READONLY.
 * Wire faults throw ConnectionException and drop the connection.
 */
interface Resp3ClientInterface
{
    /** Send a command and return the parsed reply. */
    public function command(string $name, mixed ...$args): mixed;

    /**
     * Write a command without reading a reply. Used for commands whose
     * answer arrives as a push frame (SUBSCRIBE and friends in RESP3).
     */
    public function send(string $name, mixed ...$args): void;

    /**
     * Block on the socket until one push frame or regular reply arrives.
     * A queued push is returned before any further bytes are read.
     */
    public function readNext(): mixed;

    /**
     * Send a batch of commands in one round trip and return the replies.
     *
     * @param  list<list<string>>  $commands  Each inner list: [name, ...args]
     * @return list<mixed>
     */
    public function pipeline(array $commands): array;

    /**
     * Collect every push frame the server has already sent, without
     * blocking. Use between commands, never with a command outstanding.
     *
     * @return list<PushMessage>
     */
    public function drainPushes(): array;

    /**
     * Receive the push frames that arrive while command() or pipeline()
     * waits for its replies. Called once per push after the replies are
     * read; without a listener those pushes are dropped. The listener must
     * not issue commands on this client.
     *
     * @param  (Closure(PushMessage): void)|null  $listener
     */
    public function setPushListener(?Closure $listener): void;

    /** Server identity and optional command support from the HELLO reply. */
    public function capabilities(): ServerCapabilities;

    public function close(): void;

    public function isConnected(): bool;
}
