<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Resp3\Laravel\Tracking\ArrayLocalStore;

/**
 * The process-local store behind client-side caching: plain get, set, delete
 * and clear, insertion-order LRU at max_entries and max_bytes, ttl expiry
 * and namespaces.
 */
final class ArrayLocalStoreTest extends TestCase
{
    public function test_get_returns_a_stored_value(): void
    {
        $store = new ArrayLocalStore();
        $store->set('a', 'one');

        $this->assertSame('one', $store->get('a'));
    }

    public function test_get_returns_null_for_a_missing_key(): void
    {
        $this->assertNull((new ArrayLocalStore())->get('missing'));
    }

    public function test_set_overwrites_an_existing_value(): void
    {
        $store = new ArrayLocalStore();
        $store->set('a', 'one');
        $store->set('a', 'two');

        $this->assertSame('two', $store->get('a'));
        $this->assertSame(1, $store->count());
    }

    public function test_delete_removes_only_that_key(): void
    {
        $store = new ArrayLocalStore();
        $store->set('a', 'one');
        $store->set('b', 'two');

        $store->delete('a');

        $this->assertNull($store->get('a'));
        $this->assertSame('two', $store->get('b'));
    }

    public function test_delete_of_a_missing_key_is_a_no_op(): void
    {
        $store = new ArrayLocalStore();
        $store->set('a', 'one');

        $store->delete('missing');

        $this->assertSame(1, $store->count());
    }

    public function test_clear_removes_every_entry(): void
    {
        $store = new ArrayLocalStore();
        $store->set('a', 'one');
        $store->set('b', 'two');

        $store->clear();

        $this->assertSame(0, $store->count());
        $this->assertNull($store->get('a'));
        $this->assertNull($store->get('b'));
    }

    public function test_the_oldest_entry_is_evicted_at_max_entries(): void
    {
        $store = new ArrayLocalStore(3);
        $store->set('a', '1');
        $store->set('b', '2');
        $store->set('c', '3');

        $store->set('d', '4');

        $this->assertSame(3, $store->count());
        $this->assertNull($store->get('a'));
        $this->assertSame('2', $store->get('b'));
        $this->assertSame('3', $store->get('c'));
        $this->assertSame('4', $store->get('d'));
    }

    public function test_a_re_set_key_moves_to_the_newest_position(): void
    {
        $store = new ArrayLocalStore(3);
        $store->set('a', '1');
        $store->set('b', '2');
        $store->set('c', '3');

        $store->set('a', '1b');
        $store->set('d', '4');

        $this->assertNull($store->get('b'), 'b is now the oldest and goes first');
        $this->assertSame('1b', $store->get('a'));
        $this->assertSame('3', $store->get('c'));
        $this->assertSame('4', $store->get('d'));
    }

    public function test_a_hit_moves_the_entry_to_the_newest_position(): void
    {
        $store = new ArrayLocalStore(3);
        $store->set('a', '1');
        $store->set('b', '2');
        $store->set('c', '3');

        $this->assertSame('1', $store->get('a'));
        $store->set('d', '4');

        $this->assertNull($store->get('b'));
        $this->assertSame('1', $store->get('a'));
    }

    public function test_zero_max_entries_stores_nothing(): void
    {
        $store = new ArrayLocalStore(0);
        $store->set('a', '1');

        $this->assertNull($store->get('a'));
        $this->assertSame(0, $store->count());
    }

    public function test_bytes_count_keys_plus_values(): void
    {
        $store = new ArrayLocalStore();
        $store->set('ab', '123');
        $store->set('c', '4567');

        $this->assertSame(10, $store->bytes());
    }

    public function test_bytes_follow_overwrite_delete_and_clear(): void
    {
        $store = new ArrayLocalStore();
        $store->set('a', '12345');
        $store->set('a', '1');
        $this->assertSame(2, $store->bytes());

        $store->set('b', '22');
        $store->delete('a');
        $this->assertSame(3, $store->bytes());

        $store->clear();
        $this->assertSame(0, $store->bytes());
    }

