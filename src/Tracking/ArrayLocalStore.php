<?php declare(strict_types=1);

namespace Resp3\Laravel\Tracking;

/**
 * In-memory local store for one process (Octane, queue workers, CLI).
 *
 * Bounded by $maxEntries and by $maxBytes (the sum of `strlen(key) +
 * strlen(value)` over all entries) with insertion-order LRU: a hit moves the
 * entry to the end, an insert past either limit evicts from the front. An
 * entry larger than $maxBytes on its own is not stored; a limit of 0 stores
 * nothing. $ttl caps the age of an entry in seconds (0 disables the cap);
 * invalidation pushes are the primary eviction path.
 */
final class ArrayLocalStore implements LocalStore
{
    /** @var array<string, array{0: string, 1: float}> key => [value, expires at (0.0 = never)] */
    private array $entries = [];

    /** Sum of strlen(key) + strlen(value) over $entries. */
    private int $bytes = 0;

    private ?string $namespace = null;

    public function __construct(
        private readonly int $maxEntries = 10000,
        private readonly int $ttl = 60,
        private readonly int $maxBytes = 33554432,
    ) {}

    public function get(string $key): ?string
    {
        $entry = $this->entries[$key] ?? null;
        if ($entry === null) {
            return null;
        }

        if ($entry[1] !== 0.0 && $entry[1] <= microtime(true)) {
            $this->delete($key);
            return null;
        }

        // Re-insert at the end: most recently used.
        unset($this->entries[$key]);
        $this->entries[$key] = $entry;

        return $entry[0];
    }

    public function set(string $key, string $value, ?int $ttlSeconds = null): void
    {
        $this->delete($key);

        $ttl = self::lifetime($this->ttl, $ttlSeconds);
        $size = strlen($key) + strlen($value);
        if ($this->maxEntries < 1 || $this->maxBytes < 1 || $ttl < 0 || $size > $this->maxBytes) {
            return;
        }

        $this->entries[$key] = [$value, $ttl > 0 ? microtime(true) + $ttl : 0.0];
        $this->bytes += $size;

        while (count($this->entries) > $this->maxEntries || $this->bytes > $this->maxBytes) {
            $this->delete((string) array_key_first($this->entries));
        }
    }

    public function delete(string $key): void
    {
        $entry = $this->entries[$key] ?? null;
        if ($entry === null) {
            return;
        }

        unset($this->entries[$key]);
        $this->bytes -= strlen($key) + strlen($entry[0]);
    }

    public function clear(): void
    {
        $this->entries = [];
        $this->bytes = 0;
    }

    public function namespace(): ?string
    {
        return $this->namespace;
    }

    public function useNamespace(string $namespace): void
    {
        if ($namespace !== $this->namespace) {
            $this->clear();
            $this->namespace = $namespace;
        }
    }

    /**
     * Effective lifetime in seconds: 0 = no expiry, -1 = do not store.
     *
     * @internal Shared with ApcuLocalStore.
     */
    public static function lifetime(int $storeTtl, ?int $ttlSeconds): int
    {
        if ($ttlSeconds === null) {
            return max(0, $storeTtl);
        }
        if ($ttlSeconds < 1) {
            return -1;
        }

        return $storeTtl > 0 ? min($storeTtl, $ttlSeconds) : $ttlSeconds;
    }

    /** Number of entries held, expired ones included until touched. */
    public function count(): int
    {
        return count($this->entries);
    }

    /** Bytes held (keys plus values), expired entries included until touched. */
    public function bytes(): int
    {
        return $this->bytes;
    }
}
