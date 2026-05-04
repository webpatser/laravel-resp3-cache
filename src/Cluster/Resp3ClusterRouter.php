<?php declare(strict_types=1);

namespace Resp3\Laravel\Cluster;

use Resp3\Laravel\Client\ConnectionException;
use Resp3\Laravel\Client\Resp3Client;
use Resp3\RedisException;
use RuntimeException;

/**
 * Routes Redis commands across a cluster of Resp3Client connections.
 *
 * Topology is discovered lazily on the first command via CLUSTER SHARDS
 * (Redis 7+) with a fallback to CLUSTER SLOTS (6.x). The slot map is cached
 * and refreshed on MOVED responses. ASK responses trigger a one-shot
 * redirect via the ASKING command without updating the cached slot map.
 *
 * Multi-key commands must hash to a single slot; the router rejects
 * cross-slot calls with a CROSSSLOT error to match Redis cluster semantics.
 */
final class Resp3ClusterRouter
{
    /** @var array<string, Resp3Client> Keyed by canonical "host:port". */
    private array $nodes = [];

    /** @var array<int, string> slot -> master "host:port" */
    private array $masters = [];

    /** @var array<int, list<string>> slot -> replica "host:port" list */
    private array $replicas = [];

    /** @var array<string, true> READONLY already sent on these node addresses. */
    private array $readonlySent = [];

    private bool $topologyLoaded = false;

    /**
     * @param  list<array{host:string,port:int}>  $seedNodes
     */
    public function __construct(
        private readonly array $seedNodes,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly bool $tls = false,
        private readonly float $timeout = 5.0,
        private readonly bool $persistent = false,
        private readonly array $tlsOptions = [],
        private readonly bool $readReplicas = false,
        private readonly int $maxRetries = 5,
    ) {
        if ($seedNodes === []) {
            throw new \InvalidArgumentException('At least one seed node is required');
        }
    }

    /**
     * Send one command. Routes by slot, retries on MOVED/ASK with backoff.
     */
    public function command(string $name, mixed ...$args): mixed
    {
        $upper   = strtoupper($name);
        $keys    = $this->extractKeys($upper, $args);
        $slot    = $this->resolveSlotForKeys($upper, $keys);
        $isRead  = $this->readReplicas && CommandClassifier::isReadOnly($upper);

        $askingNext = null;          // "host:port" if next command must use ASKING
        $delayMs    = 10;

        for ($attempt = 0; $attempt < $this->maxRetries; $attempt++) {
            $addr   = $askingNext ?? $this->pickAddrForSlot($slot, $isRead);
            $client = $this->connectionFor($addr, $isRead);

            try {
                if ($askingNext !== null) {
                    $client->command('ASKING');
                }
                $reply = $client->command($name, ...$args);
            } catch (ConnectionException $e) {
                // Hard socket error: drop the node and retry on a fresh map.
                unset($this->nodes[$addr]);
                $this->reloadTopology();
                $askingNext = null;
                usleep($delayMs * 1000);
                $delayMs   = min($delayMs * 2, 200);
                continue;
            }

            if ($reply instanceof RedisException) {
                $redirect = RedirectionParser::parse($reply->getMessage());
                if ($redirect !== null) {
                    $newAddr = "{$redirect['host']}:{$redirect['port']}";
                    if ($redirect['kind'] === 'MOVED') {
                        // Update the slot map; treat as authoritative for now.
                        $this->masters[$redirect['slot']] = $newAddr;
                        $askingNext = null;
                    } else {
                        // ASK: one-shot redirect, don't touch the slot map.
                        $askingNext = $newAddr;
                    }
                    continue;
                }
            }

            return $reply;
        }

        throw new RuntimeException("Cluster command failed after {$this->maxRetries} retries");
    }

    /**
     * Send a same-slot pipeline. Useful for MULTI/EXEC after CROSSSLOT validation.
     *
     * @param  list<list<string>>  $commands  Each inner list: [name, ...args]
     */
    public function pipelineSameSlot(array $commands, string $hashKey): array
    {
        $slot   = CRC16::slot($hashKey);
        $addr   = $this->pickAddrForSlot($slot, isRead: false);
        $client = $this->connectionFor($addr, isReplica: false);
        return $client->pipeline($commands);
    }

