<?php

declare(strict_types=1);

namespace App\Tests\Unit\Queue;

use App\Engine\Queue\QueuedJob;
use App\Engine\Queue\QueueStore;
use App\Engine\Queue\Stores\FileStore;
use App\Engine\Queue\Stores\MemoryStore;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What it means to be a queue, asserted against every store there is.
 *
 * The same device as the cache's conformance suite, and it matters more here.
 * The interesting disagreements between queue backends are not in push and pop:
 * they are in whether a job is handed to two workers at once, whether a delayed
 * job can be reserved early, whether an abandoned reservation ever comes back,
 * and whether attempts count the try in progress. Every one of those is a
 * question that looks fine in development and bills a customer twice in
 * production.
 *
 * A database or Redis store added later becomes conformant by appearing in one
 * provider here and passing without a line of this file changing.
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

    /** @return array<string, array{\Closure(): QueueStore}> */
    public static function stores(): array
    {
        return [
            'memory' => [static fn(): QueueStore => new MemoryStore()],
            'file' => [static function (): QueueStore {
                $directory = \sys_get_temp_dir() . '/queue-store-' . \bin2hex(\random_bytes(6));
                self::$directories[] = $directory;

                return new FileStore($directory);
            }],
        ];
    }

    private function job(string $id, string $queue = 'default', int $availableAt = 0): QueuedJob
    {
        return new QueuedJob(
            id: $id,
            queue: $queue,
            class: 'App\\Tests\\Fixtures\\Queue\\RecordingJob',
            payload: \serialize(['label' => $id]),
            availableAt: $availableAt === 0 ? \time() : $availableAt,
            createdAt: \time(),
        );
    }

    // ---- the basics ---------------------------------------------------------

    /** @param \Closure(): QueueStore $make */
    #[DataProvider('stores')]
    public function test_a_pushed_job_can_be_reserved(\Closure $make): void
    {
        $store = $make();
        $store->push($this->job('a'));

        $reserved = $store->reserve('default', 60);

        self::assertNotNull($reserved);
        self::assertSame('a', $reserved->id);
    }

    /** @param \Closure(): QueueStore $make */
    #[DataProvider('stores')]
    public function test_an_empty_queue_reserves_nothing(\Closure $make): void
    {
        self::assertNull($make()->reserve('default', 60));
    }

    /** @param \Closure(): QueueStore $make */
    #[DataProvider('stores')]
    public function test_the_payload_survives_the_round_trip(\Closure $make): void
    {
        $store = $make();
        $store->push($this->job('a'));

        $reserved = $store->reserve('default', 60);

        self::assertNotNull($reserved);
        self::assertSame(\serialize(['label' => 'a']), $reserved->payload);
        self::assertSame('App\\Tests\\Fixtures\\Queue\\RecordingJob', $reserved->class);
    }

    /**
     * The property the whole design exists for. Two workers, one job, one
     * winner -- a store that hands the same job out twice charges a customer
     * twice.
     *
     * @param \Closure(): QueueStore $make
     */
    #[DataProvider('stores')]
    public function test_a_job_is_reserved_by_exactly_one_caller(\Closure $make): void
    {
        $store = $make();
        $store->push($this->job('a'));

        self::assertNotNull($store->reserve('default', 60));
        self::assertNull($store->reserve('default', 60), 'the second caller must get nothing');
    }

    /** @param \Closure(): QueueStore $make */
    #[DataProvider('stores')]
    public function test_reserving_counts_the_attempt(\Closure $make): void
    {
        $store = $make();
        $store->push($this->job('a'));

        $reserved = $store->reserve('default', 60);

        self::assertNotNull($reserved);
        self::assertSame(1, $reserved->attempts, 'attempts include the try in progress');
    }

    /** @param \Closure(): QueueStore $make */
    #[DataProvider('stores')]
    public function test_acknowledging_takes_it_off_the_queue(\Closure $make): void
    {
        $store = $make();
        $store->push($this->job('a'));

        $reserved = $store->reserve('default', 60);
        self::assertNotNull($reserved);

        $store->acknowledge($reserved);

        self::assertSame(0, $store->size('default'));
    }

    // ---- delay and order ------------------------------------------------------

    /** @param \Closure(): QueueStore $make */
    #[DataProvider('stores')]
    public function test_a_delayed_job_is_not_reserved_early(\Closure $make): void
    {
        $store = $make();
        $store->push($this->job('later', availableAt: \time() + 300));

        self::assertNull($store->reserve('default', 60));
        self::assertSame(1, $store->size('default'), 'it is still there, just not yet');
    }

    /** @param \Closure(): QueueStore $make */
    #[DataProvider('stores')]
    public function test_jobs_come_back_in_the_order_they_are_due(\Closure $make): void
    {
        $store = $make();
        $store->push($this->job('second', availableAt: \time() - 10));
        $store->push($this->job('first', availableAt: \time() - 20));

        $first = $store->reserve('default', 60);
        $second = $store->reserve('default', 60);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame('first', $first->id);
        self::assertSame('second', $second->id);
    }

    /** @param \Closure(): QueueStore $make */
    #[DataProvider('stores')]
    public function test_a_released_job_can_be_reserved_again(\Closure $make): void
    {
        $store = $make();
        $store->push($this->job('a'));

        $reserved = $store->reserve('default', 60);
        self::assertNotNull($reserved);

        $store->release($reserved->releasedAfter(0));

        $again = $store->reserve('default', 60);

        self::assertNotNull($again);
        self::assertSame('a', $again->id);
        self::assertSame(2, $again->attempts, 'the attempt count carries across the retry');
    }

    /** @param \Closure(): QueueStore $make */
    #[DataProvider('stores')]
    public function test_a_released_job_waits_out_its_delay(\Closure $make): void
    {
        $store = $make();
        $store->push($this->job('a'));

        $reserved = $store->reserve('default', 60);
        self::assertNotNull($reserved);

        $store->release($reserved->releasedAfter(300));

        self::assertNull($store->reserve('default', 60));
    }

    // ---- reservations expire ----------------------------------------------------

    /**
     * What makes a killed worker harmless, and the reason this framework needs
     * no signal handling to be correct.
     *
     * A zero-second reservation has already lapsed, which is how this is tested
     * without waiting.
     *
     * @param \Closure(): QueueStore $make
     */
    #[DataProvider('stores')]
    public function test_an_abandoned_reservation_comes_back(\Closure $make): void
    {
        $store = $make();
        $store->push($this->job('a'));

        $reserved = $store->reserve('default', 0);
        self::assertNotNull($reserved, 'reserved, and then the worker died');

        $recovered = $store->reserve('default', 60);

        self::assertNotNull($recovered, 'the reservation lapsed, so the job is available again');
        self::assertSame('a', $recovered->id);
    }

    /**
     * And it comes back having been tried, so a job that kills whatever picks
     * it up still runs out of attempts instead of cycling forever.
     *
     * @param \Closure(): QueueStore $make
     */
    #[DataProvider('stores')]
    public function test_a_recovered_job_remembers_how_often_it_has_been_tried(\Closure $make): void
    {
        $store = $make();
        $store->push($this->job('a'));

        $store->reserve('default', 0);
        $recovered = $store->reserve('default', 0);

        self::assertNotNull($recovered);
        self::assertSame(2, $recovered->attempts);
    }

    // ---- queues are separate -----------------------------------------------------

    /** @param \Closure(): QueueStore $make */
    #[DataProvider('stores')]
    public function test_a_queue_only_yields_its_own_jobs(\Closure $make): void
    {
        $store = $make();
        $store->push($this->job('a', 'default'));
        $store->push($this->job('b', 'billing'));

        $reserved = $store->reserve('billing', 60);

        self::assertNotNull($reserved);
        self::assertSame('b', $reserved->id);
        self::assertSame(1, $store->size('default'));
    }

    /** @param \Closure(): QueueStore $make */
    #[DataProvider('stores')]
    public function test_it_reports_which_queues_hold_anything(\Closure $make): void
    {
        $store = $make();
        $store->push($this->job('a', 'default'));
        $store->push($this->job('b', 'billing'));

        self::assertSame(['billing', 'default'], $store->queues());
    }

    /** @param \Closure(): QueueStore $make */
    #[DataProvider('stores')]
    public function test_clearing_one_queue_leaves_the_others(\Closure $make): void
    {
        $store = $make();
        $store->push($this->job('a', 'default'));
        $store->push($this->job('b', 'billing'));

        $store->clear('default');

        self::assertSame(0, $store->size('default'));
        self::assertSame(1, $store->size('billing'));
    }

    // ---- failures ------------------------------------------------------------------

    /** @param \Closure(): QueueStore $make */
    #[DataProvider('stores')]
    public function test_a_failed_job_leaves_the_queue_and_is_kept(\Closure $make): void
    {
        $store = $make();
        $store->push($this->job('a'));

        $reserved = $store->reserve('default', 60);
        self::assertNotNull($reserved);

        $store->fail($reserved->failedWith(new \RuntimeException('nope')));

        self::assertSame(0, $store->size('default'));
        self::assertCount(1, $store->failed());
        self::assertStringContainsString('nope', $store->failed()[0]->error ?? '');
    }

    /** @param \Closure(): QueueStore $make */
    #[DataProvider('stores')]
    public function test_clearing_a_queue_does_not_discard_failures(\Closure $make): void
    {
        $store = $make();
        $store->push($this->job('a'));
        $reserved = $store->reserve('default', 60);
        self::assertNotNull($reserved);
        $store->fail($reserved->failedWith(new \RuntimeException('nope')));

        $store->clear();

        self::assertCount(1, $store->failed(), 'a failed job nobody can find is a failed job nobody can retry');
    }

    /** @param \Closure(): QueueStore $make */
    #[DataProvider('stores')]
    public function test_a_failure_can_be_discarded(\Closure $make): void
    {
        $store = $make();
        $store->push($this->job('a'));
        $reserved = $store->reserve('default', 60);
        self::assertNotNull($reserved);
        $store->fail($reserved->failedWith(new \RuntimeException('nope')));

        $store->forget('a');

        self::assertSame([], $store->failed());
    }

    /** @param \Closure(): QueueStore $make */
    #[DataProvider('stores')]
    public function test_every_store_can_say_what_it_is(\Closure $make): void
    {
        self::assertNotSame('', $make()->describe());
    }
}