    public function test_the_oldest_entries_are_evicted_over_max_bytes(): void
    {
        // Each entry is 1 + 4 = 5 bytes; three fit in 15, a fourth evicts one.
        $store = new ArrayLocalStore(100, 60, 15);
        $store->set('a', '1111');
        $store->set('b', '2222');
        $store->set('c', '3333');

        $store->set('d', '4444');

        $this->assertSame(3, $store->count());
        $this->assertSame(15, $store->bytes());
        $this->assertNull($store->get('a'));
        $this->assertSame('4444', $store->get('d'));
    }

    public function test_a_large_entry_evicts_as_many_old_entries_as_needed(): void
    {
        $store = new ArrayLocalStore(100, 60, 15);
        $store->set('a', '1111');
        $store->set('b', '2222');
        $store->set('c', '3333');

        $store->set('d', '444444444');

        $this->assertSame(['3333', '444444444'], [$store->get('c'), $store->get('d')]);
        $this->assertSame(2, $store->count());
        $this->assertSame(15, $store->bytes());
    }

    public function test_a_hit_protects_an_entry_from_byte_eviction(): void
    {
        $store = new ArrayLocalStore(100, 60, 10);
        $store->set('a', '1111');
        $store->set('b', '2222');

        $store->get('a');
        $store->set('c', '3333');

        $this->assertSame('1111', $store->get('a'));
        $this->assertNull($store->get('b'));
    }

    public function test_an_entry_larger_than_max_bytes_is_not_stored(): void
    {
        $store = new ArrayLocalStore(100, 60, 4);
        $store->set('a', '111');
        $store->set('b', '22222');

        $this->assertNull($store->get('b'));
        $this->assertSame('111', $store->get('a'), 'the entries already held stay');
        $this->assertSame(4, $store->bytes());
    }

    public function test_zero_max_bytes_stores_nothing(): void
    {
        $store = new ArrayLocalStore(100, 60, 0);
        $store->set('a', '1');

        $this->assertNull($store->get('a'));
        $this->assertSame(0, $store->bytes());
    }

    public function test_an_expired_entry_frees_its_bytes_when_touched(): void
    {
        $store = new ArrayLocalStore(10, 60);
        $store->set('a', '1', 1);

        usleep(1_150_000);

        $this->assertNull($store->get('a'));
        $this->assertSame(0, $store->bytes());
        $this->assertSame(0, $store->count());
    }

    public function test_a_namespace_switch_resets_the_bytes(): void
    {
        $store = new ArrayLocalStore();
        $store->useNamespace('100:1');
        $store->set('a', '1');

        $store->useNamespace('100:2');

        $this->assertSame(0, $store->bytes());
    }

    public function test_an_entry_expires_after_the_store_ttl(): void
    {
        $store = new ArrayLocalStore(10, 1);
        $store->set('a', '1');
        $this->assertSame('1', $store->get('a'));

        usleep(1_150_000);

        $this->assertNull($store->get('a'));
    }

    public function test_an_entry_expires_after_its_own_ttl(): void
    {
        $store = new ArrayLocalStore(10, 60);
        $store->set('short', '1', 1);
        $store->set('long', '2');

        usleep(1_150_000);

        $this->assertNull($store->get('short'));
        $this->assertSame('2', $store->get('long'));
    }

    public function test_an_entry_ttl_below_one_second_stores_nothing(): void
    {
        $store = new ArrayLocalStore();
        $store->set('a', '1', 0);

        $this->assertNull($store->get('a'));
    }

    public function test_the_namespace_is_null_before_it_is_set(): void
    {
        $this->assertNull((new ArrayLocalStore())->namespace());
    }

    public function test_use_namespace_with_a_different_namespace_clears_the_entries(): void
    {
        $store = new ArrayLocalStore();
        $store->useNamespace('100:1');
        $store->set('a', '1');

        $store->useNamespace('100:2');

        $this->assertSame('100:2', $store->namespace());
        $this->assertNull($store->get('a'));
        $this->assertSame(0, $store->count());
    }

    public function test_use_namespace_with_the_same_namespace_keeps_the_entries(): void
    {
        $store = new ArrayLocalStore();
        $store->useNamespace('100:1');
        $store->set('a', '1');

        $store->useNamespace('100:1');

        $this->assertSame('100:1', $store->namespace());
        $this->assertSame('1', $store->get('a'));
    }
}
