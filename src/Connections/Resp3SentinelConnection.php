<?php declare(strict_types=1);

namespace Resp3\Laravel\Connections;

use Resp3\Laravel\Sentinel\Resp3SentinelClient;

/**
 * Sentinel-managed Connection. Looks identical to a single-node
 * Resp3Connection from Laravel's perspective; the only difference is
 * that the underlying Resp3SentinelClient transparently re-discovers
 * the master on connection failure.
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
    ) {
        parent::__construct($sentinelClient, $config);
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
}
