<?php declare(strict_types=1);

namespace Resp3\Laravel\Client;

use Closure;
use Resp3\Laravel\Tracking\TrackableClient;
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
 *
 * Persistent sockets (`persistent => true`) outlive the request. Each live
 * client holds its own slot, so two clients with the same config never
 * share a socket, and a socket is handed on to the next request only when
 * the client ends in a clean state (see __destruct()).
 */
final class Resp3Client implements Resp3ClientInterface, TrackableClient
{
    /** Most bytes one drainPushes() call reads before it gives up on the connection. */
    public const DRAIN_LIMIT = 8 * 1024 * 1024;

    /** Commands that leave session state behind that the next request must not inherit. */
    private const TAINTING = ['SELECT', 'AUTH', 'HELLO', 'RESET', 'QUIT', 'MONITOR', 'SUBSCRIBE', 'PSUBSCRIBE', 'SSUBSCRIBE'];

    /** @var array<string, array<int, true>> Persistent slots in use per config, for this process. */
    private static array $slots = [];

    private mixed $socket = null;
    private Parser $parser;
    private int $readBufferSize = 8192;
    private ?ServerCapabilities $capabilities = null;
    private ?Closure $pushListener = null;

    /** Client-side caching: receives pushes before the user's listener (see TrackableClient). */
    private ?Closure $trackingListener = null;

    /** HELLO id of the live connection. */
    private ?int $connectionId = null;

    /** Client-side caching: runs after every handshake (see TrackableClient). */
    private ?Closure $connectHook = null;

    /** @var list<PushMessage> Pushes read during the current command(), handed to the listeners after it. */
    private array $pendingPushes = [];

    /** Persistent slot held by this client until __destruct(), and the config group it belongs to. */
    private ?int $slot = null;
    private ?string $slotGroup = null;

