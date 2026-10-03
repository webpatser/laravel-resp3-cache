<?php declare(strict_types=1);

namespace Resp3\Laravel\Connections;

use BadMethodCallException;
use Closure;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\Events\CommandFailed;
use Resp3\Laravel\Client\Resp3Client;
use Resp3\Laravel\Client\Resp3ClientInterface;
use Resp3\Laravel\Client\ServerCapabilities;
use Resp3\Laravel\Client\ServerException;
use Resp3\RedisException;
use Throwable;

/**
 * Laravel Connection wrapper around Resp3Client.
 *
 * Mirrors the surface that RedisStore (and most application code reaching
 * for Redis::connection()) exercises. Command dispatch goes through the
 * parent Connection::command() so command listeners and CommandExecuted /
 * CommandFailed events keep working.
 */
class Resp3Connection extends Connection
{
    /**
     * @param  Resp3ClientInterface  $client  Real socket client or a Sentinel-aware wrapper.
     * @param  array                 $config
     */
    public function __construct(Resp3ClientInterface $client, array $config = [])
    {
        $this->client = $client;
        $this->config = $config;
    }

    public array $config = [];

    /** @var list<list<string>>|null Pending command buffer between MULTI and EXEC. */
    private ?array $multiBuffer = null;

    // ------------------------------------------------------------------ overrides

    /**
     * Replace the parent's `$this->client->{$method}(...)` dispatch with our
     * explicit `Resp3Client::command()` call. Keep CommandExecuted /
     * CommandFailed events firing so userland listeners still see traffic.
     *
     * An error reply is thrown as ServerException (prefix WRONGTYPE, NOAUTH,
     * ...). Inside MULTI the command is buffered and `$this` is returned.
     *
     * @throws ServerException
     */
    public function command($method, array $parameters = [])
    {
        $upper = strtoupper($method);

        // Inside a MULTI we queue, do not round-trip yet. exec() flushes.
        if ($this->multiBuffer !== null && !in_array($upper, ['MULTI', 'EXEC', 'DISCARD'], true)) {
            $this->multiBuffer[] = [$upper, ...$this->flattenParameters($parameters)];
            return $this;
        }

        $args = $this->flattenParameters($parameters);
        $start = microtime(true);

        try {
            $result = $this->throwIfError($this->client->command($upper, ...$args));
        } catch (Throwable $e) {
            $this->events?->dispatch(new CommandFailed($method, $parameters, $e, $this));
            throw $e;
        }

        $elapsed = round((microtime(true) - $start) * 1000, 2);
        $this->events?->dispatch(new CommandExecuted($method, $parameters, $elapsed, $this));

        return $result;
    }

    /** Bridge between the parent's `$this->client->method(...)` style and our client. */
    public function __call($method, $parameters)
    {
        return $this->command($method, $parameters);
    }

    /**
     * Drive a blocking pub/sub loop on a dedicated socket so the original
     * connection stays free for normal commands.
     */
    public function createSubscription($channels, Closure $callback, $method = 'subscribe')
    {
        $loop = new \Resp3\Laravel\PubSub\SubscriptionLoop(
            $this->newSubscribeClient(),
            is_array($channels) ? array_values($channels) : [$channels],
            $callback,
            $method,
        );
        $loop->run();
    }

    /**
     * Build a fresh Resp3Client for a subscribe loop. Read timeout is dropped
     * to zero so the socket blocks forever between messages.
     */
    private function newSubscribeClient(): \Resp3\Laravel\Client\Resp3Client
    {
        $cfg = $this->config;
        $tls = (($cfg['scheme'] ?? '') === 'tls') || (bool) ($cfg['ssl'] ?? false);

        return new \Resp3\Laravel\Client\Resp3Client(
            host: (string) ($cfg['host'] ?? '127.0.0.1'),
            port: (int)    ($cfg['port'] ?? 6379),
            username:      $cfg['username'] ?? null,
            password:      $cfg['password'] ?? null,
            database: (int) ($cfg['database'] ?? 0),
            tls: $tls,
            timeout: 0.0,            // block indefinitely between messages
            persistent: false,       // never share a subscribe socket
            tlsOptions: is_array($cfg['ssl'] ?? null) ? $cfg['ssl'] : [],
        );
    }

    /** Server version and feature gates detected from the HELLO handshake. */
    public function capabilities(): ServerCapabilities
    {
        return $this->client->capabilities();
    }

    // ------------------------------------------------------------------ explicit Redis API

    public function get($key)
    {
        $result = $this->command('get', [$key]);
        return $result === false ? null : $result;
    }

    public function mget(array $keys)
    {
        $result = $this->command('mget', $keys);
        return is_array($result) ? $result : [];
    }

