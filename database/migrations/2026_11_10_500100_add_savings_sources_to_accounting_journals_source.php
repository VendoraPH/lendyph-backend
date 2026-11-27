<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Journal sources for Savings & Deposits v1 (Phase 3, design §4): `savings`
 * (deposits, withdrawals and closing payouts), `savings_interest` (interest
 * runs and the interest credited at close), `time_deposit` (placements) and
 * `time_deposit_settlement` (maturity, rollover and pre-termination).
 *
 * `accounting_journals.source` is a MySQL ENUM, so the values take a raw
 * MODIFY that lists every value. Like 2026_11_09_203100, the list is read
 * from the live column, every value a deployment holds is kept in its order,
 * and only the missing ones are appended, in this order. A column that
 * already has all four is left alone, so running this again changes nothing.
 * No row changes.
 */
return new class extends Migration
{
    private const SOURCES = ['savings', 'savings_interest', 'time_deposit', 'time_deposit_settlement'];

    public function up(): void
    {
        $column = DB::selectOne(
            'select COLUMN_TYPE from information_schema.COLUMNS
             where TABLE_SCHEMA = database() and TABLE_NAME = ? and COLUMN_NAME = ?',
            ['accounting_journals', 'source'],
        );

        if ($column === null) {
            return;
        }

        preg_match_all("/'((?:[^']|'')*)'/", (string) $column->COLUMN_TYPE, $matches);
        $values = array_map(static fn (string $value): string => str_replace("''", "'", $value), $matches[1]);
        $missing = array_values(array_diff(self::SOURCES, $values));

        if ($values === [] || $missing === []) {
            return;
        }

        $list = implode(',', array_map(static fn (string $value): string => DB::getPdo()->quote($value), [...$values, ...$missing]));

        DB::statement("ALTER TABLE accounting_journals MODIFY source ENUM({$list}) NOT NULL");
    }

    /**
     * Nothing to undo: narrowing the enum would fail on any savings journal,
     * and a posted journal is never rewritten.
     */
    public function down(): void {}
};
