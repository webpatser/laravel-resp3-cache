<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Support;

/**
 * A scripted RESP server in a child PHP process.
 *
 * The script is a list of connections; each connection is a list of raw
 * replies. For every chunk of bytes a client sends, the server writes the
 * next reply of the current connection. Once a connection's replies are
 * used up it stays open until the client closes it, then the next
 * connection in the script is accepted. The child exits after the last one
 * (or after a 15 second safety timeout).
 *
 * Two markers change that: a reply starting with "!" is sent unprompted
 * (after a short pause, without waiting for the client to write), and the
 * reply "~" closes the connection. Everything the client wrote is kept and
 * available through received().
 */
final class FakeRespServer
{
    /** HELLO 3 reply of a Valkey 9.1.2 standalone server. */
    public const HELLO = "%5\r\n\$6\r\nserver\r\n\$6\r\nvalkey\r\n\$7\r\nversion\r\n\$5\r\n9.1.2\r\n\$5\r\nproto\r\n:3\r\n\$2\r\nid\r\n:1\r\n\$4\r\nmode\r\n\$10\r\nstandalone\r\n";

    /** HELLO 3 reply of a Redis 8.10.1 standalone server. */
    public const HELLO_REDIS = "%5\r\n\$6\r\nserver\r\n\$5\r\nredis\r\n\$7\r\nversion\r\n\$6\r\n8.10.1\r\n\$5\r\nproto\r\n:3\r\n\$2\r\nid\r\n:1\r\n\$4\r\nmode\r\n\$10\r\nstandalone\r\n";

    /** @var resource|null */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    public readonly int $port;

    /** @param list<list<string>> $connections */
    public function __construct(array $connections)
    {
        $script = tempnam(sys_get_temp_dir(), 'r3-fake-');
        file_put_contents($script, self::childScript());
        $this->scriptPath = $script;
        $this->logPath = tempnam(sys_get_temp_dir(), 'r3-fakelog-');

        $this->process = proc_open(
            [PHP_BINARY, $script, base64_encode(json_encode($connections, JSON_THROW_ON_ERROR)), $this->logPath],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->pipes,
        );
        if (!is_resource($this->process)) {
            throw new \RuntimeException('Could not start the fake server process');
        }

        $line = fgets($this->pipes[1]);
        if ($line === false || !ctype_digit(trim($line))) {
            $this->stop();
            throw new \RuntimeException('Fake server did not report a port: ' . stream_get_contents($this->pipes[2]));
        }
        $this->port = (int) trim($line);
    }

    private string $scriptPath;

    private string $logPath;

    /** Raw bytes the client has sent so far, across all connections. */
    public function received(): string
    {
        clearstatcache(true, $this->logPath);

        return (string) @file_get_contents($this->logPath);
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            @proc_terminate($this->process);
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    @fclose($pipe);
                }
            }
            @proc_close($this->process);
            $this->process = null;
        }
        foreach ([$this->scriptPath ?? null, $this->logPath ?? null] as $path) {
            if ($path !== null && file_exists($path)) {
                @unlink($path);
            }
        }
    }

    public function __destruct()
    {
        $this->stop();
    }

    private static function childScript(): string
    {
        return <<<'PHP'
        <?php
        $connections = json_decode(base64_decode($argv[1]), true);
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) { fwrite(STDERR, "bind failed: $errstr\n"); exit(1); }
        $name = stream_socket_get_name($server, false);
        echo substr($name, strrpos($name, ':') + 1), "\n";
        fflush(STDOUT);

        $deadline = time() + 15;
        foreach ($connections as $replies) {
            $client = @stream_socket_accept($server, max(1, $deadline - time()));
            if ($client === false) { exit(2); }
            $closed = false;
            foreach ($replies as $reply) {
                if ($reply === '~') { fclose($client); $closed = true; break; }
                if ($reply[0] === '!') { usleep(100000); fwrite($client, substr($reply, 1)); continue; }
                $chunk = fread($client, 65536);
                if ($chunk === '' || $chunk === false) { break; }
                file_put_contents($argv[2], $chunk, FILE_APPEND);
                fwrite($client, $reply);
            }
            if ($closed) { continue; }
            // Hold the connection until the client hangs up.
            stream_set_timeout($client, max(1, $deadline - time()));
            while (($chunk = fread($client, 65536)) !== '' && $chunk !== false) {}
            fclose($client);
        }
        PHP;
    }
}
