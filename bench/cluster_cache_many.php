<?php declare(strict_types=1);
/**
 * Cluster Cache::many() benchmark with hash-tag-grouped keys.
 *
 *   php -d extension=/path/to/resp3.so bench/cluster_cache_many.php
 *
 * Requires the 6-node Valkey cluster from tests/cluster/docker-compose.yml
 * running on 127.0.0.1:7100-7105.
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

const KEYS_PER_TAG = 100;
const TAGS         = 10;          // 10 tags x 100 keys = 1000 keys total, all colocated per tag
const ITERATIONS   = 100;
const RUNS         = 5;

function bootstrap_app(): array
{
    $app = new Container();
    Container::setInstance($app);
    $app->instance('app', $app);
    $app->instance('config', new \Illuminate\Config\Repository([
        'database' => [
            'redis' => [
                'client'  => 'resp3',
                'options' => ['cluster' => 'redis', 'prefix' => 'r3clbench:'],
                'clusters' => [
                    'default' => [
                        ['host' => '127.0.0.1', 'port' => 7100],
                        ['host' => '127.0.0.1', 'port' => 7101],
                        ['host' => '127.0.0.1', 'port' => 7102],
                    ],
                    'options' => ['timeout' => 2],
                ],
            ],
        ],
        'cache' => [
            'default' => 'redis',
            'stores'  => ['redis' => ['driver' => 'redis', 'connection' => 'default']],
        ],
    ]));
    $app->instance('files', new \Illuminate\Filesystem\Filesystem());
    $app->instance('events', new Dispatcher($app));

    $redis = new RedisManager($app, 'resp3', $app['config']['database.redis']);
    $redis->extend('resp3', fn () => new Resp3Connector());
    $app->instance('redis', $redis);

    $cache = new CacheManager($app);
    $app->instance('cache', $cache);

    return [$app, $cache->store('redis')];
}

[$app, $store] = bootstrap_app();

// Pre-populate
$keysByTag = [];
for ($t = 0; $t < TAGS; $t++) {
    $tag = "tag$t";
    $keys = [];
    for ($i = 0; $i < KEYS_PER_TAG; $i++) {
        $k = "{{$tag}}.item$i";
        $keys[] = $k;
        $store->put($k, "value-$t-$i", 300);
    }
    $keysByTag[$tag] = $keys;
}

$samples = [];
for ($r = 0; $r < RUNS; $r++) {
    $start = microtime(true);
    $hits = 0;
    for ($n = 0; $n < ITERATIONS; $n++) {
        // Each iteration does TAGS round trips (one per slot group).
        foreach ($keysByTag as $keys) {
            $values = $store->many($keys);
            $hits  += count(array_filter($values, fn ($v) => $v !== null));
        }
    }
    $elapsed = microtime(true) - $start;
    $samples[] = ['hits' => $hits, 'seconds' => $elapsed];
    printf("  [run %d] %d reads in %.3fs = %.0f reads/s\n",
        $r + 1, $hits, $elapsed, $hits / $elapsed);
}

usort($samples, fn ($a, $b) => ($a['hits'] / $a['seconds']) <=> ($b['hits'] / $b['seconds']));
$median = $samples[(int) floor(count($samples) / 2)];
$rate   = $median['hits'] / $median['seconds'];

$report  = "## Cluster Cache::many() benchmark\n\n";
$report .= '_TAGS=' . TAGS . ', KEYS_PER_TAG=' . KEYS_PER_TAG . ', ITERATIONS=' . ITERATIONS . ', RUNS=' . RUNS . ", median reported_\n\n";
$report .= sprintf("Median: %s reads/s in %.3fs per round of %d MGET calls.\n",
    number_format($rate, 0), $median['seconds'], TAGS * ITERATIONS);

echo "\n";
echo $report;

$out = __DIR__ . '/results/cluster_cache_many.md';
file_put_contents($out, $report);
echo "\nWrote $out\n";

$store->flush();
