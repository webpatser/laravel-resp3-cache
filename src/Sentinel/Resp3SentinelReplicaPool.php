<?php declare(strict_types=1);

namespace Resp3\Laravel\Sentinel;

use Resp3\Laravel\Client\ConnectionException;
use Resp3\Laravel\Client\Resp3Client;
use Resp3\Laravel\Client\Resp3ClientInterface;
use Resp3\RedisException;

/**
 * Pool of lazy Resp3Client connections to Sentinel-discovered replicas.
 *
 * Routes one read command per call to a randomly picked replica. On socket
 * failure the failing replica is dropped and another is tried. When the pool
 * is empty (initial state, or every known replica failed) a fresh
 * SENTINEL replicas query refreshes the list. If that still yields nothing,
 * NoReplicasAvailableException is thrown so the connection layer can fall
 * back to the master client.
 *
 * Each new replica connection gets a one-shot READONLY so the server accepts
 * read commands; the pool tracks which addresses already have READONLY sent.
 */
final class Resp3SentinelReplicaPool
{
    /** @var array<string, Resp3ClientInterface> Keyed by canonical "host:port". */
    private array $clients = [];

    /** @var list<array{host:string,port:int}> Last discovered replica list. */
    private array $known = [];

    /** @var array<string, true> READONLY already sent on these addresses. */
    private array $readonlySent = [];

    private bool $discoveredOnce = false;

    /**
     * @param  array<string, mixed>  $clientOptions Forwarded to Resp3Client (username, password, database, tls, timeout, persistent, tlsOptions).
     */
    public function __construct(
        private readonly SentinelDiscovery $discovery,
        private readonly array $clientOptions = [],
        private readonly ?\Closure $clientFactory = null,
    ) {}

    public function command(string $name, mixed ...$args): mixed
    {
        $maxAttempts = max(count($this->known), 1) + 1;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $addr = $this->pickAddr();   // throws NoReplicasAvailableException if empty after refresh
            try {
                $client = $this->connectionFor($addr);
                return $client->command($name, ...$args);
            } catch (ConnectionException) {
                $this->drop($addr);
                $maxAttempts = max(count($this->known), 1) + 1;
                continue;
            }
        }
        throw new NoReplicasAvailableException(
            'All replica connections failed for ' . $maxAttempts . ' attempts'
        );
    }

    public function close(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
        $this->clients = [];
        $this->readonlySent = [];
    }

    /** Snapshot of the currently known replica addresses (for tests/diagnostics). */
    public function knownReplicas(): array
    {
        return $this->known;
    }

    // ------------------------------------------------------------------ internals

    private function pickAddr(): string
    {
        if ($this->known === []) {
            $this->refresh();
        }
        if ($this->known === []) {
            throw new NoReplicasAvailableException(
                "No healthy replicas reported by sentinel"
            );
        }
        $idx = array_rand($this->known);
        $node = $this->known[$idx];
        return "{$node['host']}:{$node['port']}";
    }

    private function refresh(): void
    {
        $this->discoveredOnce = true;
        $this->known = $this->discovery->discoverReplicas();
    }

    private function drop(string $addr): void
    {
        if (isset($this->clients[$addr])) {
            $this->clients[$addr]->close();
            unset($this->clients[$addr]);
        }
        unset($this->readonlySent[$addr]);
        foreach ($this->known as $i => $node) {
            if ("{$node['host']}:{$node['port']}" === $addr) {
                array_splice($this->known, $i, 1);
                break;
            }
        }
        // If the pool is now empty, force a refresh on the next pickAddr().
        // refresh() also runs when known === [] on the next call.
    }

    private function connectionFor(string $addr): Resp3ClientInterface
    {
        if (!isset($this->clients[$addr])) {
            [$host, $port] = explode(':', $addr, 2);
            $node = ['host' => $host, 'port' => (int) $port];
            $this->clients[$addr] = $this->openClient($node);
        }
        $client = $this->clients[$addr];
        if (!isset($this->readonlySent[$addr])) {
            $reply = $client->command('READONLY');
            if (!($reply instanceof RedisException)) {
                $this->readonlySent[$addr] = true;
            }
            // If READONLY itself errors (very unusual), let the next data
            // command surface the actual problem; do not poison the pool here.
        }
        return $client;
    }

    /** @param array{host:string,port:int} $node */
    private function openClient(array $node): Resp3ClientInterface
    {
        if ($this->clientFactory !== null) {
            return ($this->clientFactory)($node);
        }
        $opts = $this->clientOptions;
        return new Resp3Client(
            host: $node['host'],
            port: $node['port'],
            username:   $opts['username']   ?? null,
            password:   $opts['password']   ?? null,
            database:   (int) ($opts['database'] ?? 0),
            tls:        (bool) ($opts['tls'] ?? false),
            timeout:    (float) ($opts['timeout'] ?? 5.0),
            persistent: (bool) ($opts['persistent'] ?? false),
            tlsOptions: is_array($opts['tlsOptions'] ?? null) ? $opts['tlsOptions'] : [],
        );
    }
}
