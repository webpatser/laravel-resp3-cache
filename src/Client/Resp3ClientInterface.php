<?php declare(strict_types=1);

namespace Resp3\Laravel\Client;

/**
 * Minimal contract a sync RESP client must satisfy for the rest of the
 * package to drive it. Resp3Client implements this; tests can swap in a
 * stub without subclassing the (final) concrete class.
 */
interface Resp3ClientInterface
{
    /** Send a command and return the parsed reply. */
    public function command(string $name, mixed ...$args): mixed;

    /** Block on the socket until one complete reply or push frame arrives. */
    public function readNext(): mixed;

    public function close(): void;

    public function isConnected(): bool;
}
