<?php

namespace Tests\Feature\Accounting\YearEnd;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Models\AccountingJournal;
use Carbon\CarbonImmutable;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * GET /accounting/year-closes/preview?year=YYYY: each branch's income and
 * expense for the year, its net surplus, the months the close would close,
 * and every reason it can't run yet, in words. Writes no journal.
 */
class YearEndPreviewTest extends TestCase
{
    use PostsJournals, SetupLendyPH, YearEndFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpYearEnd();
    }

    public function test_the_preview_has_the_contract_shape(): void
    {
        $this->postTheYear();

        $preview = $this->preview();

        $this->assertSame([
            'year', 'as_of', 'net_surplus_account', 'branches', 'total_income', 'total_expenses', 'net_surplus',
            'periods_to_close', 'draft_journals', 'can_close', 'blocking_reasons',
        ], array_keys($preview));
        $this->assertSame(2025, $preview['year']);
        $this->assertSame('2025-12-31', $preview['as_of']);
        $this->assertSame(
            ['id' => $this->account('3030'), 'code' => '3030', 'name' => 'Retained Earnings'],
            $preview['net_surplus_account'],
        );
        $this->assertSame(['branch', 'lines', 'total_income', 'total_expenses', 'net_surplus'], array_keys($preview['branches'][0]));
        $this->assertSame(
            ['account_id', 'account_code', 'account_name', 'type', 'amount'],
            array_keys($preview['branches'][0]['lines'][0]),
        );
        $this->assertSame(0, $preview['draft_journals']);
        $this->assertTrue($preview['can_close']);
        $this->assertSame([], $preview['blocking_reasons']);
    }

    public function test_each_branch_shows_its_own_lines_and_net_surplus_with_no_branch_as_its_own_group(): void
    {
        $this->postTheYear();

        $branches = collect($this->preview()['branches']);

        $this->assertSame(
            [['id' => $this->far->id, 'name' => 'Far Branch'], ['id' => $this->branch->id, 'name' => $this->branch->name], null],
            $branches->pluck('branch')->all(),
            'branches in name order, the group with no branch last',
        );

        [$far, $main, $none] = $branches->all();

        $this->assertSame([
            ['account_id' => $this->account('4010'), 'account_code' => '4010', 'account_name' => 'Interest Income', 'type' => 'income', 'amount' => 8900000],
            ['account_id' => $this->account('5020'), 'account_code' => '5020', 'account_name' => 'Rent', 'type' => 'expense', 'amount' => 1500000],
        ], $main['lines']);
        $this->assertSame(8900000, $main['total_income']);
        $this->assertSame(1500000, $main['total_expenses']);
        $this->assertSame(self::MAIN_NET, $main['net_surplus']);

        $this->assertSame(['4010', '4020', '5010'], array_column($far['lines'], 'account_code'));
        $this->assertSame([2000000, 100050, 3000000], array_column($far['lines'], 'amount'));
        $this->assertSame(self::FAR_NET, $far['net_surplus'], 'a loss is negative');

        // An expense with a credit net reads negative, as it normally reads.
        $this->assertSame(['4070', '5010'], array_column($none['lines'], 'account_code'));
        $this->assertSame([400000, -20000], array_column($none['lines'], 'amount'));
        $this->assertSame(400000, $none['total_income']);
        $this->assertSame(-20000, $none['total_expenses']);
        $this->assertSame(self::NO_BRANCH_NET, $none['net_surplus']);
    }

    public function test_the_totals_are_the_years_and_equal_the_income_statement(): void
    {
        $this->postTheYear();

        $preview = $this->preview();
        $statement = $this->getJson('/api/accounting/statements/income-statement?from=2025-01-01&to=2025-12-31')->json('data');

        $this->assertSame(8900000 + 2100050 + 400000, $preview['total_income']);
        $this->assertSame(1500000 + 3000000 - 20000, $preview['total_expenses']);
        $this->assertSame(self::YEAR_NET, $preview['net_surplus']);
        $this->assertSame($statement['total_income'], $preview['total_income']);
        $this->assertSame($statement['total_expenses'], $preview['total_expenses']);
        $this->assertSame($statement['net_income'], $preview['net_surplus']);
    }

    public function test_a_branch_with_nothing_left_to_close_is_left_out(): void
    {
        $this->postSimpleJournal('1010', '4010', 50000, ['date' => '2025-02-01', 'branch_id' => $this->far->id]);
        $this->postSimpleJournal('4010', '1010', 50000, ['date' => '2025-02-02', 'branch_id' => $this->far->id]);
        $this->postSimpleJournal('1010', '3010', 50000, ['date' => '2025-02-03', 'branch_id' => $this->branch->id]);

        $preview = $this->preview();

        $this->assertSame([], $preview['branches']);
        $this->assertSame(0, $preview['net_surplus']);
        $this->assertTrue($preview['can_close']);
    }

    public function test_it_lists_every_month_of_the_year_still_open_and_leaves_out_closed_ones(): void
    {
        $this->postTheYear();
        $this->closePeriod('2025-06');

        $periods = $this->preview()['periods_to_close'];

        $this->assertSame(
            ['January 2025', 'February 2025', 'March 2025', 'April 2025', 'May 2025', 'July 2025', 'August 2025',
                'September 2025', 'October 2025', 'November 2025', 'December 2025'],
            array_column($periods, 'name'),
            'January and February too, though no journal falls in them',
        );
        $this->assertSame(['id', 'name'], array_keys($periods[0]));
    }

    public function test_a_year_that_has_not_ended_in_manila_cannot_be_closed(): void
    {
        $this->postTheYear();

        $reason = '2026 ends on 31 December 2026; it can be closed from 1 January 2027.';
        $preview = $this->preview(2026);

        $this->assertFalse($preview['can_close']);
        $this->assertSame([$reason, 'Close 2025 first.'], $preview['blocking_reasons']);

        // 11.59 pm on 31 December in Manila: still 2026.
        $this->travelTo(CarbonImmutable::parse('2026-12-31 23:59:00', 'Asia/Manila'));
        $this->assertContains($reason, $this->preview(2026)['blocking_reasons']);

        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:00:00', 'Asia/Manila'));
        $this->assertNotContains($reason, $this->preview(2026)['blocking_reasons']);
    }

    public function test_the_previous_year_must_be_closed_once_anything_is_posted_before_this_year(): void
    {
        $this->postTheYear();
        $this->postSimpleJournal('1010', '4010', 10000, ['date' => '2024-11-01']);

        $preview = $this->preview();

        $this->assertFalse($preview['can_close']);
        $this->assertSame(['Close 2024 first.'], $preview['blocking_reasons']);
    }

    public function test_an_earlier_unclosed_year_blocks_even_when_the_previous_year_is_empty(): void
    {
        $this->postTheYear();
        $this->postSimpleJournal('1010', '4010', 10000, ['date' => '2023-05-01']);

        $this->assertSame(['Close 2024 first.'], $this->preview()['blocking_reasons']);
    }

    public function test_the_first_year_of_the_books_needs_no_earlier_close(): void
    {
        $this->postTheYear();
        // A draft before the year is not a posted journal.
        $this->draftJournal([
            ['account_id' => $this->account('1010'), 'debit' => 100, 'credit' => 0],
            ['account_id' => $this->account('4010'), 'debit' => 0, 'credit' => 100],
        ], ['date' => '2024-12-01']);

        $this->assertTrue($this->preview()['can_close']);
    }

    public function test_drafts_dated_in_the_year_block_the_close(): void
    {
        $this->postTheYear();
        $line = [
            ['account_id' => $this->account('1010'), 'debit' => 100, 'credit' => 0],
            ['account_id' => $this->account('4010'), 'debit' => 0, 'credit' => 100],
        ];
        $this->draftJournal($line, ['date' => '2025-04-01']);

        $preview = $this->preview();
        $this->assertSame(1, $preview['draft_journals']);
        $this->assertSame(['1 draft journal is dated in 2025. Post or delete it first.'], $preview['blocking_reasons']);

        $this->draftJournal($line, ['date' => '2025-12-31', 'branch_id' => null]);
        $this->draftJournal($line, ['date' => '2025-01-01', 'branch_id' => $this->far->id]);
        // Outside the year: not counted.
        $this->draftJournal($line, ['date' => '2026-01-01']);

        $preview = $this->preview();
        $this->assertSame(3, $preview['draft_journals']);
        $this->assertSame(['3 draft journals are dated in 2025. Post or delete them first.'], $preview['blocking_reasons']);
        $this->assertFalse($preview['can_close']);
    }

    public function test_a_closed_december_blocks_the_close(): void
    {
        $this->postTheYear();
        $this->closePeriod('2025-12');

        $preview = $this->preview();

        $this->assertFalse($preview['can_close']);
        $this->assertSame(
            ['December 2025 is closed. Reopen it so the closing entries can be dated 31 December.'],
            $preview['blocking_reasons'],
        );
        $this->assertNotContains('December 2025', array_column($preview['periods_to_close'], 'name'));
    }

    public function test_an_unmapped_net_surplus_role_blocks_the_close(): void
    {
        $this->postTheYear();
        AccountingAccountMapping::query()->where('role', 'net_surplus')->delete();

        $preview = $this->preview();

        $this->assertNull($preview['net_surplus_account']);
        $this->assertFalse($preview['can_close']);
        $this->assertSame(
            ['The net surplus account is not set. Choose an equity account for it in Accounting → Settings → Default Accounts.'],
            $preview['blocking_reasons'],
        );
    }

    public function test_a_net_surplus_role_on_an_account_that_is_not_equity_blocks_the_close(): void
    {
        $this->postTheYear();
        AccountingAccountMapping::query()->where('role', 'net_surplus')->update(['accounting_account_id' => $this->account('1010')]);

        $preview = $this->preview();

        $this->assertSame('1010', $preview['net_surplus_account']['code']);
        $this->assertFalse($preview['can_close']);
        $this->assertSame(
            ['The net surplus account, 1010 Cash on Hand, is not an equity account. Choose an equity account for it in Accounting → Settings → Default Accounts.'],
            $preview['blocking_reasons'],
        );
    }

    public function test_an_inactive_net_surplus_account_blocks_the_close(): void
    {
        $this->postTheYear();
        AccountingAccount::query()->whereKey($this->account('3030'))->update(['is_active' => false]);

        $this->assertSame(
            ["The net surplus account, 3030 Retained Earnings, can't take postings because it is inactive. Choose an equity account for it in Accounting → Settings → Default Accounts."],
            $this->preview()['blocking_reasons'],
        );
    }

    public function test_a_closed_year_cannot_be_closed_again(): void
    {
        $this->postTheYear();
        $this->closed();

        $preview = $this->preview();

        $this->assertFalse($preview['can_close']);
        $this->assertContains('2025 is already closed.', $preview['blocking_reasons']);
    }

    public function test_every_reason_is_listed_at_once(): void
    {
        $this->postTheYear();
        $this->postSimpleJournal('1010', '4010', 10000, ['date' => '2024-11-01']);
        $this->draftJournal([
            ['account_id' => $this->account('1010'), 'debit' => 100, 'credit' => 0],
            ['account_id' => $this->account('4010'), 'debit' => 0, 'credit' => 100],
        ], ['date' => '2025-04-01']);
        $this->closePeriod('2025-12');
        AccountingAccountMapping::query()->where('role', 'net_surplus')->delete();

        $this->assertCount(4, $this->preview()['blocking_reasons']);
    }

    public function test_the_preview_writes_no_journal(): void
    {
        $this->postTheYear();
        $before = AccountingJournal::query()->count();

        $this->preview();

        $this->assertSame($before, AccountingJournal::query()->count());
    }

    public function test_the_year_is_validated(): void
    {
        $this->getJson('/api/accounting/year-closes/preview')->assertUnprocessable()->assertJsonValidationErrors('year');
        $this->getJson('/api/accounting/year-closes/preview?year=twenty')->assertUnprocessable()->assertJsonValidationErrors('year');
        $this->getJson('/api/accounting/year-closes/preview?year=1899')->assertUnprocessable()->assertJsonValidationErrors('year');
    }

    public function test_the_preview_needs_accounting_close_and_view_all(): void
    {
        $this->actingAs($this->userWith(['accounting:view', 'accounting:close']));
        $this->getJson('/api/accounting/year-closes/preview?year=2025')->assertForbidden();

        $this->actingAs($this->userWith(['accounting:view', 'branches:view_all']));
        $this->getJson('/api/accounting/year-closes/preview?year=2025')->assertForbidden();

        $this->actingAs($this->userWith(['accounting:close', 'branches:view_all']));
        $this->getJson('/api/accounting/year-closes/preview?year=2025')->assertOk();
    }
}
