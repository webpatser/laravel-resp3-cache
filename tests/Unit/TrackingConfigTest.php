<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Resp3\Laravel\Tracking\TrackingConfig;

/**
 * The `client_tracking` config block: defaults, validation only when
 * enabled, the apcu local_ttl rule, the scope secret and the fingerprint
 * that decides which stores share a ClientSideCache.
 */
final class TrackingConfigTest extends TestCase
{
    public function test_an_empty_block_gets_the_defaults(): void
    {
        $config = TrackingConfig::fromArray([]);

        $this->assertFalse($config->enabled);
        $this->assertSame('optin', $config->mode);
        $this->assertSame([], $config->prefixes);
        $this->assertFalse($config->noloop);
        $this->assertSame('auto', $config->localStore);
        $this->assertSame(60, $config->localTtl);
        $this->assertSame(10000, $config->maxEntries);
        $this->assertSame(65536, $config->maxValueBytes);
        $this->assertSame(33554432, $config->maxBytes);
        $this->assertSame('', $config->scopeSecret);
    }

    public function test_max_bytes_is_read_and_clamped_at_zero(): void
    {
        $this->assertSame(1024, TrackingConfig::fromArray(['max_bytes' => '1024'])->maxBytes);
        $this->assertSame(0, TrackingConfig::fromArray(['max_bytes' => -5])->maxBytes);
    }

    public function test_a_disabled_block_skips_validation(): void
    {
        $config = TrackingConfig::fromArray([
            'enabled' => false,
            'mode' => 'nope',
            'local_store' => 'redis',
        ]);

        $this->assertFalse($config->enabled);
        $this->assertSame('nope', $config->mode);
    }

    public function test_a_disabled_apcu_block_with_local_ttl_zero_is_accepted(): void
    {
        $config = TrackingConfig::fromArray(['enabled' => false, 'local_store' => 'apcu', 'local_ttl' => 0]);

        $this->assertSame(0, $config->localTtl);
    }

    public function test_an_enabled_block_rejects_an_unknown_mode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('client_tracking.mode');

        TrackingConfig::fromArray(['enabled' => true, 'mode' => 'nope']);
    }

    public function test_an_enabled_block_rejects_an_unknown_local_store(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('client_tracking.local_store');

        TrackingConfig::fromArray(['enabled' => true, 'local_store' => 'redis']);
    }

    public function test_an_enabled_apcu_block_with_local_ttl_zero_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('local_ttl');

        TrackingConfig::fromArray(['enabled' => true, 'local_store' => 'apcu', 'local_ttl' => 0]);
    }

    public function test_the_scope_secret_comes_from_the_second_argument_only(): void
    {
        $this->assertSame('', TrackingConfig::fromArray(['scope_secret' => 'from-user'])->scopeSecret);
        $this->assertSame('app-key', TrackingConfig::fromArray(['scope_secret' => 'from-user'], 'app-key')->scopeSecret);
    }

    public function test_equal_configs_have_equal_fingerprints(): void
    {
        $this->assertSame(
            TrackingConfig::fromArray(['enabled' => true])->fingerprint('p:'),
            TrackingConfig::fromArray(['enabled' => true])->fingerprint('p:'),
        );
    }

    public function test_the_fingerprint_depends_on_prefix_settings_and_secret(): void
    {
        $base = TrackingConfig::fromArray(['enabled' => true], 'k')->fingerprint('p:');

        $this->assertNotSame($base, TrackingConfig::fromArray(['enabled' => true], 'k')->fingerprint('q:'));
        $this->assertNotSame($base, TrackingConfig::fromArray(['enabled' => true, 'local_ttl' => 5], 'k')->fingerprint('p:'));
        $this->assertNotSame($base, TrackingConfig::fromArray(['enabled' => true], 'other')->fingerprint('p:'));
    }

    public function test_optin_tracking_arguments_ignore_the_prefix(): void
    {
        $config = TrackingConfig::fromArray(['enabled' => true, 'noloop' => true]);

        $this->assertSame(['OPTIN', 'NOLOOP'], $config->trackingArguments('p:'));
        $this->assertSame(['OPTIN', 'NOLOOP'], $config->trackingArguments('q:'));
    }

    public function test_bcast_tracking_arguments_default_to_the_store_prefix(): void
    {
        $config = TrackingConfig::fromArray(['enabled' => true, 'mode' => 'bcast']);

        $this->assertSame(['BCAST', 'PREFIX', 'p:'], $config->trackingArguments('p:'));
        $this->assertSame(['BCAST'], $config->trackingArguments(''));
    }
}
