<?php declare(strict_types=1);

namespace Resp3\Laravel\Sentinel;

use Resp3\Laravel\Client\ConnectionException;
use Resp3\Laravel\Client\Resp3Client;
use Resp3\Laravel\Client\Resp3ClientInterface;
use Resp3\RedisException;

/**
 * Discovers the current master address for a Sentinel-managed Redis service.
 *
 * Tries seed sentinels in round-robin order; the first successful reply
 * wins. A seed that fails (connection error or sentinel error reply) is
 * rotated to the back of the queue so a dead sentinel does not block every
 * future discovery attempt.
 *
 * Discovery is only invoked on the first command and after a connection
 * failure on the data plane, so the per-call cost is acceptable: one short
 * RTT to a sentinel.
 */
final class SentinelDiscovery
{
    /** @var list<array{host:string,port:int}> */
    private array $seeds;

    /**
     * @param  list<array{host:string,port:int}>  $seeds
     */
    public function __construct(
        array $seeds,
        private readonly string $service,
        private readonly ?string $sentinelPassword = null,
        private readonly float $timeout = 1.0,
        private readonly ?\Closure $clientFactory = null,
    ) {
        if ($seeds === []) {
            throw new \InvalidArgumentException('At least one seed sentinel is required');
        }
        if ($service === '') {
            throw new \InvalidArgumentException('Sentinel service name cannot be empty');
        }
        $this->seeds = array_values($seeds);
    }

    /**
     * @return array{host:string,port:int}
     * @throws ConnectionException
     */
    public function discoverMaster(): array
    {
        $errors = [];
        $count  = count($this->seeds);

        for ($i = 0; $i < $count; $i++) {
            $seed = $this->seeds[0];
            try {
                $client = $this->openSentinel($seed);
                $reply  = $client->command('SENTINEL', 'get-master-addr-by-name', $this->service);
                $client->close();

                if ($reply instanceof RedisException) {
                    $errors[] = "{$seed['host']}:{$seed['port']}: " . $reply->getMessage();
                    $this->rotateSeeds();
                    continue;
                }

                if (!is_array($reply) || count($reply) < 2) {
                    $errors[] = "{$seed['host']}:{$seed['port']}: unexpected reply shape";
                    $this->rotateSeeds();
                    continue;
                }

                return [
                    'host' => (string) $reply[0],
                    'port' => (int) $reply[1],
                ];
            } catch (ConnectionException $e) {
                $errors[] = "{$seed['host']}:{$seed['port']}: " . $e->getMessage();
                $this->rotateSeeds();
            }
        }

        throw new ConnectionException(
            "Could not discover master '{$this->service}' from any sentinel: " . implode('; ', $errors)
        );
    }

    /**
     * Returns the healthy replicas registered for the service. An empty list is
     * a valid result (no replicas configured, or all of them flagged s_down /
     * o_down) and signals the caller to fall back to master-only routing.
     *
     * @return list<array{host:string,port:int}>
     * @throws ConnectionException when no seed sentinel responds at all
     */
    public function discoverReplicas(): array
    {
        $errors = [];
        $count  = count($this->seeds);

        for ($i = 0; $i < $count; $i++) {
            $seed = $this->seeds[0];
            try {
                $client = $this->openSentinel($seed);
                $reply  = $client->command('SENTINEL', 'replicas', $this->service);
                $client->close();

                if ($reply instanceof RedisException) {
                    $errors[] = "{$seed['host']}:{$seed['port']}: " . $reply->getMessage();
                    $this->rotateSeeds();
                    continue;
                }

                if (!is_array($reply)) {
                    $errors[] = "{$seed['host']}:{$seed['port']}: unexpected reply shape";
                    $this->rotateSeeds();
                    continue;
                }

                return $this->parseReplicas($reply);
            } catch (ConnectionException $e) {
                $errors[] = "{$seed['host']}:{$seed['port']}: " . $e->getMessage();
                $this->rotateSeeds();
            }
        }

        throw new ConnectionException(
            "Could not query replicas for '{$this->service}' from any sentinel: " . implode('; ', $errors)
        );
    }

    /**
     * Parse a SENTINEL replicas reply into healthy host/port pairs.
     *
     * Each entry is either a flat key/value array (RESP2) or a map (RESP3);
     * both shapes resolve to an array with string keys after parsing.
     *
     * @param  list<mixed>  $reply
     * @return list<array{host:string,port:int}>
     */
    private function parseReplicas(array $reply): array
    {
        $out = [];
        foreach ($reply as $entry) {
            if (!is_array($entry)) continue;
            $info = $this->normalizeReplicaInfo($entry);
            if (!isset($info['ip'], $info['port'])) continue;
            $flags = (string) ($info['flags'] ?? '');
            if (str_contains($flags, 's_down') || str_contains($flags, 'o_down')) continue;
            $out[] = ['host' => (string) $info['ip'], 'port' => (int) $info['port']];
        }
        return $out;
    }

    /**
     * Convert either a flat list of alternating key/value strings or an
     * already-keyed associative array into a single string-keyed map.
     *
     * @param  array<int|string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function normalizeReplicaInfo(array $entry): array
    {
        if ($entry === []) return [];
        // Already a map (RESP3 typed reply).
        if (array_keys($entry) !== range(0, count($entry) - 1)) {
            $out = [];
            foreach ($entry as $k => $v) $out[(string) $k] = $v;
            return $out;
        }
        // Flat list of [k0, v0, k1, v1, ...] (RESP2 reply).
        $out = [];
        for ($i = 0, $n = count($entry); $i + 1 < $n; $i += 2) {
            $out[(string) $entry[$i]] = $entry[$i + 1];
        }
        return $out;
    }

    /** @param  array{host:string,port:int}  $seed */
    private function openSentinel(array $seed): Resp3ClientInterface
    {
        if ($this->clientFactory !== null) {
            return ($this->clientFactory)($seed);
        }
        return new Resp3Client(
            host: $seed['host'],
            port: $seed['port'],
            password: $this->sentinelPassword,
            timeout: $this->timeout,
        );
    }

    private function rotateSeeds(): void
    {
        $front = array_shift($this->seeds);
        if ($front !== null) {
            $this->seeds[] = $front;
        }
    }
}
