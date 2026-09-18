<?php

namespace Tests;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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
     * False buys back the old behaviour — a real `migrate:fresh` and reseed
     * before the test, no wrapping transaction — at the old price of ~1.5s, so
     * only the tests that genuinely cannot live inside a transaction set it.
     * There are four, and each states its reason at the declaration:
     *
     *  - TimezoneShiftTest and BorrowerBulkErrorDisclosureTest change the
     *    SCHEMA mid-test, and MySQL implicitly commits on any DDL — a rollback
     *    would not undo it, and it destroys the savepoints nested
     *    DB::transaction() calls rely on.
     *  - One spec in CsvImportUploadApiTest needs a second connection to SEE
     *    this one's rows, which an uncommitted transaction forbids.
     *  - One spec in SequenceAllocatorTest is about there being no transaction.
     *
     * Set it before parent::setUp(); that is where refreshDatabase() is called.
     */
    protected bool $wrapsEachTestInTransaction = true;

    /**
     * Prepare the database for a test.
     *
     * Invoked by the framework's `setUpTraits()` because of the RefreshDatabase
     * trait above. The transactional path is the trait's own; the opt-out path
     * reproduces the suite's historical behaviour — a real `migrate:fresh` plus
     * seed per test — for the handful of tests that need DDL of their own.
     */
    public function refreshDatabase(): void
    {
        if ($this->wrapsEachTestInTransaction) {
            $this->refreshDatabaseInsideTransaction();

            return;
        }

        $this->artisan('migrate:fresh', $this->migrateFreshUsing());

        $this->app[Kernel::class]->setArtisan(null);

        // This test is free to drop tables, so the next transactional test in
        // this process cannot assume the schema or the seeded baseline survived.
        RefreshDatabaseState::$migrated = false;
    }
}
