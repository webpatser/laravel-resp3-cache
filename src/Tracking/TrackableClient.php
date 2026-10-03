<?php declare(strict_types=1);

namespace Resp3\Laravel\Tracking;

use Closure;
use Resp3\Laravel\Client\Resp3ClientInterface;

/**
 * Extra surface a client needs for client-side caching. Implemented by
 * Resp3Client and Resp3SentinelClient; kept apart from Resp3ClientInterface
 * so test doubles of that interface stay valid.
 */
interface TrackableClient
{
    /** The HELLO `id` of the live connection, null when not connected. */
    public function connectionId(): ?int;

    /**
     * Call $hook after every handshake (HELLO, AUTH, SELECT), before the
     * command that caused the connect is written, with the client that owns
     * the new socket. When a connection is already open the hook runs once
     * right away. The hook may issue commands on that client.
     *
     * @param  (Closure(Resp3ClientInterface): void)|null  $hook
     */
    public function onConnect(?Closure $hook): void;

    /** Whether the socket is opened with STREAM_CLIENT_PERSISTENT. */
    public function isPersistent(): bool;

    /**
     * Listener for push frames read during command() and pipeline(), kept
     * apart from the user's setPushListener(). Pushes are collected when
     * either listener is set; on flush every push goes to this listener
     * first, then to the user's.
     *
     * @param  (Closure(\Resp3\PushMessage): void)|null  $listener
     */
    public function setTrackingListener(?Closure $listener): void;
}
