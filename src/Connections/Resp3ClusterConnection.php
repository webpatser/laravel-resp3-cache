<?php declare(strict_types=1);

namespace Resp3\Laravel\Connections;

use BadMethodCallException;
use Closure;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\Events\CommandFailed;
use Resp3\Laravel\Client\Resp3Client;
use Resp3\Laravel\Cluster\CRC16;
use Resp3\Laravel\Cluster\Resp3ClusterRouter;
use Resp3\Laravel\PubSub\SubscriptionLoop;
use RuntimeException;
use Throwable;

/**
 * Laravel cluster Connection wrapper around Resp3ClusterRouter.
 *
 * Mirrors the surface that RedisStore exercises but routes commands by slot
 * across multiple node connections. flushdb / keys / scan broadcast across
 * all masters; multi/exec require all keys to land on the same slot
 * (CROSSSLOT otherwise).
 */
class Resp3ClusterConnection extends Resp3Connection
{
    /** @var list<list<string>>|null Buffered commands between MULTI and EXEC. */
    private ?array $multiBuffer = null;

    /** First key seen inside MULTI; all later keys must hash to the same slot. */
    private ?string $multiHashKey = null;

    public function __construct(
        private readonly Resp3ClusterRouter $router,
        array $config = [],
    ) {
        // Skip parent constructor's single-client setup; we route per command.
        $this->config = $config;
    }

    public function command($method, array $parameters = [])
    {
        $upper = strtoupper($method);

        // Inside MULTI: buffer + enforce same-slot keys.
        if ($this->multiBuffer !== null && !in_array($upper, ['MULTI', 'EXEC', 'DISCARD'], true)) {
            $args = $this->flattenParameters($parameters);
            $this->trackMultiHashKey($upper, $args);
            $this->multiBuffer[] = [$upper, ...$args];
            return $this;
        }

        // Broadcast commands.
        if (in_array($upper, ['FLUSHDB', 'FLUSHALL'], true)) {
            return $this->dispatch($method, $parameters, fn () => $this->router->broadcastToMasters($upper));
        }

        return $this->dispatch($method, $parameters, function () use ($upper, $parameters) {
            $args = $this->flattenParameters($parameters);
            return $this->router->command($upper, ...$args);
        });
    }

    public function multi()
    {
        $this->multiBuffer = [];
        $this->multiHashKey = null;
        return $this;
    }

    public function exec()
    {
        $buffered = $this->multiBuffer ?? [];
        $hashKey  = $this->multiHashKey ?? throw new RuntimeException(
            'EXEC called without MULTI or without any keyed command in the transaction'
        );
        $this->multiBuffer = null;
        $this->multiHashKey = null;

        // Send MULTI + buffered commands + EXEC as one same-slot pipeline.
        $payload = [['MULTI'], ...$buffered, ['EXEC']];
        $replies = $this->router->pipelineSameSlot($payload, $hashKey);
        // Last reply is EXEC: an array of per-command results.
        return end($replies);
    }

    public function discard()
    {
        $this->multiBuffer = null;
        $this->multiHashKey = null;
        return null;
    }

    public function flushdb()
    {
        return $this->router->broadcastToMasters('FLUSHDB');
    }

    public function disconnect(): void
    {
        $this->router->disconnect();
    }

    public function createSubscription($channels, Closure $callback, $method = 'subscribe')
    {
        throw new BadMethodCallException(
            'Global Pub/Sub (SUBSCRIBE / PSUBSCRIBE) is not supported on a cluster ' .
            'connection. For per-shard delivery, use Redis::connection()->ssubscribe(' .
            "['{tag}.channel'], \$callback) (sharded pub/sub via SSUBSCRIBE). For " .
            'global broadcast semantics, open a single-node Redis::connection() ' .
            'pointed at one of the cluster master nodes.'
        );
    }

