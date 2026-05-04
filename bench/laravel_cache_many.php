<?php declare(strict_types=1);
/**
 * Laravel Cache::many() benchmark: resp3 vs predis.
 *
 *   php -d extension=/path/to/resp3.so bench/laravel_cache_many.php
 *
 * Requires Redis or Valkey on 127.0.0.1:6379. Pre-populates 1000 keys with
 * igbinary-serialised payloads, then runs N rounds of MGET-shaped reads
 * through Laravel's Cache facade with each driver. Reports the median of
 * five runs.
 */

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Cache\CacheManager;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Redis\RedisManager;
use Resp3\Laravel\Connectors\Resp3Connector;

if (!extension_loaded('resp3')) {
    fwrite(STDERR, "ext-resp3 not loaded; run with -d extension=...\n");
    exit(1);
}

const TOTAL_KEYS  = 1_000;
const ITERATIONS  = 200;
const RUNS        = 5;

function make_payload(int $i): string
{
    return serialize([
        'id'      => $i,
        'name'    => 'Item ' . $i,
        'price'   => round(9.99 + ($i * 0.13), 2),
        'tags'    => ['alpha', 'beta', 'gamma'],
        'updated' => '2026-05-04T10:00:00Z',
    ]);
}

function bootstrap_app(string $client): array
{
    $app = new Container();
    Container::setInstance($app);
    $app->instance('app', $app);
    $app->instance('config', new \Illuminate\Config\Repository([
        'database' => [
            'redis' => [
                'client'  => $client,
                'options' => ['prefix' => 'r3bench:'],
                'default' => [
                    'host'     => '127.0.0.1',
                    'port'     => 6379,
                    'database' => 0,
                ],
            ],
        ],
        'cache' => [
            'default' => 'redis',
            'stores'  => [
                'redis' => [
                    'driver'     => 'redis',
                    'connection' => 'default',
                ],
            ],
            'prefix' => 'r3bench:',
        ],
    ]));
    $app->instance('files', new \Illuminate\Filesystem\Filesystem());
    $app->instance('events', new Dispatcher($app));

    // Redis manager
    $redis = new RedisManager($app, $client, $app['config']['database.redis']);
    if ($client === 'resp3') {
        $redis->extend('resp3', fn () => new Resp3Connector());
    }
    $app->instance('redis', $redis);

    // Cache manager
    $cache = new CacheManager($app);
    $app->instance('cache', $cache);

    return [$app, $cache->store('redis')];
}

function bench(string $label, callable $cacheFactory): array
{
    $samples = [];
    for ($r = 0; $r < RUNS; $r++) {
        [$app, $store] = $cacheFactory();

        // Prep
        $keys = [];
        for ($i = 0; $i < TOTAL_KEYS; $i++) {
            $k = "key:$i";
            $keys[] = $k;
            $store->put($k, make_payload($i), 300);
        }

        $start = microtime(true);
        $hits  = 0;
        for ($n = 0; $n < ITERATIONS; $n++) {
            $values = $store->many($keys);
            $hits  += count(array_filter($values, fn ($v) => $v !== null));
        }
        $elapsed = microtime(true) - $start;

        $store->flush();

        $samples[] = ['hits' => $hits, 'seconds' => $elapsed];
        printf("  [%s run %d] %d reads in %.3fs = %.0f reads/s\n",
            $label, $r + 1, $hits, $elapsed, $hits / $elapsed);
    }
    usort($samples, fn ($a, $b) => ($a['hits'] / $a['seconds']) <=> ($b['hits'] / $b['seconds']));
    return $samples[(int) floor(count($samples) / 2)];
}

echo "Laravel Cache::many() benchmark\n";
echo "  per call: MGET " . TOTAL_KEYS . " keys + unserialize each value via Cache::many()\n";
echo "  ITERATIONS=" . ITERATIONS . ", RUNS=" . RUNS . ", median reported\n\n";

echo "Predis (pure-PHP RESP parser):\n";
$predis = bench('predis', fn () => bootstrap_app('predis'));

echo "\nResp3 (ext-resp3 C parser):\n";
$resp3 = bench('resp3', fn () => bootstrap_app('resp3'));

$predisRate = $predis['hits'] / $predis['seconds'];
$resp3Rate  = $resp3['hits']  / $resp3['seconds'];
$delta      = (($resp3Rate - $predisRate) / $predisRate) * 100;

$report  = "## Laravel Cache::many() benchmark\n\n";
$report .= '_TOTAL_KEYS=' . TOTAL_KEYS . ', ITERATIONS=' . ITERATIONS . ', RUNS=' . RUNS . ", median reported_\n\n";
$report .= "| client | reads/s | seconds (per " . ITERATIONS . " calls) |\n";
$report .= "| --- | ---: | ---: |\n";
$report .= sprintf("| predis (pure-PHP) | %s | %.3f |\n", number_format($predisRate, 0), $predis['seconds']);
$report .= sprintf("| resp3 (ext-resp3) | %s | %.3f |\n", number_format($resp3Rate, 0), $resp3['seconds']);
$report .= sprintf("\n**Delta**: %+.1f%%\n", $delta);

echo "\n";
echo $report;

$out = __DIR__ . '/results/laravel_cache_many.md';
file_put_contents($out, $report);
echo "\nWrote $out\n";