    /**
     * Run a command on every master and return the per-node replies.
     * Used by FLUSHDB, KEYS, SCAN.
     *
     * @return array<string, mixed> address -> reply
     */
    public function broadcastToMasters(string $name, mixed ...$args): array
    {
        $this->ensureTopology();
        $addrs = array_values(array_unique($this->masters));
        $out = [];
        foreach ($addrs as $addr) {
            $client = $this->connectionFor($addr, isReplica: false);
            $out[$addr] = $client->command($name, ...$args);
        }
        return $out;
    }

    public function disconnect(): void
    {
        foreach ($this->nodes as $client) {
            $client->close();
        }
        $this->nodes = [];
        $this->readonlySent = [];
    }

    // ------------------------------------------------------------------ topology

    private function ensureTopology(): void
    {
        if ($this->topologyLoaded) return;
        $this->reloadTopology();
    }

    private function reloadTopology(): void
    {
        $errors = [];
        foreach ($this->seedNodes as $seed) {
            $addr = "{$seed['host']}:{$seed['port']}";
            try {
                $client = $this->connectionFor($addr, isReplica: false);
                // Try CLUSTER SHARDS first (Redis 7+), fall back to SLOTS.
                $reply = $client->command('CLUSTER', 'SHARDS');
                if ($reply instanceof RedisException) {
                    $reply = $client->command('CLUSTER', 'SLOTS');
                    if ($reply instanceof RedisException) {
                        $errors[] = "{$addr}: " . $reply->getMessage();
                        continue;
                    }
                    $this->ingestSlots($reply);
                } else {
                    $this->ingestShards($reply);
                }
                $this->topologyLoaded = true;
                return;
            } catch (ConnectionException $e) {
                $errors[] = "{$addr}: " . $e->getMessage();
            }
        }
        throw new ConnectionException('Could not load cluster topology from any seed: ' . implode('; ', $errors));
    }

    /** CLUSTER SHARDS reply (Redis 7+) shape: list of shard maps. */
    private function ingestShards(array $shards): void
    {
        $this->masters = [];
        $this->replicas = [];
        foreach ($shards as $shard) {
            $slotsMap = $shard['slots'] ?? [];
            $nodes    = $shard['nodes'] ?? [];

            $masterAddr = null;
            $replicaAddrs = [];
            foreach ($nodes as $n) {
                $addr = ($n['endpoint'] ?? $n['ip'] ?? '127.0.0.1') . ':' . ($n['port'] ?? 6379);
                if (($n['role'] ?? '') === 'master') {
                    $masterAddr = $addr;
                } else {
                    $replicaAddrs[] = $addr;
                }
            }
            if ($masterAddr === null) continue;

            // slotsMap is a flat list of pairs: [start1, end1, start2, end2, ...]
            for ($i = 0, $n = count($slotsMap); $i < $n; $i += 2) {
                $start = (int) $slotsMap[$i];
                $end   = (int) $slotsMap[$i + 1];
                for ($s = $start; $s <= $end; $s++) {
                    $this->masters[$s] = $masterAddr;
                    if ($replicaAddrs !== []) {
                        $this->replicas[$s] = $replicaAddrs;
                    }
                }
            }
        }
    }

    /** CLUSTER SLOTS reply (Redis 6) shape: list of [start, end, master, replica1, replica2, ...]. */
    private function ingestSlots(array $slots): void
    {
        $this->masters = [];
        $this->replicas = [];
        foreach ($slots as $entry) {
            $start = (int) $entry[0];
            $end   = (int) $entry[1];
            // entry[2] = master node info: [host, port, id, ...]
            $masterAddr = "{$entry[2][0]}:{$entry[2][1]}";
            $replicaAddrs = [];
            for ($i = 3, $n = count($entry); $i < $n; $i++) {
                $replicaAddrs[] = "{$entry[$i][0]}:{$entry[$i][1]}";
            }
            for ($s = $start; $s <= $end; $s++) {
                $this->masters[$s] = $masterAddr;
                if ($replicaAddrs !== []) {
                    $this->replicas[$s] = $replicaAddrs;
                }
            }
        }
    }

    // ------------------------------------------------------------------ routing helpers

