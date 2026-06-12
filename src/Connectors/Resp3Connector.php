<?php declare(strict_types=1);

namespace Resp3\Laravel\Connectors;

use Illuminate\Contracts\Redis\Connector;
use Resp3\Laravel\Client\Resp3Client;
use Resp3\Laravel\Connections\Resp3Connection;

/**
 * Laravel Redis connector that builds a Resp3Connection.
 *
 * Registered via Redis::extend('resp3', fn () => new Resp3Connector()).
 * The framework calls connect() once per named connection and caches the
 * result.
 */
final class Resp3Connector implements Connector
{
    public function connect(array $config, array $options)
    {
        // Sentinel topology dispatches to the dedicated sentinel connector.
        // The Predis-compatible config shape uses the `replication` option
        // and treats `default` (the cluster-style array we receive in
        // $config when RedisManager normalises) as a list of seed sentinels.
        if (($options['replication'] ?? null) === 'sentinel') {
            // RedisManager normalises a single connection's config into one
            // array; for sentinel we expect a list of seed addresses. Wrap a
            // bare config in a one-element list so test setups that point at
            // a single sentinel still work.
            $seedList = $this->isListOfNodes($config) ? $config : [$config];
            return (new Resp3SentinelConnector())->connect($seedList, $options);
        }

        $client = new Resp3Client(
            host: (string) ($config['host'] ?? '127.0.0.1'),
            port: (int)    ($config['port'] ?? 6379),
            username:      $config['username'] ?? null,
            password:      $config['password'] ?? null,
            database: (int) ($config['database'] ?? 0),
            tls:      ($config['scheme'] ?? '') === 'tls' || (bool) ($config['ssl'] ?? false),
            timeout:  (float) ($options['read_timeout'] ?? $options['timeout'] ?? 5.0),
            persistent: (bool) ($options['persistent'] ?? false),
            tlsOptions: $config['ssl'] ?? [],
        );

        return new Resp3Connection($client, $config);
    }

    /** Distinguish a list of node configs from a single node config. */
    private function isListOfNodes(array $config): bool
    {
        // Single node has 'host' at top level; a list has integer keys with
        // ['host' => ..., 'port' => ...] entries.
        return $config !== [] && isset($config[0]) && is_array($config[0]);
    }

    public function connectToCluster(array $config, array $clusterOptions, array $options)
    {
        // Delegate to the dedicated cluster connector. Laravel's RedisManager
        // resolves a single connector per client name, so we route here.
        return (new Resp3ClusterConnector())->connectToCluster($config, $clusterOptions, $options);
    }
}
