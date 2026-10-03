<?php declare(strict_types=1);

namespace Resp3\Laravel\Client;

use Closure;
use InvalidArgumentException;

/**
 * Server identity and optional command support, derived from the HELLO 3
 * reply of a connection.
 *
 * Detection is version based (see the gates in FEATURES). A `features`
 * config array can force a feature on or off; `'auto'` keeps detection.
 * disable() turns a feature off at runtime, for self-healing when the
 * server answers a feature command with "unknown command" or NOPERM (for
 * example when an ACL or a proxy hides the command). A disabled feature
 * stays off for the lifetime of this object, even when forced on by config.
 */
final class ServerCapabilities
{
    public const DELEX = 'delex';
    public const SET_IF_EQ = 'setIfEq';
    public const MSETEX = 'msetex';
    public const INCREX = 'increx';

    /**
     * Minimum version per server flavour. A flavour that is absent from a
     * gate never supports the feature.
     *
     * MSETEX on Redis needs 8.4.4: earlier 8.4 releases had an ACL bypass.
     */
    private const FEATURES = [
        self::DELEX => ['redis' => '8.4.0'],
        self::SET_IF_EQ => ['redis' => '8.4.0', 'valkey' => '8.1.0'],
        self::MSETEX => ['redis' => '8.4.4', 'valkey' => '9.1.0'],
        self::INCREX => ['redis' => '8.8.0'],
    ];

    /** @var array<string, bool> */
    private array $enabled = [];

    /**
     * @param  array<string, bool|string|null>  $overrides  feature => true|false|'auto'
     */
    public function __construct(
        private readonly string $server,
        private readonly string $version,
        private readonly ?int $id = null,
        private readonly ?string $mode = null,
        array $overrides = [],
    ) {
        foreach (self::FEATURES as $feature => $gate) {
            $minimum = $gate[$this->server] ?? null;
            $this->enabled[$feature] = $minimum !== null
                && version_compare($this->version, $minimum, '>=');
        }

        foreach ($overrides as $feature => $value) {
            $this->assertKnown((string) $feature);
            $forced = self::normaliseOverride((string) $feature, $value);
            if ($forced !== null) {
                $this->enabled[$feature] = $forced;
            }
        }
    }

    /**
     * Build from a HELLO 3 reply map.
     *
     * Valkey answers HELLO with `server: redis, version: 7.2.x` when its
     * Redis compatibility mode is on. In that case $infoServer is called once
     * and must return the `INFO server` text; its `valkey_version` field
     * then decides the flavour and version.
     *
     * @param  array<string, mixed>  $hello
     * @param  array<string, bool|string|null>  $overrides
     * @param  (Closure(): string)|null  $infoServer
     */
    public static function fromHello(array $hello, array $overrides = [], ?Closure $infoServer = null): self
    {
        $server = strtolower(self::scalar($hello['server'] ?? ''));
        $version = self::scalar($hello['version'] ?? '');

        if ($server === 'redis' && str_starts_with($version, '7.2.') && $infoServer !== null) {
            $valkey = self::valkeyVersion($infoServer());
            if ($valkey !== null) {
                $server = 'valkey';
                $version = $valkey;
            }
        }

        $id = $hello['id'] ?? null;
        $mode = $hello['mode'] ?? null;

        return new self(
            server: $server,
            version: $version,
            id: is_int($id) ? $id : null,
            mode: $mode === null ? null : self::scalar($mode),
            overrides: $overrides,
        );
    }

    public function has(string $feature): bool
    {
        $this->assertKnown($feature);

        return $this->enabled[$feature];
    }

    public function delex(): bool
    {
        return $this->enabled[self::DELEX];
    }

    public function setIfEq(): bool
    {
        return $this->enabled[self::SET_IF_EQ];
    }

    public function msetex(): bool
    {
        return $this->enabled[self::MSETEX];
    }

    public function increx(): bool
    {
        return $this->enabled[self::INCREX];
    }

    /** Turn a feature off for the rest of this connection's life. */
    public function disable(string $feature): void
    {
        $this->assertKnown($feature);
        $this->enabled[$feature] = false;
    }

    /** Lowercase flavour as detected: `redis`, `valkey`, or another HELLO `server` value. */
    public function server(): string
    {
        return $this->server;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    /** HELLO `mode`: `standalone`, `cluster` or `sentinel`. */
    public function mode(): ?string
    {
        return $this->mode;
    }

    public function isRedis(): bool
    {
        return $this->server === 'redis';
    }

    public function isValkey(): bool
    {
        return $this->server === 'valkey';
    }

    /** @return list<string> */
    public static function features(): array
    {
        return array_keys(self::FEATURES);
    }

    /**
     * @return array{server: string, version: string, id: ?int, mode: ?string, features: array<string, bool>}
     */
    public function toArray(): array
    {
        return [
            'server' => $this->server,
            'version' => $this->version,
            'id' => $this->id,
            'mode' => $this->mode,
            'features' => $this->enabled,
        ];
    }

    private function assertKnown(string $feature): void
    {
        if (!isset(self::FEATURES[$feature])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown server feature "%s"; expected one of: %s',
                $feature,
                implode(', ', array_keys(self::FEATURES)),
            ));
        }
    }

    /** @return bool|null null means detect */
    private static function normaliseOverride(string $feature, mixed $value): ?bool
    {
        if ($value === null || $value === 'auto') {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new InvalidArgumentException(sprintf(
                'Feature "%s" must be true, false or "auto"',
                $feature,
            ));
        }

        return $parsed;
    }

    private static function valkeyVersion(string $info): ?string
    {
        if (preg_match('/^valkey_version:([0-9][0-9A-Za-z.\-]*)\s*$/m', $info, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private static function scalar(mixed $value): string
    {
        if ($value instanceof \Resp3\VerbatimString) {
            return $value->value;
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
