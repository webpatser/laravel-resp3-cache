<?php declare(strict_types=1);

namespace Resp3\Laravel\Client;

use Resp3\Parser;
use Resp3\RedisException;

/**
 * Synchronous TCP Redis client backed by the ext-resp3 parser.
 *
 * Each command writes a RESP-encoded request to the socket, then drives the
 * parser by reading bytes until a complete top-level reply lands. No fibers,
 * no event loop, no callbacks. Drop-in for php-fpm / sync request workflows.
 */
final class Resp3Client implements Resp3ClientInterface
{
    private mixed $socket = null;
    private Parser $parser;
    private int $readBufferSize = 8192;

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 6379,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly int $database = 0,
        private readonly bool $tls = false,
        private readonly float $timeout = 5.0,
        private readonly bool $persistent = false,
        private readonly array $tlsOptions = [],
    ) {
        $this->parser = new Parser();
    }

    /**
     * Send one command and return the parsed reply.
     *
     * Errors (`-` and `!` wire types) come back as Resp3\RedisException
     * instances; the caller decides whether to throw or route on them.
     */
    public function command(string $name, mixed ...$args): mixed
    {
        $this->ensureConnected();
        $this->write(CommandEncoder::encode([$name, ...$args]));
        return $this->readReply();
    }

    /**
     * Send a batch of commands in one socket write, then read N replies.
     *
     * @param list<list<string|int|float>> $commands
     * @return list<mixed>
     */
    public function pipeline(array $commands): array
    {
        if ($commands === []) {
            return [];
        }
        $this->ensureConnected();

        $payload = '';
        foreach ($commands as $cmd) {
            $payload .= CommandEncoder::encode($cmd);
        }
        $this->write($payload);

        $out = [];
        for ($i = 0, $n = count($commands); $i < $n; $i++) {
            $out[] = $this->readReply();
        }
        return $out;
    }

    public function isConnected(): bool
    {
        return is_resource($this->socket);
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->parser = new Parser();
    }

    /**
     * Block on the socket until one complete reply or push frame arrives, then
     * return it. Used by the pub/sub loop after sending SUBSCRIBE / PSUBSCRIBE
     * to consume server-pushed messages without writing a new command.
     */
    public function readNext(): mixed
    {
        $this->ensureConnected();
        return $this->readReply();
    }

    public function __destruct()
    {
        $this->close();
    }

    // ------------------------------------------------------------------ private

    private function ensureConnected(): void
    {
        if (is_resource($this->socket)) {
            return;
        }

        $scheme = $this->tls ? 'tls' : 'tcp';
        $uri = "{$scheme}://{$this->host}:{$this->port}";
        $flags = STREAM_CLIENT_CONNECT;
        if ($this->persistent) {
            $flags |= STREAM_CLIENT_PERSISTENT;
        }

        $context = stream_context_create($this->tls ? ['ssl' => $this->tlsOptions] : []);

        // The connect step needs a positive timeout; clamp to 5s minimum so
        // a configured read timeout of 0 (used by subscribe loops to block
        // forever between messages) does not also disable connect.
        $connectTimeout = $this->timeout > 0.0 ? $this->timeout : 5.0;

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($uri, $errno, $errstr, $connectTimeout, $flags, $context);
        if ($socket === false) {
            throw new ConnectionException("Connect to {$uri} failed: {$errstr} ({$errno})");
        }

        // Only set a read timeout when one was requested. timeout: 0.0 means
        // "block forever" (subscribe loop pattern); on macOS calling
        // stream_set_timeout(socket, 0, 0) flips to immediate timeout
        // instead of blocking, so we leave the OS default in that case.
        if ($this->timeout > 0.0) {
            stream_set_timeout(
                $socket,
                (int) floor($this->timeout),
                (int) (($this->timeout - floor($this->timeout)) * 1_000_000),
            );
        }
        $this->socket = $socket;
        $this->parser = new Parser();

        $this->handshake();
    }

    private function handshake(): void
    {
        // HELLO 3 negotiates RESP3. With AUTH it also authenticates atomically.
        $hello = ['HELLO', '3'];
        if ($this->password !== null) {
            $hello[] = 'AUTH';
            $hello[] = $this->username ?? 'default';
            $hello[] = $this->password;
        }
        $this->write(CommandEncoder::encode($hello));
        $reply = $this->readReply();
        if ($reply instanceof RedisException) {
            throw new ConnectionException('HELLO failed: ' . $reply->getMessage());
        }

        if ($this->database !== 0) {
            $this->write(CommandEncoder::encode(['SELECT', (string) $this->database]));
            $reply = $this->readReply();
            if ($reply instanceof RedisException) {
                throw new ConnectionException('SELECT failed: ' . $reply->getMessage());
            }
        }
    }

    private function write(string $payload): void
    {
        $remaining = strlen($payload);
        $offset = 0;
        while ($remaining > 0) {
            $written = @fwrite($this->socket, substr($payload, $offset, $remaining));
            if ($written === false || $written === 0) {
                $this->close();
                throw new ConnectionException('Socket write failed or connection closed');
            }
            $offset    += $written;
            $remaining -= $written;
        }
    }

    private function readReply(): mixed
    {
        // Drive the parser by reading chunks until a complete top-level message.
        while (!$this->parser->hasNext()) {
            $chunk = @fread($this->socket, $this->readBufferSize);
            if ($chunk === false || $chunk === '') {
                $meta = is_resource($this->socket) ? stream_get_meta_data($this->socket) : ['timed_out' => true, 'eof' => true];
                $this->close();
                $reason = $meta['timed_out'] ?? false ? 'read timeout' : 'connection closed';
                throw new ConnectionException("Socket read failed: {$reason}");
            }
            $this->parser->feed($chunk);
        }
        return $this->parser->next();
    }
}