    /**
     * Sharded subscribe (SSUBSCRIBE) on the cluster master that owns the
     * channel's slot. All channels in a single call must hash to the same
     * slot; use {hash tag} syntax to colocate them. Writes a dedicated
     * subscriber socket against that master and runs the SubscriptionLoop
     * until the callback returns false, a signal arrives, or the socket
     * drops.
     *
     * @param  list<string>  $channels
     */
    public function ssubscribe(array $channels, Closure $callback): void
    {
        if ($channels === []) {
            throw new \InvalidArgumentException('At least one channel is required');
        }

        $hashKey = $this->validateSameSlotChannels($channels);
        $addr    = $this->router->subscriberAddrForChannel($hashKey);

        $client = $this->newSubscribeClientFor($addr);
        (new SubscriptionLoop($client, array_values($channels), $callback, 'ssubscribe'))->run();
    }

    // ------------------------------------------------------------------ helpers

    private function dispatch(string $method, array $parameters, Closure $work): mixed
    {
        $start = microtime(true);
        try {
            $result = $work();
        } catch (Throwable $e) {
            $this->events?->dispatch(new CommandFailed($method, $parameters, $e, $this));
            throw $e;
        }
        $elapsed = round((microtime(true) - $start) * 1000, 2);
        $this->events?->dispatch(new CommandExecuted($method, $parameters, $elapsed, $this));
        return $result;
    }

    /**
     * Verify every channel hashes to the same slot. Returns the first channel
     * (used as the slot's hash key for routing). Throws RuntimeException with
     * a CROSSSLOT-style hint when the channels disagree.
     *
     * @param  list<string>  $channels
     */
    private function validateSameSlotChannels(array $channels): string
    {
        $first = (string) $channels[0];
        $slot  = CRC16::slot($first);
        for ($i = 1, $n = count($channels); $i < $n; $i++) {
            $other = (string) $channels[$i];
            if (CRC16::slot($other) !== $slot) {
                throw new RuntimeException(
                    "CROSSSLOT channels in ssubscribe: '{$first}' and '{$other}' " .
                    "hash to different slots. Use a {hash tag} to colocate them."
                );
            }
        }
        return $first;
    }

    /**
     * Build a fresh subscriber Resp3Client against a specific cluster master
     * address. Mirrors Resp3Connection::newSubscribeClient but parses the
     * "host:port" picked by the router and inherits cluster-wide auth/tls
     * via Resp3ClusterRouter::getDataPlaneOptions().
     */
    private function newSubscribeClientFor(string $addr): Resp3Client
    {
        [$host, $port] = explode(':', $addr, 2);
        $opts = $this->router->getDataPlaneOptions();

        return new Resp3Client(
            host:       $host,
            port:       (int) $port,
            username:   $opts['username']   ?? null,
            password:   $opts['password']   ?? null,
            database:   0,                                  // pub/sub is database-agnostic
            tls:        (bool) ($opts['tls'] ?? false),
            timeout:    0.0,                                // block forever between messages
            persistent: false,                              // never share a subscribe socket
            tlsOptions: is_array($opts['tlsOptions'] ?? null) ? $opts['tlsOptions'] : [],
        );
    }

    private function trackMultiHashKey(string $command, array $args): void
    {
        // Crude key extractor: use first arg as the keyed argument for most
        // commands. Covers GET, SET, INCR, HSET, etc.
        $candidate = match ($command) {
            'EVAL', 'EVALSHA' => count($args) >= 2 ? ($args[2] ?? null) : null,
            default           => $args[0] ?? null,
        };
        if (!is_string($candidate)) return;

        if ($this->multiHashKey === null) {
            $this->multiHashKey = $candidate;
            return;
        }
        $existingSlot = CRC16::slot($this->multiHashKey);
        $newSlot      = CRC16::slot($candidate);
        if ($existingSlot !== $newSlot) {
            throw new RuntimeException(
                "CROSSSLOT keys in MULTI: '$this->multiHashKey' and '$candidate' " .
                "hash to different slots. Use a {hash tag} to colocate."
            );
        }
    }
}
