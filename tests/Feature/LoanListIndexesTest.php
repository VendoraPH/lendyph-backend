<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The indexes behind the loans list's sorts, filters and past-due EXISTS.
 *
 * Column ORDER is the whole point of three of these, not an implementation
 * detail: `(created_at, id)` matches the list's ORDER BY whole where a bare
 * `created_at` would still filesort, and `(loan_id, status, due_date)` puts the
 * two equality legs ahead of the range so the range does not truncate the usable
 * prefix. So the assertion is on the ordered column list, not on the index name.
 *
 * Asserting them here rather than only in the migration means dropping one in a
 * later schema change is caught, and it is the migration actually running that
 * produces the state under test.
 */
class LoanListIndexesTest extends TestCase
{
    /**
     * @var array<string, array{0: string, 1: string}>
     */
    private const EXPECTED = [
        'loans_created_at_id_index' => ['loans', 'created_at,id'],
        'loans_status_created_at_index' => ['loans', 'status,created_at'],
        'loan_products_name_index' => ['loan_products', 'name'],
        'amortization_schedules_loan_id_status_due_date_index' => ['amortization_schedules', 'loan_id,status,due_date'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh');
    }

    public function test_the_loan_list_indexes_exist_with_their_columns_in_order(): void
    {
        foreach (self::EXPECTED as $index => [$table, $columns]) {
            $this->assertSame(
                $columns,
                $this->indexColumns($table, $index),
                "{$table}.{$index} is missing or its columns are in the wrong order. "
                .'Column order is load-bearing here — see the migration for why.',
            );
        }
    }

    /**
     * The migration is reversible, so a bad deploy can be backed out.
     *
     * Driven through the shipped file rather than `migrate:rollback --step=1`,
     * which rolls back whatever migration happens to be newest and would quietly
     * start testing someone else's the moment one lands after this.
     */
    public function test_the_migration_rolls_back_and_reapplies_cleanly(): void
    {
        $this->indexMigration()->down();

        foreach (self::EXPECTED as $index => [$table]) {
            $this->assertNull($this->indexColumns($table, $index), "{$table}.{$index} survived down().");
        }

        $this->indexMigration()->up();

        foreach (self::EXPECTED as $index => [$table, $columns]) {
            $this->assertSame($columns, $this->indexColumns($table, $index), "{$table}.{$index} did not come back.");
        }
    }

    /**
     * Both halves are guarded on Schema::hasIndex(), so neither errors when the
     * index it would touch is already in the state it wants. That is what lets
     * this land beside a branch that adds an overlapping index, and what keeps a
     * re-run on a drifted box from failing the deploy.
     */
    public function test_the_migration_is_safe_to_re_run_over_an_index_that_already_exists(): void
    {
        $this->indexMigration()->up();
        $this->indexMigration()->up();

        foreach (self::EXPECTED as $index => [$table, $columns]) {
            $this->assertSame($columns, $this->indexColumns($table, $index));
        }

        $this->indexMigration()->down();
        $this->indexMigration()->down();

        foreach (self::EXPECTED as $index => [$table]) {
            $this->assertNull($this->indexColumns($table, $index));
        }
    }

    /**
     * A plain `require` (not `require_once`) re-evaluates the file and hands
     * back a fresh instance of its anonymous migration class.
     */
    private function indexMigration(): object
    {
        return require database_path('migrations/2026_08_28_100000_add_loan_list_and_past_due_indexes.php');
    }

    /**
     * The comma-joined column list of an index, in key order, or null if the
     * index does not exist.
     */
    private function indexColumns(string $table, string $index): ?string
    {
        return DB::table('information_schema.statistics')
            ->selectRaw('GROUP_CONCAT(column_name ORDER BY seq_in_index) as columns')
            ->whereRaw('table_schema = schema()')
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->value('columns');
    }
}
