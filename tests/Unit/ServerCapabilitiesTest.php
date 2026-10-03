<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Resp3\Laravel\Client\ServerCapabilities;

final class ServerCapabilitiesTest extends TestCase
{
    /** @return array<string, array{string, string, array<string, bool>}> */
    public static function versionMatrix(): array
    {
        return [
            'redis 8.4.3' => ['redis', '8.4.3', ['delex' => true, 'setIfEq' => true, 'msetex' => false, 'increx' => false]],
            'redis 8.4.4' => ['redis', '8.4.4', ['delex' => true, 'setIfEq' => true, 'msetex' => true, 'increx' => false]],
            'redis 8.10.1' => ['redis', '8.10.1', ['delex' => true, 'setIfEq' => true, 'msetex' => true, 'increx' => true]],
            'redis 7.4.0' => ['redis', '7.4.0', ['delex' => false, 'setIfEq' => false, 'msetex' => false, 'increx' => false]],
            'valkey 8.0.0' => ['valkey', '8.0.0', ['delex' => false, 'setIfEq' => false, 'msetex' => false, 'increx' => false]],
            'valkey 8.1.0' => ['valkey', '8.1.0', ['delex' => false, 'setIfEq' => true, 'msetex' => false, 'increx' => false]],
            'valkey 9.1.0' => ['valkey', '9.1.0', ['delex' => false, 'setIfEq' => true, 'msetex' => true, 'increx' => false]],
            'valkey 9.1.2' => ['valkey', '9.1.2', ['delex' => false, 'setIfEq' => true, 'msetex' => true, 'increx' => false]],
        ];
    }

    /** @param array<string, bool> $expected */
    #[DataProvider('versionMatrix')]
    public function test_gates_follow_server_and_version(string $server, string $version, array $expected): void
    {
        $caps = ServerCapabilities::fromHello(['server' => $server, 'version' => $version, 'id' => 7, 'mode' => 'standalone']);

        $this->assertSame($server, $caps->server());
        $this->assertSame($version, $caps->version());
        foreach ($expected as $feature => $on) {
            $this->assertSame($on, $caps->has($feature), "{$server} {$version} {$feature}");
        }
        $this->assertSame($expected['delex'], $caps->delex());
        $this->assertSame($expected['setIfEq'], $caps->setIfEq());
        $this->assertSame($expected['msetex'], $caps->msetex());
        $this->assertSame($expected['increx'], $caps->increx());
    }

    public function test_hello_fields_are_exposed(): void
    {
        $caps = ServerCapabilities::fromHello(['server' => 'Valkey', 'version' => '9.1.2', 'id' => 42, 'mode' => 'cluster']);

        $this->assertSame('valkey', $caps->server());
        $this->assertSame(42, $caps->id());
        $this->assertSame('cluster', $caps->mode());
        $this->assertTrue($caps->isValkey());
        $this->assertFalse($caps->isRedis());
    }

    public function test_ambiguous_redis_7_2_answer_falls_back_to_info_server(): void
    {
        $calls = 0;
        $info = function () use (&$calls): string {
            $calls++;

            return "# Server\r\nredis_version:7.2.4\r\nvalkey_version:9.1.2\r\nos:Linux\r\n";
        };

        $caps = ServerCapabilities::fromHello(['server' => 'redis', 'version' => '7.2.4'], [], $info);

        $this->assertSame(1, $calls);
        $this->assertSame('valkey', $caps->server());
        $this->assertSame('9.1.2', $caps->version());
        $this->assertTrue($caps->msetex());
        $this->assertFalse($caps->delex());
    }

    public function test_ambiguous_answer_without_valkey_version_stays_redis(): void
    {
        $caps = ServerCapabilities::fromHello(
            ['server' => 'redis', 'version' => '7.2.4'],
            [],
            fn (): string => "# Server\r\nredis_version:7.2.4\r\n",
        );

        $this->assertSame('redis', $caps->server());
        $this->assertSame('7.2.4', $caps->version());
        $this->assertFalse($caps->setIfEq());
    }

    public function test_info_is_not_called_for_unambiguous_replies(): void
    {
        $called = false;
        $info = function () use (&$called): string {
            $called = true;

            return '';
        };

        ServerCapabilities::fromHello(['server' => 'redis', 'version' => '8.4.4'], [], $info);
        ServerCapabilities::fromHello(['server' => 'valkey', 'version' => '9.1.2'], [], $info);

        $this->assertFalse($called);
    }

    public function test_config_can_force_features_on_and_off(): void
    {
        $caps = ServerCapabilities::fromHello(
            ['server' => 'valkey', 'version' => '9.1.2'],
            ['delex' => true, 'msetex' => false, 'setIfEq' => 'auto'],
        );

        $this->assertTrue($caps->delex());
        $this->assertFalse($caps->msetex());
        $this->assertTrue($caps->setIfEq());
        $this->assertFalse($caps->increx());
    }

    public function test_override_with_unknown_feature_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ServerCapabilities::fromHello(['server' => 'redis', 'version' => '8.10.1'], ['bogus' => true]);
    }

    public function test_override_with_invalid_value_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ServerCapabilities::fromHello(['server' => 'redis', 'version' => '8.10.1'], ['delex' => 'maybe']);
    }

    public function test_disable_turns_a_feature_off(): void
    {
        $caps = ServerCapabilities::fromHello(['server' => 'redis', 'version' => '8.10.1']);
        $this->assertTrue($caps->delex());

        $caps->disable(ServerCapabilities::DELEX);

        $this->assertFalse($caps->delex());
        $this->assertFalse($caps->has('delex'));
        $this->assertTrue($caps->msetex(), 'other features are untouched');
    }

    public function test_disable_beats_a_forced_on_override(): void
    {
        $caps = ServerCapabilities::fromHello(['server' => 'redis', 'version' => '8.10.1'], ['msetex' => true]);

        $caps->disable('msetex');

        $this->assertFalse($caps->msetex());
    }

    public function test_has_and_disable_reject_unknown_features(): void
    {
        $caps = ServerCapabilities::fromHello(['server' => 'redis', 'version' => '8.10.1']);

        try {
            $caps->has('nope');
            $this->fail('has() accepted an unknown feature');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        $caps->disable('nope');
    }

    public function test_to_array_reports_state(): void
    {
        $caps = ServerCapabilities::fromHello(['server' => 'valkey', 'version' => '9.1.2', 'id' => 3, 'mode' => 'standalone']);

        $array = $caps->toArray();

        $this->assertSame('valkey', $array['server']);
        $this->assertSame('9.1.2', $array['version']);
        $this->assertSame(['delex' => false, 'setIfEq' => true, 'msetex' => true, 'increx' => false], $array['features']);
    }
}
