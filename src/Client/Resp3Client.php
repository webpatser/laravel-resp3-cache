<?php declare(strict_types=1);

namespace Resp3\Laravel\Client;

use Closure;
use Resp3\Parser;
use Resp3\PushMessage;
use Resp3\RedisException;

/**
 * Synchronous TCP Redis client backed by the ext-resp3 parser.
 *
 * Each command writes a RESP-encoded request to the socket, then drives the
 * parser by reading bytes until a complete top-level reply lands. No fibers,
 * no event loop, no callbacks. Drop-in for php-fpm / sync request workflows.
 *
 * The parser runs in push-queue mode: push frames (pub/sub messages,
 * tracking invalidations) never take the place of a command reply, so
 * pipelines stay aligned when a push arrives between two replies.
 */
final class Resp3Client implements Resp3ClientInterface
{
    private mixed $socket = null;
    private Parser $parser;
    private int $readBufferSize = 8192;
    private ?ServerCapabilities $capabilities = null;
    private ?Closure $pushListener = null;

    /** @var list<PushMessage> Pushes read during the current command(), handed to the listener after it. */
    private array $pendingPushes = [];

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
        private readonly array $features = [],
    ) {
        $this->parser = self::newParser();
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
        $reply = $this->readReply();
        $this->flushPushes();
        return $reply;
    }

    public function send(string $name, mixed ...$args): void
    {
        $this->ensureConnected();
        $this->write(CommandEncoder::encode([$name, ...$args]));
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
        $this->flushPushes();
        return $out;
    }

    public function drainPushes(): array
    {
        if (!is_resource($this->socket)) {
            return [];
        }

        $eof = false;
        stream_set_blocking($this->socket, false);
        try {
            while (true) {
                $chunk = @fread($this->socket, $this->readBufferSize);
                if ($chunk === false || $chunk === '') {
                    $eof = feof($this->socket);
                    break;
                }
                $this->parser->feed($chunk);
            }
        } finally {
            if (is_resource($this->socket)) {
                stream_set_blocking($this->socket, true);
            }
        }

        $pushes = [];
        try {
            while (($push = $this->parser->nextPush()) !== null) {
                $pushes[] = $push;
            }
            $stray = $this->parser->hasNext();
        } catch (RedisException $e) {
            $this->protocolFault($e);
        }

        if ($stray) {
            // A regular reply with no command outstanding: the reply stream
            // is out of step with our requests and cannot be trusted.
            $this->close();
            throw new ConnectionException('Unexpected reply with no command outstanding');
        }
        if ($eof) {
            $this->close();
        }
        return $pushes;
    }

    public function setPushListener(?Closure $listener): void
    {
        $this->pushListener = $listener;
        if ($listener === null) {
            $this->pendingPushes = [];
        }
    }

    public function capabilities(): ServerCapabilities
    {
        $this->ensureConnected();
        return $this->capabilities
            ?? throw new ConnectionException('Server capabilities unavailable: handshake did not complete');
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
        $this->parser = self::newParser();
        $this->pendingPushes = [];
    }

    /**
     * Block on the socket until one push frame or regular reply arrives, then
     * return it. Used by the pub/sub loop after send('SUBSCRIBE', ...) to
     * consume server-pushed messages without writing a new command. Queued
     * pushes come first, in wire order.
     */
    public function readNext(): mixed
    {
        $this->ensureConnected();
        try {
            while (true) {
                if ($this->parser->hasPush()) {
                    return $this->parser->nextPush();
                }
                if ($this->parser->hasNext()) {
                    return $this->parser->next();
                }
                $this->fill();
            }
        } catch (RedisException $e) {
            $this->protocolFault($e);
        }
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
        $this->parser = self::newParser();

        // A failed handshake must not leave an open socket behind: the next
        // call would skip HELLO (no RESP3, no AUTH, no capabilities).
        try {
            $this->handshake();
        } catch (\Throwable $e) {
            $this->close();
            throw $e;
        }
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
        if (!is_array($reply)) {
            throw new ConnectionException('HELLO failed: unexpected reply type ' . get_debug_type($reply));
        }

        // Detection runs once per client; a reconnect keeps features that
        // were disable()d after an "unknown command" or NOPERM answer.
        $this->capabilities ??= ServerCapabilities::fromHello(
            $reply,
            $this->features,
            fn (): string => $this->infoServer(),
        );

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

    /**
     * Read one regular reply. Pushes that arrived before it, or that sit
     * fully buffered behind it, move to $pendingPushes when a listener is
     * set and are dropped otherwise, so the parser queue cannot grow.
     */
    private function readReply(): mixed
    {
        try {
            while (!$this->parser->hasNext()) {
                $this->fill();
            }
            $reply = $this->parser->next();

            while (($push = $this->parser->nextPush()) !== null) {
                if ($this->pushListener !== null) {
                    $this->pendingPushes[] = $push;
                }
            }
        } catch (RedisException $e) {
            $this->protocolFault($e);
        }
        return $reply;
    }

    /** Block for the next chunk of bytes and feed it to the parser. */
    private function fill(): void
    {
        $chunk = @fread($this->socket, $this->readBufferSize);
        if ($chunk === false || $chunk === '') {
            $meta = is_resource($this->socket) ? stream_get_meta_data($this->socket) : ['timed_out' => true, 'eof' => true];
            $this->close();
            $reason = $meta['timed_out'] ?? false ? 'read timeout' : 'connection closed';
            throw new ConnectionException("Socket read failed: {$reason}");
        }
        $this->parser->feed($chunk);
    }

    /**
     * A PROTOCOL fault means malformed wire bytes: the parser is latched and
     * unread bytes may still sit in the socket, so reset() alone is not
     * enough. Drop the connection; the next command reconnects. Any other
     * RedisException is not a wire fault and is rethrown unchanged.
     */
    private function protocolFault(RedisException $e): never
    {
        if ($e->prefix !== 'PROTOCOL') {
            throw $e;
        }
        $this->close();
        throw new ConnectionException('protocol error: ' . $e->getMessage(), 0, $e);
    }

    /** Hand pushes read during the last command() or pipeline() to the listener. */
    private function flushPushes(): void
    {
        if ($this->pendingPushes === []) {
            return;
        }
        $pushes = $this->pendingPushes;
        $this->pendingPushes = [];
        if ($this->pushListener === null) {
            return;
        }
        foreach ($pushes as $push) {
            ($this->pushListener)($push);
        }
    }

    /**
     * `INFO server` text, for telling Valkey in Redis compatibility mode
     * apart from Redis 7.2. An error reply (for example NOPERM) yields ''.
     */
    private function infoServer(): string
    {
        $this->write(CommandEncoder::encode(['INFO', 'server']));
        $reply = $this->readReply();
        if ($reply instanceof \Resp3\VerbatimString) {
            return $reply->value;
        }
        return is_string($reply) ? $reply : '';
    }

    private static function newParser(): Parser
    {
        return new Parser(queuePushes: true);
    }
}
