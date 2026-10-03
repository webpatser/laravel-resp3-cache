<?php declare(strict_types=1);

namespace Resp3\Laravel\Tracking;

/**
 * Process-local copy of server values for client-side caching.
 *
 * Entries live in a namespace that belongs to exactly one server
 * connection (`pid:HELLO id`), because the server tracks keys per
 * connection and only that connection receives the invalidations. Keys are
 * the full server keys (store prefix included); values are the raw
 * serialized strings as the server returned them.
 */
interface LocalStore
{
    /** The cached raw value, or null on a miss or after local_ttl. */
    public function get(string $key): ?string;

    /**
     * Store $value for at most $ttlSeconds (the server TTL), capped by the
     * store's own ttl. Null uses the store ttl alone. A lifetime below one
     * second stores nothing.
     */
    public function set(string $key, string $value, ?int $ttlSeconds = null): void;

    public function delete(string $key): void;

    /** Drop every entry of the current namespace. */
    public function clear(): void;

    /** The current namespace, null before the first useNamespace(). */
    public function namespace(): ?string;

    /**
     * Switch to $namespace. Entries of a different namespace this store
     * held before (in this process) are dropped; switching to the same
     * namespace keeps them.
     */
    public function useNamespace(string $namespace): void;
}
