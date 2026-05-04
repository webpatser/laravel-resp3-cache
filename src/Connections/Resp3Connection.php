<?php declare(strict_types=1);

namespace Resp3\Laravel\Connections;

use BadMethodCallException;
use Closure;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\Events\CommandFailed;
use Resp3\Laravel\Client\Resp3Client;
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
     * @param  Resp3Client  $client
     * @param  array        $config
     */
    public function __construct(Resp3Client $client, array $config = [])
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
            $result = $this->client->command($upper, ...$args);
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
        return $reply === 'OK';
    }

    public function setex($key, $ttl, $value)
    {
        $reply = $this->command('setex', [$key, (string) $ttl, (string) $value]);
        return $reply === 'OK';
    }

    public function setnx($key, $value)
    {
        return (int) $this->command('setnx', [$key, (string) $value]);
    }

    public function del(...$keys)
    {
        // Accept either del('a','b') or del(['a','b']).
        if (count($keys) === 1 && is_array($keys[0])) {
            $keys = $keys[0];
        }
        if ($keys === []) return 0;
        return (int) $this->command('del', $keys);
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

    public function exec()
    {
        $buffered = $this->multiBuffer ?? [];
        $this->multiBuffer = null;

        // Send queued commands now (server has acknowledged each individually
        // with +QUEUED on the round trips above; we collected the ack via
        // parent::command). Actually under Resp3Client we have not sent the
        // queued commands yet — `command()` returned early. So we send them
        // now as a pipeline plus the trailing EXEC.
        if ($buffered !== []) {
            $payload = [];
            foreach ($buffered as $cmd) {
                $payload[] = $cmd;
            }
            $payload[] = ['EXEC'];
            $replies = $this->client->pipeline($payload);
            // The last reply is the EXEC result (an array of per-command replies).
            return end($replies);
        }

        return $this->command('exec', []);
    }

    public function discard()
    {
        $this->multiBuffer = null;
        return $this->command('discard', []);
    }

    // ------------------------------------------------------------------ helpers

    /** RedisStore sometimes wraps args in a single nested array; flatten. */
    private function flattenParameters(array $params): array
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