    /**
     * Returns true on OK, false when an NX/XX condition was not met. Inside
     * MULTI the command is queued and true is returned; the real outcome is
     * in the array exec() returns.
     */
    public function set($key, $value, $expireResolution = null, $expireTTL = null, $flag = null)
    {
        $args = [$key, (string) $value];
        if ($expireResolution && $expireTTL !== null) {
            $args[] = strtoupper($expireResolution);   // EX or PX
            $args[] = (string) $expireTTL;
        }
        if ($flag) {
            $args[] = strtoupper($flag);               // NX or XX
        }
        $reply = $this->command('set', $args);
        return $reply === $this || $reply === 'OK';
    }

    /** Same MULTI contract as set(): queued commands return true. */
    public function setex($key, $ttl, $value)
    {
        $reply = $this->command('setex', [$key, (string) $ttl, (string) $value]);
        return $reply === $this || $reply === 'OK';
    }

    /** Returns 1 or 0; inside MULTI `$this` (the reply is in exec()). */
    public function setnx($key, $value)
    {
        $reply = $this->command('setnx', [$key, (string) $value]);
        return $reply === $this ? $this : (int) $reply;
    }

    /** Returns the number of deleted keys; inside MULTI `$this`. */
    public function del(...$keys)
    {
        // Accept either del('a','b') or del(['a','b']).
        if (count($keys) === 1 && is_array($keys[0])) {
            $keys = $keys[0];
        }
        if ($keys === []) return 0;
        $reply = $this->command('del', $keys);
        return $reply === $this ? $this : (int) $reply;
    }

    public function eval($script, $numkeys, ...$arguments)
    {
        return $this->command('eval', [$script, (string) $numkeys, ...$arguments]);
    }

    public function flushdb()
    {
        return $this->command('flushdb', []);
    }

    public function multi()
    {
        $this->multiBuffer = [];
        return $this->command('multi', []);
    }

    /**
     * Run the buffered transaction.
     *
     * Returns the EXEC array as the server sent it: one reply per queued
     * command, where a command that failed at run time (WRONGTYPE, ...) is a
     * Resp3\RedisException element rather than a thrown exception, matching
     * Redis semantics where the other commands still ran. A queue-time error
     * (unknown command, wrong arity) aborts the whole transaction and throws
     * ServerException with prefix EXECABORT.
     *
     * @return list<mixed>
     * @throws ServerException
     */
    public function exec()
    {
        $buffered = $this->multiBuffer ?? [];
        $this->multiBuffer = null;

        // MULTI already went out from multi(); the buffered commands have not.
        // Send them now as one pipeline with the trailing EXEC. Each queued
        // command replies +QUEUED (or a queue-time error), EXEC replies last.
        if ($buffered !== []) {
            $replies = $this->client->pipeline([...$buffered, ['EXEC']]);
            $execReply = array_pop($replies);

            return $this->transactionResult($replies, $execReply);
        }

        return $this->command('exec', []);
    }

    public function discard()
    {
        $this->multiBuffer = null;
        return $this->command('discard', []);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Throw an error reply as ServerException, pass anything else through.
     *
     * @throws ServerException
     */
    protected function throwIfError(mixed $reply): mixed
    {
        if ($reply instanceof RedisException) {
            throw new ServerException($reply);
        }
        return $reply;
    }

    /**
     * Resolve a transaction from the replies to the commands sent before
     * EXEC and the EXEC reply itself. Any error before EXEC, or an error
     * reply to EXEC (EXECABORT), throws; the queue-time error is chained as
     * the previous exception. Nested per-command errors in the EXEC array
     * are returned as is.
     *
     * @param  list<mixed>  $queuedReplies
     * @throws ServerException
     */
    protected function transactionResult(array $queuedReplies, mixed $execReply): mixed
    {
        $queueError = null;
        foreach ($queuedReplies as $reply) {
            if ($reply instanceof RedisException) {
                $queueError = new ServerException($reply);
                break;
            }
        }

        if ($execReply instanceof RedisException) {
            throw new ServerException($execReply, $queueError);
        }
        if ($queueError !== null) {
            throw $queueError;
        }

        return $execReply;
    }

    /** RedisStore sometimes wraps args in a single nested array; flatten. */
    protected function flattenParameters(array $params): array
    {
        $out = [];
        foreach ($params as $p) {
            if (is_array($p)) {
                foreach ($p as $inner) {
                    $out[] = is_scalar($inner) ? (string) $inner : $inner;
                }
            } else {
                $out[] = is_scalar($p) ? (string) $p : $p;
            }
        }
        return $out;
    }

    public function disconnect()
    {
        $this->client->close();
    }
}
