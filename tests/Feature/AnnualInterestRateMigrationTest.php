<?php

namespace Tests\Feature;

use App\Models\LoanProduct;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The migration that adds `annual` to the rate period ENUM on loans and loan
 * products: it only adds, runs twice without a change, and refuses to roll
 * back while a rate is quoted per year (MySQL would blank those rows).
 */
class AnnualInterestRateMigrationTest extends TestCase
{
    /** It runs DDL, which would end the per-test transaction. */
    protected bool $wrapsEachTestInTransaction = false;

    public function test_the_migration_cannot_be_rolled_back_while_a_rate_is_quoted_per_year(): void
    {
        $migration = require database_path('migrations/2026_11_13_100000_add_annual_to_interest_rate_frequency.php');

        LoanProduct::factory()->create(['interest_rate_frequency' => 'annual']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('quoted per year');

        $migration->down();
    }

    public function test_the_migration_runs_twice_without_changing_anything(): void
    {
        $migration = require database_path('migrations/2026_11_13_100000_add_annual_to_interest_rate_frequency.php');

        $before = DB::selectOne("SELECT COLUMN_TYPE AS t, COLUMN_DEFAULT AS d, IS_NULLABLE AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'loans' AND COLUMN_NAME = 'interest_rate_frequency'");

        $this->assertStringContainsString("'annual'", $before->t);
        $this->assertSame('NO', $before->n);

        $migration->up();

        $after = DB::selectOne("SELECT COLUMN_TYPE AS t, COLUMN_DEFAULT AS d, IS_NULLABLE AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'loans' AND COLUMN_NAME = 'interest_rate_frequency'");

        $this->assertEquals($before, $after);
        $this->assertSame('monthly', trim((string) $after->d, "'"));
    }

    public function test_it_rolls_back_to_the_old_list_when_no_rate_is_quoted_per_year(): void
    {
        $migration = require database_path('migrations/2026_11_13_100000_add_annual_to_interest_rate_frequency.php');

        $migration->down();

        foreach (['loans', 'loan_products'] as $table) {
            $this->assertStringNotContainsString("'annual'", $this->columnType($table));
        }

        $migration->up();

        foreach (['loans', 'loan_products'] as $table) {
            $this->assertSame("enum('daily','weekly','bi_weekly','semi_monthly','monthly','annual')", $this->columnType($table));
        }
    }

    private function columnType(string $table): string
    {
        return DB::selectOne("SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'interest_rate_frequency'", [$table])->t;
    }
}
