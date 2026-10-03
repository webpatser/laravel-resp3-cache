<?php declare(strict_types=1);

namespace Resp3\Laravel\Sentinel;

use Closure;
use Resp3\Laravel\Client\ConnectionException;
use Resp3\Laravel\Client\Resp3Client;
use Resp3\Laravel\Client\Resp3ClientInterface;
use Resp3\Laravel\Client\ServerCapabilities;
use Resp3\Laravel\Tracking\TrackableClient;
use Resp3\RedisException;



/**
 * Sentinel-aware client. Looks like a Resp3Client to the rest of the
 * package but transparently re-discovers the current master and
 * reconnects when the data-plane connection breaks or the master is
 * demoted to a replica.
 */
final class Resp3SentinelClient implements Resp3ClientInterface, TrackableClient
{
    private ?Resp3ClientInterface $current = null;

    public function __construct(
        private readonly SentinelDiscovery $discovery,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly int $database = 0,
        private readonly bool $tls = false,
        private readonly float $timeout = 5.0,
        private readonly bool $persistent = false,
        private readonly array $tlsOptions = [],
        private readonly ?\Closure $clientFactory = null,
        private readonly array $features = [],
        private readonly string $persistentId = '',
    ) {}

    public function command(string $name, mixed ...$args): mixed
    {
        try {
            $reply = $this->client()->command($name, ...$args);
        } catch (ConnectionException) {
            // Hard socket failure: discover a fresh master and retry once.
            $this->reset();
            return $this->client()->command($name, ...$args);
        }

        if ($reply instanceof RedisException && in_array($reply->prefix, ['READONLY', 'MASTERDOWN'], true)) {
            // Master was demoted (READONLY) or a replica lost its master
            // (MASTERDOWN); the socket still works but the node cannot serve
            // the command. Rediscover and retry once.
            $this->reset();
            return $this->client()->command($name, ...$args);
        }

        return $reply;
    }

    public function readNext(): mixed
    {
        return $this->client()->readNext();
    }

    public function send(string $name, mixed ...$args): void
    {
        try {
            $this->client()->send($name, ...$args);
        } catch (ConnectionException) {
            $this->reset();
            $this->client()->send($name, ...$args);
        }
    }

    public function drainPushes(): array
    {
        // A fresh connection has no pushes; never discover just to drain.
        return $this->current?->isConnected() ? $this->current->drainPushes() : [];
    }

    public function setPushListener(?Closure $listener): void
    {
        $this->pushListener = $listener;
        $this->current?->setPushListener($listener);
    }

    /** Follows the master like the push listener: every client built after a failover gets it too. */
    public function setTrackingListener(?Closure $listener): void
    {
        $this->trackingListener = $listener;
        if ($this->current instanceof TrackableClient) {
            $this->current->setTrackingListener($listener);
        }
    }

    public function capabilities(): ServerCapabilities
    {
        return $this->client()->capabilities();
    }

    public function connectionId(): ?int
    {
        return $this->current instanceof TrackableClient && $this->current->isConnected()
            ? $this->current->connectionId()
            : null;
    }

    /** The hook follows the master: every client built after a failover gets it too. */
    public function onConnect(?Closure $hook): void
    {
        $this->connectHook = $hook;
        if ($this->current instanceof TrackableClient) {
            $this->current->onConnect($hook);
        }
    }

    public function isPersistent(): bool
    {
        return $this->persistent;
    }

    public function pipeline(array $commands): array
    {
        try {
            return $this->client()->pipeline($commands);
        } catch (ConnectionException) {
            $this->reset();
            return $this->client()->pipeline($commands);
        }
    }

    public function close(): void
    {
        $this->current?->close();
        $this->current = null;
    }

    public function isConnected(): bool
    {
        return $this->current?->isConnected() ?? false;
    }

    /** Expose the active master address for tests and diagnostics. */
    public function currentMaster(): ?array
    {
        return $this->currentAddr;
    }

    private ?array $currentAddr = null;

    private ?Closure $pushListener = null;

    private ?Closure $trackingListener = null;

    private ?Closure $connectHook = null;

    private function client(): Resp3ClientInterface
    {
        if ($this->current?->isConnected()) {
            return $this->current;
        }

        $addr = $this->discovery->discoverMaster();
        $this->currentAddr = $addr;

        if ($this->clientFactory !== null) {
            $this->current = ($this->clientFactory)($addr);
            $this->current->setPushListener($this->pushListener);
            if ($this->current instanceof TrackableClient) {
                // Listener before hook: pushes read during the hook's
                // commands must reach client-side caching.
                if ($this->trackingListener !== null) {
                    $this->current->setTrackingListener($this->trackingListener);
                }
                if ($this->connectHook !== null) {
                    $this->current->onConnect($this->connectHook);
                }
            }
            return $this->current;
        }

        $this->current = new Resp3Client(
            host: $addr['host'],
            port: $addr['port'],
            username: $this->username,
            password: $this->password,
            database: $this->database,
            tls: $this->tls,
            timeout: $this->timeout,
            persistent: $this->persistent,
            tlsOptions: $this->tlsOptions,
            features: $this->features,
            persistentId: $this->persistentId,
        );
        $this->current->setPushListener($this->pushListener);
        $this->current->setTrackingListener($this->trackingListener);
        if ($this->connectHook !== null) {
            $this->current->onConnect($this->connectHook);
        }
        return $this->current;
    }

    private function reset(): void
    {
        $this->current?->close();
        $this->current = null;
    }
}
