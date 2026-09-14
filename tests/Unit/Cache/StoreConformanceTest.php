<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cache;

use App\Engine\Cache\CacheStore;
use App\Engine\Cache\Stores\ArrayStore;
use App\Engine\Cache\Stores\FileStore;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What it means to be a store, asserted against every store there is.
 *
 * This is the test that makes "abstract the backend" a claim rather than a
 * hope. Each case runs against memory and against files, and a Redis or APCu
 * store added later becomes conformant by being added to one provider here and
 * passing without changing a line of it.
 *
 * That matters more than it looks. The interesting disagreements between cache
 * backends are not in the obvious methods -- they are in whether a stored null
 * is a hit, whether an expired entry is a miss or an error, whether clearing a
 * namespace clears anything else, and whether a value survives being stored as
 * the same value. Every one of those is below.
 */
final class StoreConformanceTest extends TestCase
{
    /** @var list<string> */
    private static array $directories = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$directories as $directory) {
            if (!\is_dir($directory)) {
                continue;
            }

            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($entries as $entry) {
                if ($entry instanceof \SplFileInfo) {
                    $entry->isDir() ? @\rmdir($entry->getPathname()) : @\unlink($entry->getPathname());
                }
            }

            @\rmdir($directory);
        }

        self::$directories = [];
    }

    /** @return array<string, array{\Closure(): CacheStore}> */
    public static function stores(): array
    {
        return [
            'array' => [static fn(): CacheStore => new ArrayStore()],
            'file' => [static function (): CacheStore {
                $directory = \sys_get_temp_dir() . '/cache-store-' . \bin2hex(\random_bytes(6));
                self::$directories[] = $directory;

                return new FileStore($directory);
            }],
        ];
    }

    /** @param \Closure(): CacheStore $make */
    #[DataProvider('stores')]
    public function test_a_value_comes_back(\Closure $make): void
    {
        $store = $make();
        $store->put('greeting', 'hello');

        $entry = $store->get('greeting');

        self::assertNotNull($entry);
        self::assertSame('hello', $entry->value);
    }

    /** @param \Closure(): CacheStore $make */
    #[DataProvider('stores')]
    public function test_a_key_that_was_never_set_is_a_miss(\Closure $make): void
    {
        self::assertNull($make()->get('never.set'));
    }

    /**
     * The distinction most cache APIs lose, and the one that costs the most.
     *
     * A store whose miss and whose stored null look the same turns remember()
     * around a lookup that legitimately answers null into a query on every
     * single request, silently, while appearing to work.
     *
     * @param \Closure(): CacheStore $make
     */
    #[DataProvider('stores')]
    public function test_a_stored_null_is_a_hit(\Closure $make): void
    {
        $store = $make();
        $store->put('nothing', null);

        $entry = $store->get('nothing');

        self::assertNotNull($entry, 'a stored null must not read as a miss');
        self::assertNull($entry->value);
    }

    /** @param \Closure(): CacheStore $make */
    #[DataProvider('stores')]
    public function test_values_of_every_ordinary_shape_survive(\Closure $make): void
    {
        $store = $make();

        foreach ([
            'int' => 42,
            'float' => 1.5,
            'false' => false,
            'zero' => 0,
            'empty string' => '',
            'list' => [1, 2, 3],
            'map' => ['a' => ['b' => 'c']],
            'empty array' => [],
        ] as $label => $value) {
            $store->put('probe', $value);

            $entry = $store->get('probe');

            self::assertNotNull($entry, $label);
            self::assertSame($value, $entry->value, $label);
        }
    }

    /** @param \Closure(): CacheStore $make */
    #[DataProvider('stores')]
    public function test_writing_again_replaces(\Closure $make): void
    {
        $store = $make();
        $store->put('k', 'first');
        $store->put('k', 'second');

        self::assertSame('second', $store->get('k')?->value);
    }

    /** @param \Closure(): CacheStore $make */
    #[DataProvider('stores')]
    public function test_forgetting_removes_it(\Closure $make): void
    {
        $store = $make();
        $store->put('k', 'value');
        $store->forget('k');

        self::assertNull($store->get('k'));
    }

    /** Forgetting something that was never there is not a failure. */
    #[DataProvider('stores')]
    public function test_forgetting_what_is_not_there_succeeds(\Closure $make): void
    {
        self::assertTrue($make()->forget('never.set'));
    }

    // ---- expiry ------------------------------------------------------------

    /** @param \Closure(): CacheStore $make */
    #[DataProvider('stores')]
    public function test_an_entry_within_its_ttl_is_still_there(\Closure $make): void
    {
        $store = $make();
        $store->put('k', 'value', 60);

        self::assertSame('value', $store->get('k')?->value);
    }

    /**
     * A negative TTL is already in the past, which is how this gets tested
     * without a sleep.
     *
     * @param \Closure(): CacheStore $make
     */
    #[DataProvider('stores')]
    public function test_an_expired_entry_is_a_miss(\Closure $make): void
    {
        $store = $make();
        $store->put('k', 'value', -1);

        self::assertNull($store->get('k'), 'an expired entry must read as a miss, not as a value');
    }

    /** @param \Closure(): CacheStore $make */
    #[DataProvider('stores')]
    public function test_an_entry_with_no_ttl_does_not_expire(\Closure $make): void
    {
        $store = $make();
        $store->put('k', 'value');

        $entry = $store->get('k');

        self::assertNotNull($entry);
        self::assertNull($entry->expiresAt);
        self::assertNull($entry->remaining());
    }

    /** @param \Closure(): CacheStore $make */
    #[DataProvider('stores')]
    public function test_a_ttl_is_reported_as_time_remaining(\Closure $make): void
    {
        $store = $make();
        $store->put('k', 'value', 60);

        $remaining = $store->get('k')?->remaining();

        self::assertNotNull($remaining);
        self::assertGreaterThan(0, $remaining);
        self::assertLessThanOrEqual(60, $remaining);
    }

    // ---- namespaces --------------------------------------------------------

    /** @param \Closure(): CacheStore $make */
    #[DataProvider('stores')]
    public function test_flushing_everything_empties_it(\Closure $make): void
    {
        $store = $make();
        $store->put('a', 1);
        $store->put('billing:b', 2);

        $store->flush();

        self::assertNull($store->get('a'));
        self::assertNull($store->get('billing:b'));
    }

    /**
     * The reason a namespace exists at all: clearing one module's cached
     * answers must not clear anybody else's.
     *
     * @param \Closure(): CacheStore $make
     */
    #[DataProvider('stores')]
    public function test_flushing_a_prefix_leaves_everything_else(\Closure $make): void
    {
        $store = $make();
        $store->put('billing:invoice', 'kept elsewhere');
        $store->put('billing.reports:total', 'also billing-ish');
        $store->put('assets:app.css', 'not billing');
        $store->put('unnamespaced', 'not billing either');

        $store->flush('billing:');

        self::assertNull($store->get('billing:invoice'));
        self::assertSame('not billing', $store->get('assets:app.css')?->value);
        self::assertSame('not billing either', $store->get('unnamespaced')?->value);
        self::assertSame(
            'also billing-ish',
            $store->get('billing.reports:total')?->value,
            'a prefix is a namespace, not a string match: billing.reports is not inside billing',
        );
    }

    /** @param \Closure(): CacheStore $make */
    #[DataProvider('stores')]
    public function test_flushing_a_prefix_with_nothing_under_it_succeeds(\Closure $make): void
    {
        self::assertTrue($make()->flush('never.used:'));
    }

    /** @param \Closure(): CacheStore $make */
    #[DataProvider('stores')]
    public function test_every_store_can_say_what_it_is(\Closure $make): void
    {
        self::assertNotSame('', $make()->describe());
    }
}
