<?php declare(strict_types=1);

namespace Resp3\Laravel\Connectors;

use Illuminate\Contracts\Redis\Connector;
use Resp3\Laravel\Client\Resp3Client;
use Resp3\Laravel\Connections\Resp3Connection;
use RuntimeException;

/**
 * Laravel Redis connector that builds a Resp3Connection.
 *
 * Registered via Redis::extend('resp3', fn () => new Resp3Connector()).
 * The framework calls connect() once per named connection and caches the
 * result.
 */
final class Resp3Connector implements Connector
{
    public function connect(array $config, array $options): Resp3Connection
    {
        $client = new Resp3Client(
            host: (string) ($config['host'] ?? '127.0.0.1'),
            port: (int)    ($config['port'] ?? 6379),
            username:      $config['username'] ?? null,
            password:      $config['password'] ?? null,
            database: (int) ($config['database'] ?? 0),
            tls:      (bool) ($config['scheme'] ?? '') === 'tls' || ($config['ssl'] ?? false),
            timeout:  (float) ($options['read_timeout'] ?? $options['timeout'] ?? 5.0),
            persistent: (bool) ($options['persistent'] ?? false),
            tlsOptions: $config['ssl'] ?? [],
        );

        return new Resp3Connection($client, $config);
    }

    public function connectToCluster(array $config, array $clusterOptions, array $options): Resp3Connection
    {
        throw new RuntimeException(
            'Resp3 client does not support Redis Cluster yet. Use phpredis or predis for cluster connections.'
        );
    }
}
