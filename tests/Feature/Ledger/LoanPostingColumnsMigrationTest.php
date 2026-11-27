<?php

namespace Tests\Feature\Ledger;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The loan columns Phase 2's release and restructure postings read: the
 * release method and reference, and the restructure split stored on the new
 * loan. Added nullable, so every existing loan reads "not recorded" and
 * nothing is recalculated; safe to run twice; no posted journal touched.
 */
class LoanPostingColumnsMigrationTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    /** DDL commits implicitly in MySQL, so this test cannot live inside a transaction. */
    protected bool $wrapsEachTestInTransaction = false;

    private const MIGRATION = 'migrations/2026_11_08_100100_add_release_method_and_restructure_split_to_loans_table.php';

    private const COLUMNS = [
        'release_method',
        'release_reference',
        'restructure_carried_principal',
        'restructure_capitalised_interest',
        'restructure_capitalised_penalty',
        'restructure_written_off_principal',
    ];

    public function test_it_adds_the_columns_nullable_runs_twice_and_touches_no_journal(): void
    {
        $this->seedAndLogin();
        $loan = $this->createReleasedLoan();
        $this->seedChartOfAccounts();
        $this->postSimpleJournal('1010', '4030', 150_000);

        Schema::table('loans', function (Blueprint $table): void {
            $table->dropColumn(self::COLUMNS);
        });
        $before = $this->journalFingerprint();

        $migration = $this->migration();
        $migration->up();
        $migration->up();

        foreach (self::COLUMNS as $column) {
            $this->assertTrue(Schema::hasColumn('loans', $column), $column);
        }

        $row = (array) DB::table('loans')->where('id', $loan->id)->first(self::COLUMNS);
        $this->assertSame(array_fill_keys(self::COLUMNS, null), $row);
        $this->assertSame($before, $this->journalFingerprint());

        $type = fn (string $column): string => strtolower((string) DB::selectOne(
            'SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['loans', $column],
        )->t);
        $this->assertSame('decimal(15,2)', $type('restructure_carried_principal'));
        $this->assertSame('decimal(15,2)', $type('restructure_written_off_principal'));
    }

    private function migration(): Migration
    {
        return require database_path(self::MIGRATION);
    }

    /** @return array{count: int, debit: int, credit: int, max_id: int} */
    private function journalFingerprint(): array
    {
        $lines = DB::table('accounting_journal_lines');

        return [
            'count' => (clone $lines)->count(),
            'debit' => (int) (clone $lines)->sum('debit'),
            'credit' => (int) (clone $lines)->sum('credit'),
            'max_id' => (int) (clone $lines)->max('id'),
        ];
    }
}
