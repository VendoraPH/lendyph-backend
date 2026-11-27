<?php

namespace Tests\Feature\Savings\TimeDeposits;

use App\Models\AccountingJournal;
use App\Models\SavingsTaxRule;
use App\Models\TimeDeposit;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Post-maturity interest (Arvin's standing approval, standard option): a
 * placement settled after its maturity date earns, for the days from the
 * maturity date to the settlement date, the regular savings rate (its
 * pre-termination product's rate in force on each day) on its maturity
 * amount, half up, taxed at the holder's tax rule on the settlement date.
 * It posts with the settlement and rolls over with `rollover_all`.
 *
 * The fixtures: ₱100,000.00 at 3% for 90 days matures on Feb 8, 2027 at
 * ₱100,739.73; the regular rate is 1%.
 */
class TimeDepositPostMaturityTest extends TestCase
{
    use TimeDepositFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTimeDeposits();
    }

    protected function tearDown(): void
    {
        $this->tearDownTimeDeposits();
        parent::tearDown();
    }

    private function placed(array $overrides = []): TimeDeposit
    {
        return TimeDeposit::query()->findOrFail($this->place($overrides)->assertCreated()->json('data.id'));
    }

    private function settleOn(TimeDeposit $deposit, string $date, array $body = [])
    {
        $this->travelTo(Carbon::parse("{$date} 09:00:00", 'Asia/Manila'));

        return $this->idem("/api/savings/time-deposits/{$deposit->id}/settle", ['date' => $date, 'action' => 'payout', 'payout_method' => 'savings', ...$body]);
    }

    public function test_a_payout_14_days_late_earns_the_regular_rate_for_those_days(): void
    {
        SavingsTaxRule::query()->create(['holder_type' => 'member', 'rate' => '20.0000', 'effective_from' => '2027-02-01']);
        $account = $this->savingsAccount();
        $deposit = $this->placed(['payout_account_id' => $account->id]);

        $this->travelTo(Carbon::parse('2027-02-22 08:00:00', 'Asia/Manila'));
        $this->getJson("/api/savings/time-deposits/{$deposit->id}")->assertOk()
            ->assertJsonPath('data.post_maturity_days', 14)
            ->assertJsonPath('data.post_maturity_interest', '38.64');

        // ₱100,739.73 × 1% × 14 ÷ 365 = 3,863.99 centavos → ₱38.64, taxed 20% (in force on the settlement date) → ₱7.73.
        $data = $this->settleOn($deposit, '2027-02-22')->assertCreated()->json('data');

        $this->assertSame(14, $data['post_maturity_days']);
        $this->assertSame('38.64', $data['post_maturity_interest']);
        $this->assertSame('778.37', $data['settled_interest']);
        $this->assertSame('7.73', $data['settled_tax']);
        $this->assertSame('100770.64', $data['settled_amount']);
        $this->assertSame('100770.64', $account->refresh()->ledger_balance);

        $journal = $this->journalFor(TimeDeposit::class, $deposit->id, 'time_deposit_settlement');
        $this->assertSame('2027-02-22', $journal->date->toDateString());
        $this->assertSame([['2060', 10_000_000, 0], ['5160', 77_837, 0], ['2210', 0, 773], ['2050', 0, 10_077_064]], $this->lines($journal));
    }

    public function test_a_rollover_14_days_late_rolls_the_net_post_maturity_interest_and_keeps_the_books_in_step(): void
    {
        $deposit = $this->placed(['maturity_instruction' => 'rollover_all']);

        $new = $this->settleOn($deposit, '2027-02-22', ['action' => 'rollover_all'])->assertCreated()->json('data');

        $this->assertSame('100778.37', $new['principal']);
        $this->assertSame('2027-02-22', $new['placement_date']);
        $this->assertSame('3.0000', $new['annual_rate']);
        $this->assertSame(0, $new['post_maturity_days']);
        $this->assertSame('0.00', $new['post_maturity_interest']);

        $old = $deposit->refresh();
        $this->assertSame('778.37', $old->settled_interest);
        $this->assertSame('0.00', $old->settled_amount);

        $journal = $this->journalFor(TimeDeposit::class, $deposit->id, 'time_deposit_settlement');
        $this->assertSame([['2060', 10_000_000, 0], ['5160', 77_837, 0], ['2060', 0, 10_077_837]], $this->lines($journal));

        foreach (['2027-02-08', '2027-02-21', '2027-02-22'] as $day) {
            $this->assertSame($this->subledger2060($day), $this->gl2060($day), "GL 2060 differs from the time deposit subledger on {$day}.");
        }
    }

    public function test_settling_on_the_maturity_date_earns_no_post_maturity_interest(): void
    {
        $account = $this->savingsAccount();
        $deposit = $this->placed(['payout_account_id' => $account->id]);

        $data = $this->settleOn($deposit, '2027-02-08')->assertCreated()->json('data');

        $this->assertSame(0, $data['post_maturity_days']);
        $this->assertSame('0.00', $data['post_maturity_interest']);
        $this->assertSame('739.73', $data['settled_interest']);
        $this->assertSame('100739.73', $data['settled_amount']);
    }

    public function test_a_rate_change_during_the_gap_is_taken_day_by_day(): void
    {
        $this->rate($this->regular, null, '2.0000', '2027-02-15');
        $account = $this->savingsAccount();
        $deposit = $this->placed(['payout_account_id' => $account->id]);

        // 7 days at 1% and 7 at 2% on ₱100,739.73, rounded once: 5,796 centavos.
        $data = $this->settleOn($deposit, '2027-02-22')->assertCreated()->json('data');

        $this->assertSame('57.96', $data['post_maturity_interest']);
        $this->assertSame('797.69', $data['settled_interest']);
        $this->assertSame('100797.69', $data['settled_amount']);
    }

    public function test_a_late_cash_payout_waits_for_approval_at_the_amount_with_post_maturity_interest(): void
    {
        $deposit = $this->placed();

        $this->settleOn($deposit, '2027-02-22', ['payout_method' => 'cash'])
            ->assertStatus(202)
            ->assertJsonPath('data.approval.amount', '100778.37');
    }

    private function subledger2060(string $day): int
    {
        return (int) round(100 * (float) TimeDeposit::query()
            ->where('placement_date', '<=', $day)
            ->where(fn ($q) => $q->whereNull('settled_on')->orWhere('settled_on', '>', $day))
            ->sum('principal'));
    }

    private function gl2060(string $day): int
    {
        return (int) AccountingJournal::query()
            ->join('accounting_journal_lines AS l', 'l.accounting_journal_id', '=', 'accounting_journals.id')
            ->join('accounting_accounts AS a', 'a.id', '=', 'l.accounting_account_id')
            ->where('a.code', '2060')
            ->where('accounting_journals.status', 'posted')
            ->where('accounting_journals.date', '<=', $day)
            ->selectRaw('COALESCE(SUM(l.credit), 0) - COALESCE(SUM(l.debit), 0) AS balance')
            ->value('balance');
    }
}
