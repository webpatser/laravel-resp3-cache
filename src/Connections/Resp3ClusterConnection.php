<?php declare(strict_types=1);

namespace Resp3\Laravel\Connections;

use BadMethodCallException;
use Closure;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\Events\CommandFailed;
use Resp3\Laravel\Cluster\CRC16;
use Resp3\Laravel\Cluster\Resp3ClusterRouter;
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
            'Pub/Sub is not supported on a cluster connection. Subscribe via a ' .
            'single-node Redis::connection() pointed at one of the master nodes, ' .
            'or wait for sharded pub/sub (SSUBSCRIBE) support in v0.4.'
        );
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
