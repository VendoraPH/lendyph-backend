<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Journal source `closing`, written by the year-end close
 * (App\Services\Accounting\YearEndCloser).
 *
 * `accounting_journals.source` is a MySQL ENUM, so the value takes a raw
 * MODIFY that lists every value. The list is read from the live column, not
 * from the model, so every value a deployment holds is kept, in its order,
 * with `closing` added at the end. A column that already has `closing` is
 * left alone, so running this again changes nothing. No row changes.
 */
return new class extends Migration
{
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

        if ($values === [] || in_array('closing', $values, true)) {
            return;
        }

        $values[] = 'closing';
        $list = implode(',', array_map(static fn (string $value): string => DB::getPdo()->quote($value), $values));

        DB::statement("ALTER TABLE accounting_journals MODIFY source ENUM({$list}) NOT NULL");
    }

    /**
     * Nothing to undo: narrowing the enum would fail on any `closing` journal,
     * and a posted journal is never rewritten.
     */
    public function down(): void {}
};