    /** Session state of the live socket, tracked so a dirty persistent socket is never reused. */
    private bool $inMulti = false;
    private bool $watching = false;
    private bool $tainted = false;

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
        private readonly string $persistentId = '',
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
        $this->noteSessionState($name, $args);
        $this->write(CommandEncoder::encode([$name, ...$args]));
        $reply = $this->readReply();
        $this->flushPushes();
        return $reply;
    }

    public function send(string $name, mixed ...$args): void
    {
        $this->ensureConnected();
        // Replies read later through readNext() are outside our bookkeeping.
        $this->tainted = true;
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
            $this->noteSessionState((string) ($cmd[0] ?? ''), array_slice($cmd, 1));
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

    /**
     * Pushes already on the socket, read without blocking. Throws a
     * ConnectionException (after closing the socket) on malformed bytes, on
     * a regular reply with no command outstanding, or when one drain reads
     * more than DRAIN_LIMIT bytes, so a flood cannot grow memory unbounded.
     */
    public function drainPushes(): array
    {
        if (!is_resource($this->socket)) {
            return [];
        }

        $pushes = [];
        $read = 0;
        $eof = false;
        $stray = false;
        stream_set_blocking($this->socket, false);
        try {
            while (true) {
                $chunk = @fread($this->socket, $this->readBufferSize);
                if ($chunk === false || $chunk === '') {
                    $eof = feof($this->socket);
                    break;
                }
                $read += strlen($chunk);
                if ($read > self::DRAIN_LIMIT) {
                    $this->close();
                    throw new ConnectionException('Push drain exceeded ' . self::DRAIN_LIMIT . ' bytes; connection dropped');
                }
                // Take pushes per chunk so the parser queue never holds more
                // than one chunk's worth of frames.
                $this->parser->feed($chunk);
                while (($push = $this->parser->nextPush()) !== null) {
                    $pushes[] = $push;
                }
            }
            while (($push = $this->parser->nextPush()) !== null) {
                $pushes[] = $push;
            }
            $stray = $this->parser->hasNext();
        } catch (RedisException $e) {
            $this->protocolFault($e);
        } finally {
            if (is_resource($this->socket)) {
                stream_set_blocking($this->socket, true);
            }
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
        if (!$this->collectsPushes()) {
            $this->pendingPushes = [];
        }
    }

    public function setTrackingListener(?Closure $listener): void
    {
        $this->trackingListener = $listener;
        if (!$this->collectsPushes()) {
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

    public function connectionId(): ?int
    {
        return is_resource($this->socket) ? $this->connectionId : null;
    }

    public function onConnect(?Closure $hook): void
    {
        $this->connectHook = $hook;
        if ($hook !== null && is_resource($this->socket)) {
            $hook($this);
        }
    }

    public function isPersistent(): bool
    {
        return $this->persistent;
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->connectionId = null;
        $this->parser = self::newParser();
        $this->pendingPushes = [];
        $this->resetSessionState();
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
        $this->tainted = true;
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

    /**
     * A clean persistent socket is left open for the next request (only our
     * handle is dropped); anything else is closed. Clean means: handshake
     * done, not inside MULTI or WATCH, no session-changing command (SELECT,
     * subscribe, ...), no send()/readNext() use, and nothing left unread in
     * the parser. Pushes still in the kernel buffer stay there and reach the
     * next request's handshake, which is what keeps tracking invalidations.
     */
    public function __destruct()
    {
        if ($this->reusable()) {
            $this->socket = null;
        } else {
            $this->close();
        }
        $this->releaseSlot();
    }

    // ------------------------------------------------------------------ private

    private function ensureConnected(): void
    {
        if (is_resource($this->socket)) {
            return;
        }

        // A reused persistent socket can start with stray bytes (the tail of
        // a push frame cut off when the previous request ended), be inside
        // a MULTI left by a request that died, or be closed by the server.
        // handshake() returns false then; retry once on a fresh socket
        // (fclose() drops the persistent one).
        for ($attempt = 1; ; $attempt++) {
            $this->open();

            // A failed handshake must not leave an open socket behind: the
            // next call would skip HELLO (no RESP3, no AUTH, no capabilities).
            try {
                if ($this->handshake()) {
                    return;
                }
            } catch (\Throwable $e) {
                $this->close();
                throw $e;
            }

            $this->close();
            if ($attempt >= 2) {
                throw new ConnectionException('HELLO failed: the persistent socket did not answer the handshake in step');
            }
        }
    }

    private function open(): void
    {
        $scheme = $this->tls ? 'tls' : 'tcp';
        $address = "{$scheme}://{$this->host}:{$this->port}";
        $uri = $address;
        $flags = STREAM_CLIENT_CONNECT;
        if ($this->persistent) {
            // PHP keys persistent sockets by URI; the path keeps clients on
            // another database, user, persistent_id or slot apart.
            $flags |= STREAM_CLIENT_PERSISTENT;
            $uri .= '/' . $this->persistentKey();
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
            throw new ConnectionException("Connect to {$address} failed: {$errstr} ({$errno})");
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
        $this->resetSessionState();
    }

    /**
     * HELLO (with AUTH), SELECT, then the connect hook. Returns false when a
     * persistent socket does not answer the handshake in step; the caller
     * reconnects.
     */
    private function handshake(): bool
    {
        // HELLO 3 negotiates RESP3. With AUTH it also authenticates atomically.
        $hello = ['HELLO', '3'];
        if ($this->password !== null) {
            $hello[] = 'AUTH';
            $hello[] = $this->username ?? 'default';
            $hello[] = $this->password;
        }

        if ($this->persistent) {
            $reply = $this->persistentHello($hello);
            if ($reply === null) {
                return false;
            }
        } else {
            $this->write(CommandEncoder::encode($hello));
            $reply = $this->readReply();
            if ($reply instanceof RedisException) {
                throw new ConnectionException('HELLO failed: ' . $reply->getMessage());
            }
            if (!is_array($reply)) {
                throw new ConnectionException('HELLO failed: unexpected reply type ' . get_debug_type($reply));
            }
        }

        // Detection runs once per client; a reconnect keeps features that
        // were disable()d after an "unknown command" or NOPERM answer.
        $this->capabilities ??= ServerCapabilities::fromHello(
            $reply,
            $this->features,
            fn (): string => $this->infoServer(),
        );
        $this->connectionId = is_int($reply['id'] ?? null) ? $reply['id'] : null;

        // persistentHello() already selected the database.
        if (!$this->persistent && $this->database !== 0) {
            $this->write(CommandEncoder::encode(['SELECT', (string) $this->database]));
            $reply = $this->readReply();
            if ($reply instanceof RedisException) {
                throw new ConnectionException('SELECT failed: ' . $reply->getMessage());
            }
        }

        if ($this->connectHook !== null) {
            ($this->connectHook)($this);
        }

        return true;
    }

    /**
     * Handshake on a persistent socket, which may be a reused one: HELLO,
     * SELECT (always, a previous owner may have switched) and `PING <nonce>`
     * in one write. Exactly the HELLO map and the SELECT OK must come back
     * ahead of the nonce; pushes queued on the socket (tracking
     * invalidations) go to the push queue and do not count. Returns the
     * HELLO map, or null when the socket is out of step (stray replies,
     * malformed bytes, closed by the server, still inside a MULTI), so the
     * caller reconnects on a fresh socket. A genuine HELLO or SELECT error
     * throws.
     *
     * Residual gap: the tail of a push cut off when the previous request
     * ended shows up as extra replies or malformed bytes and is caught. A
     * forged push hidden in a key name, cut inside the last key's payload,
     * can still line up and lose that one key's invalidation. The PTTL cap
     * on local entries and `local_ttl` bound how long such a copy stays.
     *
     * @param  list<string>  $hello
     * @return array<mixed>|null
     */
    private function persistentHello(array $hello): ?array
    {
        $nonce = bin2hex(random_bytes(16));

        try {
            $this->write(
                CommandEncoder::encode($hello)
                . CommandEncoder::encode(['SELECT', (string) $this->database])
                . CommandEncoder::encode(['PING', $nonce]),
            );
            $reply = $this->readReply();
            $selected = $this->readReply();
            $echo = $this->readReply();
        } catch (ConnectionException) {
            // Socket closed by the server, or bytes that do not parse.
            return null;
        }

        if ($reply instanceof RedisException && !is_array($selected)) {
            // Rejected HELLO (bad credentials): SELECT and PING were refused
            // or answered after it, nothing is out of step.
            throw new ConnectionException('HELLO failed: ' . $reply->getMessage());
        }
        if (!is_array($reply) || !is_int($reply['id'] ?? null) || $echo !== $nonce) {
            return null;
        }
        if ($selected instanceof RedisException) {
            throw new ConnectionException('SELECT failed: ' . $selected->getMessage());
        }
        if ($selected !== 'OK') {
            return null;
        }

        return $reply;
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

            $collect = $this->collectsPushes();
            while (($push = $this->parser->nextPush()) !== null) {
                if ($collect) {
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

    /**
     * Hand pushes read during the last command() or pipeline() to the
     * listeners: all of them to the tracking listener first, then to the
     * user's, so a throwing user listener cannot skip an invalidation.
     */
    private function flushPushes(): void
    {
        if ($this->pendingPushes === []) {
            return;
        }
        $pushes = $this->pendingPushes;
        $this->pendingPushes = [];
        if ($this->trackingListener !== null) {
            foreach ($pushes as $push) {
                ($this->trackingListener)($push);
            }
        }
        if ($this->pushListener !== null) {
            foreach ($pushes as $push) {
                ($this->pushListener)($push);
            }
        }
    }

    private function collectsPushes(): bool
    {
        return $this->pushListener !== null || $this->trackingListener !== null;
    }

    /**
     * Follow the session state a command leaves on the socket. Tracked at
     * write time: once MULTI is on the wire the server is in it, whatever
     * the reply says.
     *
     * @param  array<mixed>  $args
     */
    private function noteSessionState(string $name, array $args): void
    {
        $name = strtoupper($name);
        if ($name === 'MULTI') {
            $this->inMulti = true;
        } elseif ($name === 'EXEC' || $name === 'DISCARD') {
            $this->inMulti = false;
            $this->watching = false;
        } elseif ($name === 'WATCH') {
            $this->watching = true;
        } elseif ($name === 'UNWATCH') {
            $this->watching = false;
        } elseif (in_array($name, self::TAINTING, true)
            || ($name === 'CLIENT' && strtoupper((string) ($args[0] ?? '')) === 'REPLY')) {
            $this->tainted = true;
        }
    }

    private function resetSessionState(): void
    {
        $this->inMulti = false;
        $this->watching = false;
        $this->tainted = false;
    }

    /** Whether __destruct() may leave the persistent socket for the next request. */
    private function reusable(): bool
    {
        return $this->persistent
            && is_resource($this->socket)
            && $this->connectionId !== null
            && !$this->inMulti
            && !$this->watching
            && !$this->tainted
            && $this->pendingPushes === []
            && !$this->parser->hasNext()
            && !$this->parser->hasPush();
    }

    /**
     * Path part of the persistent URI: a hash of database, username,
     * persistent_id and this client's slot. The slot is the lowest one not
     * held by another live client of the same config in this process, so
     * live clients never share a socket and a successor reuses it.
     */
    private function persistentKey(): string
    {
        if ($this->slot === null) {
            $this->slotGroup = implode("\0", [
                $this->tls ? 'tls' : 'tcp',
                $this->host,
                (string) $this->port,
                (string) $this->database,
                $this->username ?? '',
                $this->persistentId,
            ]);
            $slot = 0;
            while (isset(self::$slots[$this->slotGroup][$slot])) {
                $slot++;
            }
            self::$slots[$this->slotGroup][$slot] = true;
            $this->slot = $slot;
        }

        return hash('xxh128', implode("\0", [
            (string) $this->database,
            $this->username ?? '',
            $this->persistentId,
            (string) $this->slot,
        ]));
    }

    private function releaseSlot(): void
    {
        if ($this->slot === null || $this->slotGroup === null) {
            return;
        }
        unset(self::$slots[$this->slotGroup][$this->slot]);
        if (self::$slots[$this->slotGroup] === []) {
            unset(self::$slots[$this->slotGroup]);
        }
        $this->slot = null;
        $this->slotGroup = null;
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
