<?php declare(strict_types=1);

namespace Resp3\Laravel\Connectors;

use Illuminate\Contracts\Redis\Connector;
use Resp3\Laravel\Connections\Resp3SentinelConnection;
use Resp3\Laravel\Sentinel\Resp3SentinelClient;
use Resp3\Laravel\Sentinel\Resp3SentinelReplicaPool;
use Resp3\Laravel\Sentinel\SentinelDiscovery;
use RuntimeException;

/**
 * Builds a Sentinel-aware Connection. Reached from
 * Resp3Connector::connect() when the user sets
 * 'replication' => 'sentinel' on their Redis config.
 *
 * Config shape (Predis-compatible):
 *   - $config: list of seed sentinel addresses (host + port).
 *   - $options: top-level Redis config including:
 *       - service:           required, the master name registered with
 *                            the sentinel cluster (e.g. 'mymaster').
 *       - sentinel_password: optional sentinel-side password.
 *       - password:          optional data-plane password.
 *       - database, timeout, persistent, scheme, ssl: forwarded to the
 *                            data-plane Resp3Client just like the
 *                            single-node connector.
 */
final class Resp3SentinelConnector implements Connector
{
    public function connect(array $config, array $options): Resp3SentinelConnection
    {
        $service = (string) ($options['service'] ?? '');
        if ($service === '') {
            throw new RuntimeException(
                "'service' option is required when 'replication' => 'sentinel' is set."
            );
        }

        // $config arrives as a list of seed sentinels (one entry per node).
        // RedisManager passes the cluster `default` array through as-is when
        // 'replication' is set, mirroring Predis's expected shape.
        $seeds = [];
        foreach ($config as $node) {
            $seeds[] = [
                'host' => (string) ($node['host'] ?? '127.0.0.1'),
                'port' => (int)    ($node['port'] ?? 26379),
            ];
        }
        if ($seeds === []) {
            // Single-node fallback shape: caller passed one entry as the
            // top-level config. Still useful for trivial test setups.
            $seeds[] = [
                'host' => (string) ($options['host'] ?? '127.0.0.1'),
                'port' => (int)    ($options['port'] ?? 26379),
            ];
        }

        $discovery = new SentinelDiscovery(
            seeds: $seeds,
            service: $service,
            sentinelPassword: $options['sentinel_password'] ?? null,
            timeout: (float) ($options['sentinel_timeout'] ?? 1.0),
        );

        $tls        = ($options['scheme'] ?? '') === 'tls' || (bool) ($options['ssl'] ?? false);
        $timeout    = (float) ($options['read_timeout'] ?? $options['timeout'] ?? 5.0);
        $persistent = (bool) ($options['persistent'] ?? false);
        $tlsOptions = is_array($options['ssl'] ?? null) ? $options['ssl'] : [];

        $client = new Resp3SentinelClient(
            discovery: $discovery,
            username: $options['username'] ?? null,
            password: $options['password'] ?? null,
            database: (int) ($options['database'] ?? 0),
            tls: $tls,
            timeout: $timeout,
            persistent: $persistent,
            tlsOptions: $tlsOptions,
            features: is_array($options['features'] ?? null) ? $options['features'] : [],
        );

        $replicaPool = null;
        if ((bool) ($options['sentinel_read_replicas'] ?? false)) {
            $replicaPool = new Resp3SentinelReplicaPool(
                discovery: $discovery,
                clientOptions: [
                    'username'   => $options['username'] ?? null,
                    'password'   => $options['password'] ?? null,
                    'database'   => (int) ($options['database'] ?? 0),
                    'tls'        => $tls,
                    'timeout'    => $timeout,
                    'persistent' => $persistent,
                    'tlsOptions' => $tlsOptions,
                ],
            );
        }

        return new Resp3SentinelConnection($client, $options, $replicaPool);
    }

    public function connectToCluster(array $config, array $clusterOptions, array $options): Resp3SentinelConnection
    {
        throw new RuntimeException(
            'Sentinel and Cluster modes are mutually exclusive Redis topologies. ' .
            'Pick one: drop the cluster config and keep replication=sentinel, or ' .
            'drop replication=sentinel and keep the cluster config.'
        );
    }
}
