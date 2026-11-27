<?php

namespace Tests\Feature\Savings\Foundation;

use App\Models\AccountingJournal;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The four savings journal sources (design §4), appended to
 * `accounting_journals.source` after every existing value, in the model's
 * order, by a migration that changes nothing when it runs again.
 */
class SavingsJournalSourcesTest extends TestCase
{
    /** The migration is DDL, which MySQL commits implicitly. */
    protected bool $wrapsEachTestInTransaction = false;

    private const MIGRATION = 'database/migrations/2026_11_10_500100_add_savings_sources_to_accounting_journals_source.php';

    private const SAVINGS = ['savings', 'savings_interest', 'time_deposit', 'time_deposit_settlement'];

    public function test_the_migration_appends_the_four_keeps_every_value_and_runs_twice(): void
    {
        $this->assertSame(AccountingJournal::SOURCES, $this->sources());
        $this->assertSame(self::SAVINGS, array_slice(AccountingJournal::SOURCES, -4));

        $without = array_values(array_diff(AccountingJournal::SOURCES, self::SAVINGS));
        $quoted = implode(',', array_map(fn (string $value): string => "'{$value}'", $without));
        DB::statement("ALTER TABLE accounting_journals MODIFY source ENUM({$quoted}) NOT NULL");

        $migration = require base_path(self::MIGRATION);
        $migration->up();
        $this->assertSame([...$without, ...self::SAVINGS], $this->sources());

        $migration->up();
        $this->assertSame([...$without, ...self::SAVINGS], $this->sources());
        $this->assertSame('NO', $this->column()->IS_NULLABLE);
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
            'select COLUMN_TYPE, IS_NULLABLE from information_schema.COLUMNS
             where TABLE_SCHEMA = database() and TABLE_NAME = ? and COLUMN_NAME = ?',
            ['accounting_journals', 'source'],
        );
    }
}
