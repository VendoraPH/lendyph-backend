<?php

namespace Tests;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase {
        refreshDatabase as protected refreshDatabaseInsideTransaction;
    }

    /**
     * Seeded ONCE per worker process, as part of the single `migrate:fresh`
     * RefreshDatabase runs before the first test.
     *
     * This suite used to rebuild the schema and re-run this seeder in every
     * single test — 1.5s of DDL under every ~50ms assertion. The seeded rows
     * are now a fixed baseline each test starts from, and each test's own
     * writes are rolled back with its transaction.
     *
     * @var class-string<Seeder>
     */
    protected $seeder = DatabaseSeeder::class;

    /**
     * Whether this test may run inside the transaction RefreshDatabase opens.
     *
     * False buys back the old behaviour — a database in the freshly migrated
     * and seeded state, with no wrapping transaction — so only the tests that
     * genuinely cannot live inside a transaction set it. Each states its
     * reason at the declaration. The usual ones:
     *
     *  - TimezoneShiftTest and BorrowerBulkErrorDisclosureTest change the
     *    SCHEMA mid-test, and MySQL implicitly commits on any DDL — a rollback
     *    would not undo it, and it destroys the savepoints nested
     *    DB::transaction() calls rely on.
     *  - The race and collision tests need the request's own transaction to be
     *    a real one (not a savepoint), or a second connection that can SEE
     *    this one's rows, which an uncommitted transaction forbids.
     *  - One spec in SequenceAllocatorTest is about there being no transaction.
     *
     * That state comes from Tests\SeededBaseline when the schema still matches
     * the last real `migrate:fresh` in this process, and from a real
     * `migrate:fresh` otherwise.
     *
     * Set it before parent::setUp(); that is where refreshDatabase() is called.
     */
    protected bool $wrapsEachTestInTransaction = true;

    /**
     * Prepare the database for a test.
     *
     * Invoked by the framework's `setUpTraits()` because of the RefreshDatabase
     * trait above. The transactional path is the trait's own; the opt-out path
     * starts the test from the freshly migrated and seeded state with no
     * transaction around it.
     */
    public function refreshDatabase(): void
    {
        if ($this->wrapsEachTestInTransaction) {
            $this->refreshDatabaseInsideTransaction();

            return;
        }

        $this->migrateAndSeedFresh();

        // This test commits its writes and is free to drop tables, so the next
        // transactional test in this process cannot assume the schema or the
        // seeded baseline survived.
        RefreshDatabaseState::$migrated = false;
    }

    /**
     * The trait's own step, with the rebuild routed through migrateAndSeedFresh().
     */
    protected function refreshTestDatabase(): void
    {
        if (! RefreshDatabaseState::$migrated) {
            $this->migrateAndSeedFresh();

            RefreshDatabaseState::$migrated = true;
        }

        $this->beginDatabaseTransaction();
    }

    /**
     * Leave the database exactly as `migrate:fresh` with the seeder leaves it.
     *
     * A real `migrate:fresh` costs seconds (150+ migrations), and every opt-out
     * test used to pay it, which by 2026-11 was most of the suite's run time.
     * Tests\SeededBaseline copies the result of the last real one and puts the
     * rows and id counters back instead, but only while the schema is exactly
     * the one it copied; after a test changed the schema it refuses, and this
     * runs the real `migrate:fresh` again.
     */
    private function migrateAndSeedFresh(): void
    {
        if (SeededBaseline::restore(DB::connection())) {
            return;
        }

        $this->artisan('migrate:fresh', $this->migrateFreshUsing());

        $this->app[Kernel::class]->setArtisan(null);

        SeededBaseline::capture(DB::connection());
    }
}
