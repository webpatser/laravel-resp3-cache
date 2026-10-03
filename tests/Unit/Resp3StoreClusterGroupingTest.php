<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Unit;

use Illuminate\Contracts\Redis\Factory;
use PHPUnit\Framework\TestCase;
use Resp3\Laravel\Cache\Resp3Store;
use Resp3\Laravel\Client\ServerCapabilities;
use Resp3\Laravel\Cluster\CRC16;
use Resp3\Laravel\Cluster\Resp3ClusterRouter;
use Resp3\Laravel\Connections\Resp3ClusterConnection;

/**
 * many() and putMany() on a cluster connection must never send a
 * multi-key command whose keys hash to different slots. The connection is a
 * stub that records commands; no server is involved.
 */
final class Resp3StoreClusterGroupingTest extends TestCase
{
    public function test_fixture_keys_hash_as_expected(): void
    {
        $this->assertNotSame(CRC16::slot('a'), CRC16::slot('b'));
        $this->assertSame(CRC16::slot('{t}x'), CRC16::slot('{t}y'));
    }

    public function test_many_sends_one_mget_per_slot(): void
    {
        $conn = $this->connection(['a' => 's:1:"A";', 'b' => 's:1:"B";', '{t}x' => 's:2:"TX";', '{t}y' => 's:2:"TY";']);
        $store = $this->store($conn);

        $result = $store->many(['a', '{t}x', 'b', '{t}y', 'missing']);

        $this->assertSame(['a', '{t}x', 'b', '{t}y', 'missing'], array_keys($result), 'caller order is kept');
        $this->assertSame('A', $result['a']);
        $this->assertSame('B', $result['b']);
        $this->assertSame('TX', $result['{t}x']);
        $this->assertSame('TY', $result['{t}y']);
        $this->assertNull($result['missing']);

        $mgets = $this->commandsNamed($conn, 'MGET');
        $this->assertGreaterThanOrEqual(3, count($mgets), 'a, b, {t} and missing live in at least 3 slots');
        foreach ($mgets as $args) {
            $this->assertCount(1, array_unique(array_map(CRC16::slot(...), $args)), 'one slot per MGET');
        }
        $this->assertContains(['{t}x', '{t}y'], array_map(fn ($a) => array_values($a), $mgets));
    }

    public function test_many_with_no_keys_sends_nothing(): void
    {
        $conn = $this->connection([]);

        $this->assertSame([], $this->store($conn)->many([]));
        $this->assertSame([], $conn->log);
    }

    public function test_put_many_same_slot_uses_a_single_msetex(): void
    {
        $conn = $this->connection([], 'valkey', '9.1.2');

        $this->assertTrue($this->store($conn)->putMany(['{t}x' => 'one', '{t}y' => 'two'], 60));

        $this->assertCount(1, $conn->log);
        [$name, $args] = $conn->log[0];
        $this->assertSame('MSETEX', $name);
        $this->assertSame('2', $args[0]);
        $this->assertSame(['{t}x', '{t}y'], [$args[1], $args[3]]);
        $this->assertSame(['EX', '60'], array_slice($args, -2));
    }

    public function test_put_many_across_slots_falls_back_to_one_setex_per_key(): void
    {
        $conn = $this->connection([], 'valkey', '9.1.2');

        $this->assertTrue($this->store($conn)->putMany(['a' => 1, 'b' => 2, 'c' => 3], 60));

        $this->assertSame([], $this->commandsNamed($conn, 'MSETEX'));
        $setex = $this->commandsNamed($conn, 'SETEX');
        $this->assertCount(3, $setex);
        $this->assertSame(['a', 'b', 'c'], array_map(fn ($a) => $a[0], $setex));
        $this->assertSame(['60', '60', '60'], array_map(fn ($a) => $a[1], $setex));
    }

    public function test_put_many_without_msetex_support_sets_each_key(): void
    {
        $conn = $this->connection([], 'redis', '8.4.3');

        $this->assertTrue($this->store($conn)->putMany(['{t}x' => 'one', '{t}y' => 'two'], 30));

        $this->assertSame([], $this->commandsNamed($conn, 'MSETEX'));
        $this->assertCount(2, $this->commandsNamed($conn, 'SETEX'));
    }

    private function store(Resp3ClusterConnection $conn): Resp3Store
    {
        $factory = new class($conn) implements Factory {
            public function __construct(private readonly Resp3ClusterConnection $conn) {}

            public function connection($name = null)
            {
                return $this->conn;
            }
        };

        return new Resp3Store($factory, '', 'default');
    }

    /** @param array<string, string> $data raw stored values by key */
    private function connection(array $data, string $server = 'valkey', string $version = '9.1.2'): Resp3ClusterConnection
    {
        $router = new Resp3ClusterRouter([['host' => '127.0.0.1', 'port' => 7100]]);
        $caps = ServerCapabilities::fromHello(['server' => $server, 'version' => $version, 'mode' => 'cluster']);

        return new class($router, $caps, $data) extends Resp3ClusterConnection {
            /** @var list<array{string, list<string>}> */
            public array $log = [];

            /** @param array<string, string> $data */
            public function __construct(Resp3ClusterRouter $router, private readonly ServerCapabilities $caps, private readonly array $data)
            {
                parent::__construct($router);
            }

            public function capabilities(): ServerCapabilities
            {
                return $this->caps;
            }

            public function command($method, array $parameters = [])
            {
                $name = strtoupper($method);
                $args = array_map('strval', array_is_list($parameters) ? $parameters : array_values($parameters));
                $this->log[] = [$name, $args];

                return match ($name) {
                    'MGET' => array_map(fn (string $key) => $this->data[$key] ?? null, $args),
                    'MSETEX' => 1,
                    default => 'OK',
                };
            }
        };
    }

    /** @return list<list<string>> argument lists of every recorded command with this name */
    private function commandsNamed(Resp3ClusterConnection $conn, string $name): array
    {
        return array_values(array_map(
            fn (array $entry) => $entry[1],
            array_filter($conn->log, fn (array $entry) => $entry[0] === $name),
        ));
    }
}
