<?php declare(strict_types=1);
/**
 * Smoke test for Resp3Client against a local Redis or Valkey on 127.0.0.1:6379.
 *
 *   php -d extension=/path/to/resp3.so examples/smoke.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Resp3\Laravel\Client\Resp3Client;
use Resp3\RedisException;

if (!extension_loaded('resp3')) {
    fwrite(STDERR, "ext-resp3 not loaded; run with -d extension=...\n");
    exit(1);
}

$c = new Resp3Client(host: '127.0.0.1', port: 6379);

$c->command('DEL', 'r3:smoke:str', 'r3:smoke:list', 'r3:smoke:hash', 'r3:smoke:counter');

$c->command('SET', 'r3:smoke:str', 'hello');
$got = $c->command('GET', 'r3:smoke:str');
assert($got === 'hello', 'GET returned ' . var_export($got, true));

$missing = $c->command('GET', 'r3:smoke:nonexistent');
assert($missing === null, 'expected null on missing key, got ' . var_export($missing, true));

$incr = $c->command('INCR', 'r3:smoke:counter');
assert(is_int($incr), 'INCR should return int');

$c->command('RPUSH', 'r3:smoke:list', 'a', 'b', 'c');
$range = $c->command('LRANGE', 'r3:smoke:list', '0', '-1');
assert($range === ['a', 'b', 'c'], 'LRANGE mismatch: ' . json_encode($range));

$c->command('HSET', 'r3:smoke:hash', 'k1', 'v1', 'k2', 'v2');
// HGETALL under RESP3 returns a map (associative array) instead of a flat list.
$hash = $c->command('HGETALL', 'r3:smoke:hash');
assert($hash === ['k1' => 'v1', 'k2' => 'v2'], 'HGETALL mismatch: ' . json_encode($hash));

// Pipeline: send three commands, read three replies in one round trip.
$pipe = $c->pipeline([
    ['SET', 'r3:smoke:p1', 'one'],
    ['SET', 'r3:smoke:p2', 'two'],
    ['MGET', 'r3:smoke:p1', 'r3:smoke:p2'],
]);
assert($pipe[2] === ['one', 'two'], 'pipeline MGET mismatch: ' . json_encode($pipe));

// Error replies come back as RedisException instances, not thrown.
$err = $c->command('SET');                    // missing args
assert($err instanceof RedisException, 'expected RedisException for bad args');
echo "error preserved: ", $err->getMessage(), "\n";

$c->command('DEL',
    'r3:smoke:str', 'r3:smoke:list', 'r3:smoke:hash', 'r3:smoke:counter',
    'r3:smoke:p1', 'r3:smoke:p2',
);

echo "smoke.php: OK\n";
