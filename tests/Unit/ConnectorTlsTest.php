<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Resp3\Laravel\Cluster\Resp3ClusterRouter;
use Resp3\Laravel\Client\Resp3Client;
use Resp3\Laravel\Connectors\Resp3ClusterConnector;
use Resp3\Laravel\Connectors\Resp3Connector;

/**
 * Guards against the operator-precedence bug where `(bool) $scheme === 'tls'`
 * always evaluates to false, silently disabling TLS for an operator who sets
 * `scheme => 'tls'` without an explicit `ssl` array.
 */
final class ConnectorTlsTest extends TestCase
{
    private static function readTls(object $target): bool
    {
        $ref = new \ReflectionProperty($target, 'tls');

        return (bool) $ref->getValue($target);
    }

    public function test_scheme_tls_without_ssl_enables_tls_on_single_node(): void
    {
        $connection = (new Resp3Connector())->connect(
            ['host' => '127.0.0.1', 'port' => 6379, 'scheme' => 'tls'],
            [],
        );

        $client = $connection->client();
        $this->assertInstanceOf(Resp3Client::class, $client);
        $this->assertTrue(self::readTls($client), 'scheme=tls must enable TLS even without an ssl array');
    }

    public function test_no_scheme_and_no_ssl_keeps_tls_disabled_on_single_node(): void
    {
        $connection = (new Resp3Connector())->connect(
            ['host' => '127.0.0.1', 'port' => 6379],
            [],
        );

        $this->assertFalse(self::readTls($connection->client()));
    }

    public function test_scheme_tls_without_ssl_enables_tls_on_cluster(): void
    {
        $connection = (new Resp3ClusterConnector())->connectToCluster(
            [['host' => '127.0.0.1', 'port' => 6379]],
            [],
            ['scheme' => 'tls'],
        );

        $router = (new \ReflectionProperty($connection, 'router'))->getValue($connection);
        $this->assertInstanceOf(Resp3ClusterRouter::class, $router);
        $this->assertTrue(self::readTls($router), 'scheme=tls must enable TLS on the cluster router');
    }
}
