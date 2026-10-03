<?php declare(strict_types=1);

namespace Resp3\Laravel\Tracking;

use APCUIterator;
use RuntimeException;

/**
 * APCu-backed local store for PHP-FPM with persistent connections.
 *
 * A persistent socket outlives the request, so the server keeps tracking
 * keys for it and the next request on the same worker can keep using the
 * entries after it has applied the invalidations queued on the socket.
 * Entries are keyed by $scope (an HMAC over the server connection config and
 * the tracking config, keyed with the app key; see ClientSideCache) and the
 * namespace (`pid:HELLO id`), so workers never read each other's entries.
 * The namespace last used by this worker is remembered in APCu; switching
 * to another one drops the old entries.
 *
 * APCu enforces its own memory limit; $ttl caps the age of an entry in
 * seconds (0 disables the cap).
 */
final class ApcuLocalStore implements LocalStore
{
    private ?string $namespace = null;

    private string $entryPrefix = '';

    public function __construct(
        private readonly string $scope,
        private readonly int $ttl = 60,
    ) {
        if (!self::available()) {
            throw new RuntimeException('ApcuLocalStore needs the apcu extension enabled (apc.enable_cli=1 on the CLI).');
        }
    }

    public static function available(): bool
    {
        return extension_loaded('apcu') && function_exists('apcu_enabled') && apcu_enabled();
    }

    public function get(string $key): ?string
    {
        if ($this->namespace === null) {
            return null;
        }

        $value = apcu_fetch($this->entryPrefix.$key, $found);

        return $found && is_string($value) ? $value : null;
    }

    public function set(string $key, string $value, ?int $ttlSeconds = null): void
    {
        if ($this->namespace === null) {
            return;
        }

        $ttl = ArrayLocalStore::lifetime($this->ttl, $ttlSeconds);
        if ($ttl < 0) {
            apcu_delete($this->entryPrefix.$key);
            return;
        }

        apcu_store($this->entryPrefix.$key, $value, $ttl);
    }

    public function delete(string $key): void
    {
        if ($this->namespace !== null) {
            apcu_delete($this->entryPrefix.$key);
        }
    }

    public function clear(): void
    {
        if ($this->namespace !== null) {
            self::deletePrefix($this->entryPrefix);
        }
    }

    public function namespace(): ?string
    {
        return $this->namespace;
    }

    public function useNamespace(string $namespace): void
    {
        if ($namespace === $this->namespace) {
            return;
        }

        $marker = 'resp3ct-ns:'.$this->scope.':'.getmypid();
        $previous = apcu_fetch($marker, $found);
        if ($found && is_string($previous) && $previous !== $namespace) {
            self::deletePrefix($this->prefixFor($previous));
        }
        if ($this->namespace !== null && $this->namespace !== $previous) {
            self::deletePrefix($this->entryPrefix);
        }

        apcu_store($marker, $namespace);
        $this->namespace = $namespace;
        $this->entryPrefix = $this->prefixFor($namespace);
    }

    private function prefixFor(string $namespace): string
    {
        return 'resp3ct:'.$this->scope.':'.$namespace.':';
    }

    private static function deletePrefix(string $prefix): void
    {
        apcu_delete(new APCUIterator('/^'.preg_quote($prefix, '/').'/s', APC_ITER_KEY));
    }
}
