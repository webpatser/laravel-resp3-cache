<?php declare(strict_types=1);
/**
 * Client-side caching demo against a local Redis or Valkey on 127.0.0.1:6379.
 *
 * Builds a Resp3Store with the `client_tracking` block enabled, reads a key
 * twice (the second read is served from the process-local store), lets a
 * second client overwrite the key, reads again (the server pushed an
 * invalidation, so the store fetches the new value) and prints the commands
 * that actually reached the server.
 *
 *   php -d extension=/path/to/resp3.so examples/client_tracking.php
 *
 * Needs Redis 6+ or Valkey 6+ (CLIENT TRACKING).
 */

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\RedisManager;
use Resp3\Laravel\Cache\Resp3Store;
use Resp3\Laravel\Client\Resp3Client;
use Resp3\Laravel\Connectors\Resp3Connector;
use Resp3\Laravel\Tracking\TrackingConfig;
use Resp3\RedisException;

if (!extension_loaded('resp3')) {
    fwrite(STDERR, "ext-resp3 not loaded; run with -d extension=...\n");
    exit(1);
}

$node = ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0];

// The cache store, wired by hand. In a Laravel app this is the config block
// of a store with 'driver' => 'resp3'.
$redis = new RedisManager(new Container(), 'resp3', ['default' => $node]);
$redis->extend('resp3', fn () => new Resp3Connector());

$store = new Resp3Store($redis, 'r3ex:', 'default');
$store->setClientTracking(TrackingConfig::fromArray([
    'enabled' => true,
    'mode' => 'optin',
    'local_store' => 'array',   // 'auto' picks APCu only for persistent connections
    'local_ttl' => 60,
    'max_entries' => 1000,
    'max_value_bytes' => 65536,
]));

// Record every command that reaches the server.
$seen = [];
$events = new Dispatcher();
$events->listen(CommandExecuted::class, function (CommandExecuted $event) use (&$seen): void {
    $seen[] = strtoupper($event->command) . ' ' . implode(' ', array_map('strval', $event->parameters));
});
$store->connection()->setEventDispatcher($events);

// The other application server: a plain client that writes behind our back.
$other = new Resp3Client(host: $node['host'], port: $node['port']);
if ($other->command('CLIENT', 'TRACKINGINFO') instanceof RedisException) {
    fwrite(STDERR, "This server has no CLIENT TRACKING.\n");
    exit(1);
}

$step = static function (string $label, array &$seen): void {
    echo str_pad($label, 46), $seen === [] ? '(no command sent)' : implode(' | ', $seen), "\n";
    $seen = [];
};

$store->forget('greeting');
$seen = [];

$store->put('greeting', 'hello', 60);
$step('put', $seen);

$first = $store->get('greeting');
$step("get -> '{$first}'", $seen);

$second = $store->get('greeting');
$step("get again -> '{$second}'", $seen);

// Serialized the same way the store does, so get() can read it back.
$other->command('SET', 'r3ex:greeting', serialize('hello from elsewhere'));
$third = $store->get('greeting');
$step("get after other SET -> '{$third}'", $seen);

assert($first === 'hello' && $second === 'hello', 'first two reads should return the stored value');
assert($third === 'hello from elsewhere', 'invalidation should force a fresh read');

$store->forget('greeting');
$other->close();

echo "client_tracking.php: OK\n";
