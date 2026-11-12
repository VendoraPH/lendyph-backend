<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A rate quoted per year: `annual` joins the `interest_rate_frequency` list on
 * loan products and loans (InterestRateFrequency::Annual).
 *
 * Only adds a value: every existing row keeps its period and the column keeps
 * its `monthly` default. The list is rewritten to the same list, so running it
 * again changes nothing.
 */
return new class extends Migration
{
    private const TABLES = ['loan_products', 'loans'];

    private const PERIODS = ['daily', 'weekly', 'bi_weekly', 'semi_monthly', 'monthly', 'annual'];

    private const PREVIOUS_PERIODS = ['daily', 'weekly', 'bi_weekly', 'semi_monthly', 'monthly'];

    public function up(): void
    {
        $this->periods(self::PERIODS);
    }

    /**
     * Refused while any product or loan quotes its rate per year: dropping the
     * value would make MySQL blank those rows' period.
     */
    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (DB::table($table)->where('interest_rate_frequency', 'annual')->exists()) {
                throw new RuntimeException("Some {$table} rows have a rate quoted per year; their rate period would be lost. Change them first.");
            }
        }

        $this->periods(self::PREVIOUS_PERIODS);
    }

    /** @param  list<string>  $periods */
    private function periods(array $periods): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $list = implode(', ', array_map(fn (string $period) => "'{$period}'", $periods));

        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} MODIFY interest_rate_frequency ENUM({$list}) NOT NULL DEFAULT 'monthly'");
        }
    }
};
