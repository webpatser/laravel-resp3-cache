<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Resp3\Laravel\Client\Resp3Client;
use Resp3\Laravel\Tests\Support\Env;

/**
 * Persistent sockets against a live server: live clients never share a
 * socket, a clean client hands its socket to its successor, and a client
 * that ends inside MULTI does not.
 *
 * Persistent sockets outlive a test, so every test uses its own
 * persistent_id to start from sockets no other test touched.
 */
final class PersistentSocketTest extends TestCase
{
    private string $persistentId;

    protected function setUp(): void
    {
        if (!extension_loaded('resp3')) {
            $this->markTestSkipped('ext-resp3 is not loaded');
        }
        if (!Env::reachable()) {
            $this->markTestSkipped('No server at ' . Env::address());
        }
        $this->persistentId = 'test-' . bin2hex(random_bytes(6));
    }

    private function client(int $database = 0): Resp3Client
    {
        return new Resp3Client(
            host: Env::host(),
            port: Env::port(),
            database: $database,
            persistent: true,
            persistentId: $this->persistentId,
        );
    }

    private function clientId(Resp3Client $client): int
    {
        $id = $client->command('CLIENT', 'ID');
        $this->assertIsInt($id);

        return $id;
    }

    public function test_clients_on_different_databases_get_different_sockets(): void
    {
        $db0 = $this->client(0);
        $db1 = $this->client(1);

        $this->assertNotSame($this->clientId($db0), $this->clientId($db1));
    }

    public function test_two_live_clients_with_the_same_config_get_different_sockets(): void
    {
        $first = $this->client();
        $second = $this->client();

        $this->assertNotSame($this->clientId($first), $this->clientId($second));
    }

    public function test_a_clean_client_hands_its_socket_to_the_next_one(): void
    {
        $client = $this->client();
        $id = $this->clientId($client);
        unset($client);

        $next = $this->client();

        $this->assertSame($id, $this->clientId($next));
        $this->assertSame('PONG', $next->command('PING'));
    }

    public function test_a_client_destroyed_inside_multi_does_not_hand_on_its_socket(): void
    {
        $client = $this->client();
        $id = $this->clientId($client);
        $this->assertSame('OK', $client->command('MULTI'));
        unset($client);

        $next = $this->client();

        $this->assertNotSame($id, $this->clientId($next));
        $this->assertSame('PONG', $next->command('PING'), 'the new socket is not inside a transaction');
    }

    public function test_a_finished_transaction_leaves_the_socket_reusable(): void
    {
        $client = $this->client();
        $id = $this->clientId($client);
        $client->command('MULTI');
        $client->command('PING');
        $this->assertSame(['PONG'], $client->command('EXEC'));
        unset($client);

        $this->assertSame($id, $this->clientId($this->client()));
    }

    public function test_a_client_that_switched_database_does_not_hand_on_its_socket(): void
    {
        $client = $this->client();
        $id = $this->clientId($client);
        $client->command('SELECT', '2');
        unset($client);

        $this->assertNotSame($id, $this->clientId($this->client()));
    }
}
