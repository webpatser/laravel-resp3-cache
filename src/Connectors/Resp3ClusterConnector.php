<?php declare(strict_types=1);

namespace Resp3\Laravel\Connectors;

use Illuminate\Contracts\Redis\Connector;
use Resp3\Laravel\Cluster\Resp3ClusterRouter;
use Resp3\Laravel\Connections\Resp3ClusterConnection;

/**
 * Laravel Redis connector for cluster mode.
 *
 * Reached via Resp3Connector::connectToCluster(); not registered standalone
 * because Laravel's RedisManager resolves a single connector per client name.
 */
final class Resp3ClusterConnector implements Connector
{
    public function connect(array $config, array $options): Resp3ClusterConnection
    {
        // Single-node "connect" through this class only happens if userland
        // explicitly instantiates it; route the same way as connectToCluster
        // with a single seed node.
        return $this->connectToCluster([$config], [], $options);
    }

    public function connectToCluster(array $config, array $clusterOptions, array $options): Resp3ClusterConnection
    {
        $seedNodes = [];
        foreach ($config as $node) {
            $seedNodes[] = [
                'host' => (string) ($node['host'] ?? '127.0.0.1'),
                'port' => (int)    ($node['port'] ?? 6379),
            ];
        }

        // Cluster-level options get merged on top of connection-level options.
        $merged = array_merge($options, $clusterOptions);

        $router = new Resp3ClusterRouter(
            seedNodes: $seedNodes,
            username:      $merged['username'] ?? $config[0]['username'] ?? null,
            password:      $merged['password'] ?? $config[0]['password'] ?? null,
            tls:      (bool) ($merged['scheme'] ?? '') === 'tls' || ($merged['ssl'] ?? false),
            timeout:  (float) ($merged['read_timeout'] ?? $merged['timeout'] ?? 5.0),
            persistent: (bool) ($merged['persistent'] ?? false),
            tlsOptions: $merged['ssl'] ?? [],
            readReplicas: (bool) ($merged['cluster_read_replicas'] ?? false),
        );

        return new Resp3ClusterConnection($router, $config[0] ?? []);
    }
}
