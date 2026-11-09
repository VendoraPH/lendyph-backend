<?php

namespace Tests\Feature\Accounting\OpeningBalances;

use App\Models\AccountingAccount;
use Tests\TestCase;

/**
 * GET /accounting/opening-balances: every figure the screen shows is the
 * server's, the 3050 line that would balance the entry included.
 */
class OpeningBalancePreviewTest extends TestCase
{
    use OpeningBalanceTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOpeningBalances();
    }

    public function test_nothing_saved_reads_none(): void
    {
        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.status', 'none')
            ->assertJsonPath('data.id', null)
            ->assertJsonPath('data.as_of', null)
            ->assertJsonPath('data.lines', [])
            ->assertJsonPath('data.total_debit', 0)
            ->assertJsonPath('data.total_credit', 0)
            ->assertJsonPath('data.difference', 0)
            ->assertJsonPath('data.is_balanced', false)
            ->assertJsonPath('data.opening_equity_line', null)
            ->assertJsonPath('data.suggested_opening_equity_line', null)
            ->assertJsonPath('data.posted_journals_on_or_before_as_of', 0)
            ->assertJsonPath('data.journal', null)
            ->assertJsonPath('data.edit_version', 0)
            ->assertJsonPath('data.finalised_at', null)
            ->assertJsonPath('data.finalised_by', null)
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.can_finalise', false)
            ->assertJsonPath('data.blocking_reasons', ['Add at least two lines.']);
    }

    public function test_more_debit_suggests_a_3050_credit_for_the_difference(): void
    {
        $this->save($this->unbalancedLines())->assertOk();

        $data = $this->getJson(self::URL)->assertOk()->json('data');

        $this->assertSame(250_000_000, $data['total_debit']);
        $this->assertSame(15_000_000, $data['total_credit']);
        $this->assertSame(235_000_000, $data['difference']);
        $this->assertFalse($data['is_balanced']);
        $this->assertNull($data['opening_equity_line']);
        $this->assertSame([
            'account_id' => $this->account('3050'),
            'account_code' => '3050',
            'account_name' => 'Opening Balance Equity',
            'debit' => 0,
            'credit' => 235_000_000,
        ], $data['suggested_opening_equity_line']);
        $this->assertSame(
            ['The debits and credits differ by ₱2,350,000.00. Add the difference to 3050 Opening Balance Equity or correct a line.'],
            $data['blocking_reasons'],
        );
        $this->assertFalse($data['can_finalise']);
        // The lines in code order, with each account's figures.
        $this->assertSame(['1010', '1110', '2300'], array_column($data['lines'], 'account_code'));
        $this->assertSame('asset', $data['lines'][0]['account_type']);
        $this->assertSame('Cash on Hand', $data['lines'][0]['account_name']);
    }

    public function test_more_credit_suggests_a_3050_debit(): void
    {
        $this->save([
            ['account_id' => $this->account('1010'), 'debit' => 10_000, 'credit' => 0],
            ['account_id' => $this->account('2300'), 'debit' => 0, 'credit' => 25_000],
        ])->assertOk();

        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.difference', -15_000)
            ->assertJsonPath('data.suggested_opening_equity_line.debit', 15_000)
            ->assertJsonPath('data.suggested_opening_equity_line.credit', 0);
    }

    public function test_the_suggestion_adds_the_difference_to_a_3050_line_already_there(): void
    {
        $this->save([
            ...$this->unbalancedLines(),
            ['account_id' => $this->account('3050'), 'debit' => 0, 'credit' => 200_000_000],
        ])->assertOk();

        $data = $this->getJson(self::URL)->assertOk()->json('data');

        $this->assertSame(35_000_000, $data['difference']);
        $this->assertSame('3050', $data['opening_equity_line']['account_code']);
        $this->assertSame(200_000_000, $data['opening_equity_line']['credit']);
        $this->assertSame(235_000_000, $data['suggested_opening_equity_line']['credit']);
        $this->assertSame(0, $data['suggested_opening_equity_line']['debit']);
    }

    public function test_a_3050_line_on_the_wrong_side_flips_when_the_difference_outweighs_it(): void
    {
        $this->save([
            ['account_id' => $this->account('1010'), 'debit' => 50_000, 'credit' => 0],
            ['account_id' => $this->account('3050'), 'debit' => 10_000, 'credit' => 0],
        ])->assertOk();

        // Debits exceed credits by ₱600.00; 3050 goes from a ₱100.00 debit to a ₱500.00 credit.
        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.suggested_opening_equity_line.debit', 0)
            ->assertJsonPath('data.suggested_opening_equity_line.credit', 50_000);
    }

    public function test_a_balanced_entry_has_no_suggestion_and_can_be_finalised(): void
    {
        $this->saveBalancedDraft();

        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.is_balanced', true)
            ->assertJsonPath('data.difference', 0)
            ->assertJsonPath('data.suggested_opening_equity_line', null)
            ->assertJsonPath('data.opening_equity_line.credit', 235_000_000)
            ->assertJsonPath('data.blocking_reasons', [])
            ->assertJsonPath('data.can_finalise', true);
    }

    public function test_no_suggestion_when_the_chart_has_no_3050(): void
    {
        $this->save($this->unbalancedLines())->assertOk();
        AccountingAccount::query()->whereKey($this->account('3050'))->delete();

        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.suggested_opening_equity_line', null);
    }

    public function test_one_line_is_not_balanced(): void
    {
        $this->save([['account_id' => $this->account('1010'), 'debit' => 100, 'credit' => 0]])->assertOk();

        $reasons = $this->getJson(self::URL)->assertOk()->assertJsonPath('data.is_balanced', false)->json('data.blocking_reasons');

        $this->assertContains('Add at least two lines.', $reasons);
    }

    public function test_the_warning_counts_posted_journals_on_or_before_as_of(): void
    {
        $this->postSimpleJournal('1010', '2300', 100, ['date' => self::AS_OF]);
        $this->postSimpleJournal('1010', '2300', 100, ['date' => '2026-08-01']);
        $this->postSimpleJournal('1010', '2300', 100, ['date' => '2026-09-01']);
        $this->draftJournal([
            ['account_id' => $this->account('1010'), 'debit' => 100, 'credit' => 0],
            ['account_id' => $this->account('2300'), 'debit' => 0, 'credit' => 100],
        ], ['date' => '2026-08-15']);

        $this->save($this->unbalancedLines())->assertOk();

        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.posted_journals_on_or_before_as_of', 2);
    }

    public function test_a_closed_period_is_a_blocking_reason(): void
    {
        $this->saveBalancedDraft();
        $this->closeMonth(self::AS_OF);

        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.blocking_reasons', ['August 2026 is closed.'])
            ->assertJsonPath('data.can_finalise', false);
    }

    public function test_a_cli_opening_balance_reads_posted_elsewhere(): void
    {
        $journal = $this->postSimpleJournal('1010', '3050', 10_000, ['source' => 'opening_balance', 'branch_id' => null]);

        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.status', 'posted_elsewhere')
            ->assertJsonPath('data.journal', ['id' => $journal->id, 'journal_no' => $journal->journal_no])
            ->assertJsonPath('data.can_edit', false)
            ->assertJsonPath('data.can_finalise', false)
            ->assertJsonPath('data.blocking_reasons', ["An opening balance has already been posted ({$journal->journal_no})."]);
    }
}
