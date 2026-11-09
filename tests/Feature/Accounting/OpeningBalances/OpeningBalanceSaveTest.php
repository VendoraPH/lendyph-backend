<?php

namespace Tests\Feature\Accounting\OpeningBalances;

use App\Models\AccountingAccount;
use App\Models\AccountingOpeningBalance;
use App\Models\AccountingOpeningBalanceLine;
use App\Models\AuditLog;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PUT /accounting/opening-balances: the draft is created on the first save
 * and its lines are replaced wholesale on every later one.
 */
class OpeningBalanceSaveTest extends TestCase
{
    use OpeningBalanceTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOpeningBalances();
    }

    public function test_the_first_save_creates_the_draft_and_its_lines(): void
    {
        $response = $this->save($this->unbalancedLines())->assertOk();

        $response->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.as_of', self::AS_OF)
            ->assertJsonPath('data.edit_version', 1)
            ->assertJsonCount(3, 'data.lines')
            ->assertJsonPath('data.lines.0.account_code', '1010')
            ->assertJsonPath('data.lines.0.debit', 40_000_000);

        $draft = AccountingOpeningBalance::query()->sole();
        $this->assertSame('draft', $draft->status);
        $this->assertSame($this->admin->id, $draft->created_by);
        $this->assertSame(3, $draft->lines()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'accounting_opening_balance_created')->count());
    }

    public function test_a_later_save_replaces_the_lines_wholesale_and_bumps_the_version(): void
    {
        $this->save($this->unbalancedLines())->assertOk();

        $response = $this->save([
            ['account_id' => $this->account('1010'), 'debit' => 5_000, 'credit' => 0],
            ['account_id' => $this->account('3050'), 'debit' => 0, 'credit' => 5_000],
        ], 1)->assertOk();

        $response->assertJsonPath('data.edit_version', 2)->assertJsonCount(2, 'data.lines');
        $this->assertSame(2, AccountingOpeningBalanceLine::query()->count());
        $this->assertSame(1, AccountingOpeningBalance::query()->count());

        $audit = AuditLog::query()->where('action', 'accounting_opening_balance_saved')->sole();
        $this->assertSame(3, $audit->old_values['line_count']);
        $this->assertSame(250_000_000, $audit->old_values['total_debit_centavos']);
        $this->assertSame(2, $audit->new_values['line_count']);
        $this->assertSame(5_000, $audit->new_values['total_credit_centavos']);
    }

    public function test_lines_may_be_empty(): void
    {
        $this->save([])->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonCount(0, 'data.lines');
    }

    public function test_a_save_that_changes_nothing_writes_no_audit_row_and_keeps_the_version(): void
    {
        $this->save($this->unbalancedLines())->assertOk();
        $before = AuditLog::query()->count();

        // The same lines in another order are the same entry.
        $this->save(array_reverse($this->unbalancedLines()), 1)->assertOk()->assertJsonPath('data.edit_version', 1);

        $this->assertSame($before, AuditLog::query()->count());
    }

    public function test_a_stale_edit_version_answers_409(): void
    {
        $this->save($this->unbalancedLines())->assertOk();
        $this->save($this->balancedLines(), 1)->assertOk();

        $this->save($this->unbalancedLines(), 1)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Someone else changed the opening balances; reload and try again.');
        $this->assertSame(4, AccountingOpeningBalanceLine::query()->count());
    }

    public function test_the_same_account_twice_is_refused_on_the_second_line(): void
    {
        $lines = $this->unbalancedLines();
        $lines[] = ['account_id' => $this->account('1010'), 'debit' => 100, 'credit' => 0];

        $this->save($lines)->assertStatus(422)->assertJsonValidationErrors(['lines.3.account_id']);
        $this->assertSame(0, AccountingOpeningBalance::query()->count());
    }

    public function test_an_account_id_sent_as_a_string_is_checked_the_same_way(): void
    {
        $this->save([
            ['account_id' => (string) $this->account('1010'), 'debit' => 100, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 100],
        ])->assertStatus(422)->assertJsonValidationErrors(['lines.1.account_id']);

        $this->save([
            ['account_id' => (string) $this->account('1000'), 'debit' => 100, 'credit' => 0],
            ['account_id' => $this->account('3050'), 'debit' => 0, 'credit' => 100],
        ])->assertStatus(422)->assertJsonValidationErrors(['lines.0.account_id']);
    }

    public function test_a_heading_account_is_refused(): void
    {
        $this->save([
            ['account_id' => $this->account('1000'), 'debit' => 100, 'credit' => 0],
            ['account_id' => $this->account('3050'), 'debit' => 0, 'credit' => 100],
        ])->assertStatus(422)->assertJsonValidationErrors(['lines.0.account_id']);
    }

    public function test_an_inactive_account_is_refused(): void
    {
        AccountingAccount::query()->whereKey($this->account('2300'))->update(['is_active' => false]);

        $this->save($this->unbalancedLines())->assertStatus(422)->assertJsonValidationErrors(['lines.2.account_id']);
    }

    public function test_an_unknown_account_is_refused(): void
    {
        $this->save([['account_id' => 999_999, 'debit' => 100, 'credit' => 0]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.account_id']);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badAmounts(): array
    {
        return [
            'both sides' => [['debit' => 100, 'credit' => 100], 'lines.0.debit'],
            'neither side' => [['debit' => 0, 'credit' => 0], 'lines.0.debit'],
            'a negative debit' => [['debit' => -100, 'credit' => 0], 'lines.0.debit'],
            'a negative credit' => [['debit' => 0, 'credit' => -100], 'lines.0.credit'],
            'pesos rather than centavos' => [['debit' => 10.5, 'credit' => 0], 'lines.0.debit'],
            'past the books\' ceiling' => [['debit' => PHP_INT_MAX, 'credit' => 0], 'lines.0.debit'],
        ];
    }

    /** @param  array<string, mixed>  $amounts */
    #[DataProvider('badAmounts')]
    public function test_a_bad_amount_is_refused(array $amounts, string $field): void
    {
        $this->save([['account_id' => $this->account('1010')] + $amounts])
            ->assertStatus(422)
            ->assertJsonValidationErrors([$field]);
    }

    public function test_more_than_500_lines_is_refused(): void
    {
        $lines = array_fill(0, 501, ['account_id' => $this->account('1010'), 'debit' => 1, 'credit' => 0]);

        $this->save($lines)->assertStatus(422)->assertJsonValidationErrors(['lines']);
    }

    public function test_an_as_of_in_a_closed_period_is_refused_with_the_period_guards_message(): void
    {
        $this->closeMonth(self::AS_OF);

        $response = $this->save($this->unbalancedLines())->assertStatus(422)->assertJsonValidationErrors(['as_of']);

        $this->assertStringContainsString('August 2026 — a period that was closed', $response->json('errors.as_of.0'));
        $this->assertSame(0, AccountingOpeningBalance::query()->count());
    }

    public function test_an_as_of_that_is_not_a_date_or_is_in_the_future_is_refused(): void
    {
        $this->save($this->unbalancedLines(), asOf: '31/08/2026')->assertStatus(422)->assertJsonValidationErrors(['as_of']);
        $this->save($this->unbalancedLines(), asOf: now()->addDay()->toDateString())->assertStatus(422)->assertJsonValidationErrors(['as_of']);
    }

    public function test_a_save_after_finalising_answers_409(): void
    {
        $this->finalise($this->saveBalancedDraft())->assertOk();

        $this->save($this->unbalancedLines(), 2)->assertStatus(409);
        $this->assertSame(4, AccountingOpeningBalanceLine::query()->count());
    }

    public function test_a_save_after_the_cli_posted_answers_409(): void
    {
        $this->postSimpleJournal('1010', '3050', 10_000, ['source' => 'opening_balance', 'branch_id' => null]);

        $this->save($this->unbalancedLines())->assertStatus(409);
        $this->assertSame(0, AccountingOpeningBalance::query()->count());
    }
}
