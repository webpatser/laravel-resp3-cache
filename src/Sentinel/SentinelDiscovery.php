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
