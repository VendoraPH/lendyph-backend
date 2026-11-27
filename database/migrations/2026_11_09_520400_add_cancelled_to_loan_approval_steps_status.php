<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Approval step status `cancelled`, written when a loan is voided while its
 * chain is unfinished (LoanApprovalChainService::cancelOpenSteps()).
 *
 * `loan_approval_steps.status` is a MySQL ENUM, so the value takes a raw
 * MODIFY that lists every value. The list is read from the live column, not
 * from the model, so every value a deployment holds is kept, in its order,
 * with `cancelled` added at the end, and the column keeps its `waiting`
 * default. A column that already has `cancelled` is left alone, so running
 * this again changes nothing. No row changes: loans voided before this keep
 * their steps as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        $column = DB::selectOne(
            'select COLUMN_TYPE from information_schema.COLUMNS
             where TABLE_SCHEMA = database() and TABLE_NAME = ? and COLUMN_NAME = ?',
            ['loan_approval_steps', 'status'],
        );

        if ($column === null) {
            return;
        }

        preg_match_all("/'((?:[^']|'')*)'/", (string) $column->COLUMN_TYPE, $matches);
        $values = array_map(static fn (string $value): string => str_replace("''", "'", $value), $matches[1]);

        if ($values === [] || in_array('cancelled', $values, true)) {
            return;
        }

        $values[] = 'cancelled';
        $list = implode(',', array_map(static fn (string $value): string => DB::getPdo()->quote($value), $values));

        DB::statement("ALTER TABLE loan_approval_steps MODIFY status ENUM({$list}) NOT NULL DEFAULT 'waiting'");
    }

    /**
     * Nothing to undo: narrowing the enum would fail on any cancelled step,
     * and a step's history is never rewritten.
     */
    public function down(): void {}
};
