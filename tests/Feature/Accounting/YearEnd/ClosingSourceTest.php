<?php

namespace Tests\Feature\Accounting\YearEnd;

use App\Models\AccountingJournal;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The `closing` journal source: last in `accounting_journals.source`, after
 * every existing value in its order, and added by a migration that changes
 * nothing when it runs again.
 */
class ClosingSourceTest extends TestCase
{
    /**
     * Opted out of the per-test transaction: the migration is DDL, which
     * MySQL commits implicitly.
     */
    protected bool $wrapsEachTestInTransaction = false;

    private const MIGRATION = 'database/migrations/2026_11_09_203100_add_closing_to_accounting_journals_source.php';

    public function test_the_column_and_the_model_list_the_same_sources_with_closing_last(): void
    {
        $this->assertSame(AccountingJournal::SOURCES, $this->sources());
        $this->assertSame('closing', AccountingJournal::SOURCES[array_key_last(AccountingJournal::SOURCES)]);
    }

    public function test_a_journal_can_be_stored_with_the_closing_source(): void
    {
        $id = DB::table('accounting_journals')->insertGetId([
            'date' => '2025-12-31',
            'source' => 'closing',
            'description' => 'Year-end close 2025',
            'status' => 'draft',
            'total_debit' => 0,
            'total_credit' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('closing', DB::table('accounting_journals')->where('id', $id)->value('source'));
    }

    public function test_the_migration_appends_closing_keeps_every_value_in_order_and_runs_twice(): void
    {
        $without = array_values(array_diff(AccountingJournal::SOURCES, ['closing']));
        $quoted = implode(',', array_map(fn (string $value): string => "'{$value}'", $without));
        DB::statement("ALTER TABLE accounting_journals MODIFY source ENUM({$quoted}) NOT NULL");
        $this->assertSame($without, $this->sources());

        $migration = require base_path(self::MIGRATION);
        $migration->up();

        $this->assertSame([...$without, 'closing'], $this->sources());
        $column = $this->column();
        $this->assertSame('NO', $column->IS_NULLABLE);
        $this->assertNull($column->COLUMN_DEFAULT);

        $migration->up();

        $this->assertSame([...$without, 'closing'], $this->sources());
    }

    /** @return list<string> */
    private function sources(): array
    {
        preg_match_all("/'([^']*)'/", (string) $this->column()->COLUMN_TYPE, $matches);

        return $matches[1];
    }

    private function column(): object
    {
        return DB::selectOne(
            'select COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT from information_schema.COLUMNS
             where TABLE_SCHEMA = database() and TABLE_NAME = ? and COLUMN_NAME = ?',
            ['accounting_journals', 'source'],
        );
    }
}
