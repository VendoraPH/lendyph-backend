<?php

namespace Tests\Feature\Accounting\OpeningBalances;

use App\Models\AccountingJournal;
use App\Models\AuditLog;
use Tests\TestCase;

/**
 * Lead's call 3: a contra account on its unusual side is a non-blocking
 * warning, `warnings[]`, in words. And the opening balance messages read
 * their date in words ("Aug 31, 2026"), never `2026-08-31`.
 */
class OpeningBalanceWarningsAndDatesTest extends TestCase
{
    use OpeningBalanceTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOpeningBalances();
    }

    public function test_a_debit_on_the_allowance_is_a_warning_that_does_not_block(): void
    {
        $version = (int) $this->save([
            ['account_id' => $this->account('1010'), 'debit' => 50_000, 'credit' => 0],
            ['account_id' => $this->account('1200'), 'debit' => 40_000, 'credit' => 0],
            ['account_id' => $this->account('3050'), 'debit' => 0, 'credit' => 90_000],
        ])->assertOk()
            ->assertJsonPath('data.warnings', [
                '1200 Allowance for Credit Losses has a debit of ₱400.00. It is a contra account that normally carries a credit balance, '
                .'so a debit adds to the assets it should reduce. Check the side before finalising.',
            ])
            ->json('data.edit_version');

        $this->getJson(self::URL)->assertOk()
            ->assertJsonCount(1, 'data.warnings')
            ->assertJsonPath('data.blocking_reasons', [])
            ->assertJsonPath('data.can_finalise', true);

        $this->finalise($version)->assertOk();
    }

    public function test_a_contra_account_on_its_usual_side_has_no_warning(): void
    {
        $this->save([
            ['account_id' => $this->account('1110'), 'debit' => 90_000, 'credit' => 0],
            ['account_id' => $this->account('1200'), 'debit' => 0, 'credit' => 40_000],
            ['account_id' => $this->account('3050'), 'debit' => 0, 'credit' => 50_000],
        ])->assertOk()->assertJsonPath('data.warnings', []);
    }

    public function test_no_entry_has_no_warnings(): void
    {
        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.warnings', []);
    }

    public function test_the_audit_rows_and_the_journal_read_the_date_in_words(): void
    {
        $version = $this->saveBalancedDraft();
        $this->save($this->balancedLines(), $version, '2026-07-31')->assertOk();
        $this->finalise($version + 1)->assertOk();

        $this->assertStringStartsWith(
            'Started the opening balances as of Aug 31, 2026 ',
            (string) AuditLog::query()->where('action', 'accounting_opening_balance_created')->sole()->description,
        );
        $this->assertStringStartsWith(
            'Saved the opening balances as of Jul 31, 2026:',
            (string) AuditLog::query()->where('action', 'accounting_opening_balance_saved')->sole()->description,
        );
        $this->assertStringStartsWith(
            'Finalised the opening balances as of Jul 31, 2026,',
            (string) AuditLog::query()->where('action', 'accounting_opening_balance_finalised')->sole()->description,
        );
        $this->assertSame('Opening balances as of Jul 31, 2026', AccountingJournal::query()->sole()->description);
    }

    public function test_a_closed_as_of_month_is_refused_with_the_date_in_words(): void
    {
        $this->closeMonth(self::AS_OF);

        $this->save($this->balancedLines())
            ->assertStatus(422)
            ->assertJsonPath('errors.as_of.0', fn (string $message): bool => str_starts_with($message, 'The opening balance entry is dated Aug 31, 2026, which falls in'));
    }
}
