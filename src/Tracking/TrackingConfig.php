<?php declare(strict_types=1);

namespace Resp3\Laravel\Tracking;

use InvalidArgumentException;

/**
 * The `client_tracking` block of a `resp3` cache store:
 *
 *     'client_tracking' => [
 *         'enabled' => env('RESP3_CLIENT_TRACKING', false),
 *         'mode' => 'optin',          // or 'bcast'
 *         'prefixes' => [],           // bcast only; default: the store prefix
 *         'noloop' => false,
 *         'local_store' => 'auto',    // 'array' | 'apcu'
 *         'local_ttl' => 60,
 *         'max_entries' => 10000,     // array store only
 *         'max_bytes' => 33554432,    // array store only: keys plus values
 *         'max_value_bytes' => 65536,
 *     ],
 *
 * `mode` and `local_store` are validated only when `enabled` is true. The
 * apcu store needs `local_ttl` of at least one second; `auto` picks apcu
 * only then (and with a persistent connection and apcu enabled).
 *
 * $scopeSecret keys the APCu entry names (HMAC); the service provider fills
 * it from `app.key`, never from the store's config array.
 */
final class TrackingConfig
{
    public const MODE_OPTIN = 'optin';
    public const MODE_BCAST = 'bcast';

    public const STORE_AUTO = 'auto';
    public const STORE_ARRAY = 'array';
    public const STORE_APCU = 'apcu';

    /** @var array<string, string> fingerprint per store prefix */
    private array $fingerprints = [];

    /**
     * @param  list<string>  $prefixes
     */
    public function __construct(
        public readonly bool $enabled = false,
        public readonly string $mode = self::MODE_OPTIN,
        public readonly array $prefixes = [],
        public readonly bool $noloop = false,
        public readonly string $localStore = self::STORE_AUTO,
        public readonly int $localTtl = 60,
        public readonly int $maxEntries = 10000,
        public readonly int $maxValueBytes = 65536,
        public readonly int $maxBytes = 33554432,
        public readonly string $scopeSecret = '',
    ) {
        if (!$enabled) {
            return;
        }

        if (!in_array($mode, [self::MODE_OPTIN, self::MODE_BCAST], true)) {
            throw new InvalidArgumentException("client_tracking.mode must be 'optin' or 'bcast', got '{$mode}'.");
        }
        if (!in_array($localStore, [self::STORE_AUTO, self::STORE_ARRAY, self::STORE_APCU], true)) {
            throw new InvalidArgumentException("client_tracking.local_store must be 'auto', 'array' or 'apcu', got '{$localStore}'.");
        }
        if ($localStore === self::STORE_APCU && $localTtl < 1) {
            throw new InvalidArgumentException("client_tracking.local_store 'apcu' needs a local_ttl of at least 1 second, got {$localTtl}.");
        }
        if ($localStore === self::STORE_APCU && !ApcuLocalStore::available()) {
            throw new InvalidArgumentException("client_tracking.local_store 'apcu' needs the apcu extension enabled (apc.enable_cli=1 on the CLI).");
        }
    }

    /**
     * @param  array<string, mixed>  $config  the store's `client_tracking` block
     * @param  string  $scopeSecret  secret for the APCu entry names (the app key)
     */
    public static function fromArray(array $config, string $scopeSecret = ''): self
    {
        $prefixes = $config['prefixes'] ?? [];

        return new self(
            enabled: self::bool($config['enabled'] ?? false),
            mode: strtolower((string) ($config['mode'] ?? self::MODE_OPTIN)),
            prefixes: array_values(array_map('strval', is_array($prefixes) ? $prefixes : [$prefixes])),
            noloop: self::bool($config['noloop'] ?? false),
            localStore: strtolower((string) ($config['local_store'] ?? self::STORE_AUTO)),
            localTtl: max(0, (int) ($config['local_ttl'] ?? 60)),
            maxEntries: max(0, (int) ($config['max_entries'] ?? 10000)),
            maxValueBytes: max(0, (int) ($config['max_value_bytes'] ?? 65536)),
            maxBytes: max(0, (int) ($config['max_bytes'] ?? 33554432)),
            scopeSecret: $scopeSecret,
        );
    }

    public function optIn(): bool
    {
        return $this->mode === self::MODE_OPTIN;
    }

    /**
     * The BCAST prefixes: the configured ones, else the store prefix, else
     * none (every key).
     *
     * @return list<string>
     */
    public function prefixesFor(string $storePrefix): array
    {
        if ($this->mode !== self::MODE_BCAST) {
            return [];
        }
        if ($this->prefixes !== []) {
            return $this->prefixes;
        }

        return $storePrefix !== '' ? [$storePrefix] : [];
    }

    /**
     * Arguments after `CLIENT TRACKING ON`.
     *
     * @return list<string>
     */
    public function trackingArguments(string $storePrefix): array
    {
        $args = [];
        if ($this->optIn()) {
            $args[] = 'OPTIN';
        } else {
            $args[] = 'BCAST';
            foreach ($this->prefixesFor($storePrefix) as $prefix) {
                $args[] = 'PREFIX';
                $args[] = $prefix;
            }
        }
        if ($this->noloop) {
            $args[] = 'NOLOOP';
        }

        return $args;
    }

    /**
     * Identity of this config for a store with $storePrefix: stores whose
     * fingerprints match share one ClientSideCache per connection.
     *
     * @internal Used by ClientSideCache.
     */
    public function fingerprint(string $storePrefix): string
    {
        return $this->fingerprints[$storePrefix] ??= hash('sha256', serialize([
            $this->enabled,
            $this->mode,
            $this->prefixes,
            $this->noloop,
            $this->localStore,
            $this->localTtl,
            $this->maxEntries,
            $this->maxValueBytes,
            $this->maxBytes,
            $this->scopeSecret,
            $storePrefix,
        ]));
    }

    private static function bool(mixed $value): bool
    {
        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOL);
        }

        return (bool) $value;
    }
}
