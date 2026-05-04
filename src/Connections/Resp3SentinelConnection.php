<?php declare(strict_types=1);

namespace Resp3\Laravel\Connections;

use Resp3\Laravel\Cluster\CommandClassifier;
use Resp3\Laravel\Sentinel\NoReplicasAvailableException;
use Resp3\Laravel\Sentinel\Resp3SentinelClient;
use Resp3\Laravel\Sentinel\Resp3SentinelReplicaPool;
use Resp3\Laravel\Sentinel\SentinelDiscovery;

/**
 * Sentinel-managed Connection. Looks identical to a single-node
 * Resp3Connection from Laravel's perspective; the only difference is
 * that the underlying Resp3SentinelClient transparently re-discovers
 * the master on connection failure.
 *
 * When a Resp3SentinelReplicaPool is provided (sentinel_read_replicas =
 * true), read commands are routed to a random healthy replica. Writes,
 * MULTI buffer commands, and pool-exhausted reads always go to the master.
 *
 * Subscribe loops still get a dedicated socket via
 * Resp3Connection::createSubscription() but build a SentinelClient so
 * the subscriber connects to whichever node is the current master.
 */
class Resp3SentinelConnection extends Resp3Connection
{
    public function __construct(
        private readonly Resp3SentinelClient $sentinelClient,
        array $config = [],
        private readonly ?Resp3SentinelReplicaPool $replicaPool = null,
    ) {
        parent::__construct($sentinelClient, $config);
    }

    /**
     * Route reads through the replica pool when one is configured; fall back
     * to the parent implementation (master) for writes, MULTI-buffered
     * commands, and any read where the replica pool has no usable nodes.
     */
    public function command($method, array $parameters = [])
    {
        if ($this->replicaPool === null) {
            return parent::command($method, $parameters);
        }

        $upper = strtoupper((string) $method);

        // MULTI / EXEC / DISCARD must stay on the master and reads inside an
        // open MULTI buffer are queued by the parent, not dispatched as reads.
        if ($this->isMultiActive() || !CommandClassifier::isReadOnly($upper)) {
            return parent::command($method, $parameters);
        }

        // Reads only past this point: try the pool, fall back silently.
        try {
            return $this->dispatchToPool($upper, $parameters);
        } catch (NoReplicasAvailableException) {
            return parent::command($method, $parameters);
        }
    }

    public function disconnect()
    {
        parent::disconnect();
        $this->replicaPool?->close();
    }

    /**
     * For subscribers, hand back another Sentinel-aware client so the
     * subscribe loop also benefits from re-discovery on connection failure.
     * The new client opens a fresh socket; the original is unaffected.
     */
    protected function newSubscribeClient(): Resp3SentinelClient
    {
        // Re-use the same discovery instance so all sentinel queries hit the
        // same seed pool. The new wrapper opens its own data-plane socket.
        $reflection = new \ReflectionClass($this->sentinelClient);
        $discovery  = $reflection->getProperty('discovery')->getValue($this->sentinelClient);

        return new Resp3SentinelClient(
            discovery: $discovery,
            username: (string) ($this->config['username'] ?? '') ?: null,
            password: (string) ($this->config['password'] ?? '') ?: null,
            database: (int) ($this->config['database'] ?? 0),
            tls: ($this->config['scheme'] ?? '') === 'tls' || (bool) ($this->config['ssl'] ?? false),
            timeout: 0.0,           // block forever between messages
            persistent: false,
            tlsOptions: is_array($this->config['ssl'] ?? null) ? $this->config['ssl'] : [],
        );
    }

    // ------------------------------------------------------------------ helpers

    private function dispatchToPool(string $upper, array $parameters): mixed
    {
        // Mirror the parent's flatten + event behaviour but route the actual
        // command through the replica pool. Events still fire so command
        // listeners see the read traffic.
        $args  = $this->flattenForPool($parameters);
        $start = microtime(true);

        try {
            $result = $this->replicaPool->command($upper, ...$args);
        } catch (\Throwable $e) {
            $this->events?->dispatch(new \Illuminate\Redis\Events\CommandFailed($upper, $parameters, $e, $this));
            throw $e;
        }

        $elapsed = round((microtime(true) - $start) * 1000, 2);
        $this->events?->dispatch(new \Illuminate\Redis\Events\CommandExecuted($upper, $parameters, $elapsed, $this));

        return $result;
    }

    private function isMultiActive(): bool
    {
        // The parent buffers MULTI commands in a private property; introspect
        // via reflection so we never re-route reads inside an open transaction.
        static $prop = null;
        if ($prop === null) {
            $prop = (new \ReflectionClass(Resp3Connection::class))->getProperty('multiBuffer');
        }
        return $prop->getValue($this) !== null;
    }

    private function flattenForPool(array $params): array
    {
        $out = [];
        foreach ($params as $p) {
            if (is_array($p)) {
                foreach ($p as $inner) {
                    $out[] = is_scalar($inner) ? (string) $inner : $inner;
                }
            } else {
                $out[] = is_scalar($p) ? (string) $p : $p;
            }
        }
        return $out;
    }
}