    private function pickAddrForSlot(int $slot, bool $isRead): string
    {
        $this->ensureTopology();
        if ($isRead && isset($this->replicas[$slot]) && $this->replicas[$slot] !== []) {
            $list = $this->replicas[$slot];
            return $list[array_rand($list)];
        }
        if (!isset($this->masters[$slot])) {
            // Topology has gaps; refresh once and retry.
            $this->reloadTopology();
        }
        return $this->masters[$slot] ?? throw new RuntimeException("No master for slot {$slot}");
    }

    private function connectionFor(string $addr, bool $isReplica): Resp3Client
    {
        if (!isset($this->nodes[$addr])) {
            [$host, $port] = explode(':', $addr, 2);
            $this->nodes[$addr] = new Resp3Client(
                host: $host,
                port: (int) $port,
                username: $this->username,
                password: $this->password,
                tls: $this->tls,
                timeout: $this->timeout,
                persistent: $this->persistent,
                tlsOptions: $this->tlsOptions,
            );
        }
        $client = $this->nodes[$addr];
        if ($isReplica && !isset($this->readonlySent[$addr])) {
            $reply = $client->command('READONLY');
            if (!($reply instanceof RedisException)) {
                $this->readonlySent[$addr] = true;
            }
        }
        return $client;
    }

    /**
     * Determine the slot for a command's keys. Throws CROSSSLOT if multiple
     * keys do not hash to the same slot. Returns 0 for keyless commands so
     * they go to whatever node was picked first (CLUSTER itself, INFO, etc).
     *
     * @param  list<mixed>  $keys
     */
    private function resolveSlotForKeys(string $command, array $keys): int
    {
        if ($keys === []) {
            $this->ensureTopology();
            // Pick a deterministic slot owned by some master so connectionFor() works.
            return array_key_first($this->masters) ?? 0;
        }
        $slot = CRC16::slot((string) $keys[0]);
        for ($i = 1, $n = count($keys); $i < $n; $i++) {
            if (CRC16::slot((string) $keys[$i]) !== $slot) {
                throw new RuntimeException(
                    "CROSSSLOT Keys in request don't hash to the same slot (use a {hash tag} to group them)"
                );
            }
        }
        return $slot;
    }

    /**
     * Extract the key arguments for a command. Most commands take keys as
     * leading positional arguments; we whitelist a few commands that take
     * keys at non-standard positions.
     *
     * @param  list<mixed>  $args
     * @return list<mixed>
     */
    private function extractKeys(string $command, array $args): array
    {
        return match ($command) {
            'GET','SET','SETEX','SETNX','INCR','DECR','INCRBY','DECRBY',
            'EXPIRE','PERSIST','TTL','PTTL','TYPE','STRLEN','GETRANGE',
            'BITCOUNT','BITPOS','APPEND','GETSET',
            'HSET','HGET','HMSET','HMGET','HGETALL','HKEYS','HVALS','HLEN',
            'HEXISTS','HSTRLEN','HDEL','HINCRBY','HINCRBYFLOAT',
            'LPUSH','RPUSH','LPOP','RPOP','LLEN','LRANGE','LINDEX','LSET',
            'LTRIM','LINSERT','LREM','LPOS',
            'SADD','SREM','SMEMBERS','SCARD','SISMEMBER','SMISMEMBER',
            'SRANDMEMBER','SPOP',
            'ZADD','ZRANGE','ZRANGEBYSCORE','ZRANGEBYLEX','ZREVRANGE',
            'ZSCORE','ZCARD','ZCOUNT','ZRANK','ZREVRANK','ZLEXCOUNT',
            'ZINCRBY','ZREM','ZPOPMIN','ZPOPMAX',
            'XADD','XREAD','XRANGE','XREVRANGE','XLEN','XDEL','XACK',
                => $args === [] ? [] : [$args[0]],
            'MGET' => $args,
            'MSET','MSETNX' => $this->everyOther($args, 0),
            'DEL','EXISTS','UNLINK','TOUCH' => $args,
            'EVAL','EVALSHA' => count($args) >= 2
                ? array_slice($args, 2, (int) $args[1])
                : [],
            default => [],
        };
    }

    /** @param list<mixed> $args @return list<mixed> */
    private function everyOther(array $args, int $startOffset): array
    {
        $out = [];
        for ($i = $startOffset; $i < count($args); $i += 2) {
            $out[] = $args[$i];
        }
        return $out;
    }
}
