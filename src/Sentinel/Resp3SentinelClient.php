<?php declare(strict_types=1);

namespace Resp3\Laravel\Sentinel;

use Resp3\Laravel\Client\ConnectionException;
use Resp3\Laravel\Client\Resp3Client;
use Resp3\Laravel\Client\Resp3ClientInterface;
use Resp3\RedisException;



/**
 * Sentinel-aware client. Looks like a Resp3Client to the rest of the
 * package but transparently re-discovers the current master and
 * reconnects when the data-plane connection breaks or the master is
 * demoted to a replica.
 */
final class Resp3SentinelClient implements Resp3ClientInterface
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

        if ($reply instanceof RedisException && str_starts_with($reply->getMessage(), 'READONLY ')) {
            // Master was demoted gracefully; the connection still works but
            // server refuses writes. Rediscover and retry once.
            $this->reset();
            return $this->client()->command($name, ...$args);
        }

        return $reply;
    }

    public function readNext(): mixed
    {
        return $this->client()->readNext();
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

    private function client(): Resp3ClientInterface
    {
        if ($this->current?->isConnected()) {
            return $this->current;
        }

        $addr = $this->discovery->discoverMaster();
        $this->currentAddr = $addr;

        if ($this->clientFactory !== null) {
            $this->current = ($this->clientFactory)($addr);
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
        );
        return $this->current;
    }

    private function reset(): void
    {
        $this->current?->close();
        $this->current = null;
    }
}
