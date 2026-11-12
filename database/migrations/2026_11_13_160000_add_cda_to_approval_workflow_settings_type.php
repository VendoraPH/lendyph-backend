<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The CDA review chain (gap round B6): Settings → Approval Workflow gets a
 * `cda` type next to `normal` and `policy_exception`.
 *
 * `approval_workflow_settings.type` is a MySQL ENUM, so the value takes a raw
 * MODIFY that lists every value. As in 2026_11_10_500100, the list is read
 * from the live column, every value a deployment holds is kept in its order,
 * and only `cda` is appended. A column that already has it is left alone, so
 * running this again changes nothing. No row changes.
 */
return new class extends Migration
{
    private const TYPE = 'cda';

    public function up(): void
    {
        $values = $this->values();

        if ($values === [] || in_array(self::TYPE, $values, true)) {
            return;
        }

        $this->modify([...$values, self::TYPE]);
    }

    /**
     * Narrows the column again only while no `cda` chain is saved; a saved
     * chain is kept, and the column with it.
     */
    public function down(): void
    {
        $values = $this->values();

        if (! in_array(self::TYPE, $values, true) || DB::table('approval_workflow_settings')->where('type', self::TYPE)->exists()) {
            return;
        }

        $this->modify(array_values(array_diff($values, [self::TYPE])));
    }

    /** @return list<string> */
    private function values(): array
    {
        $column = DB::selectOne(
            'select COLUMN_TYPE from information_schema.COLUMNS
             where TABLE_SCHEMA = database() and TABLE_NAME = ? and COLUMN_NAME = ?',
            ['approval_workflow_settings', 'type'],
        );

        if ($column === null) {
            return [];
        }

        preg_match_all("/'((?:[^']|'')*)'/", (string) $column->COLUMN_TYPE, $matches);

        return array_map(static fn (string $value): string => str_replace("''", "'", $value), $matches[1]);
    }

    /** @param  list<string>  $values */
    private function modify(array $values): void
    {
        $list = implode(',', array_map(static fn (string $value): string => DB::getPdo()->quote($value), $values));

        DB::statement("ALTER TABLE approval_workflow_settings MODIFY type ENUM({$list}) NOT NULL");
    }
};
