<?php

namespace Tests\Feature\Savings\TimeDeposits;

use App\Models\AccountingJournal;
use App\Models\AuditLog;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\TimeDeposit;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Savings\ApprovalGate;
use App\Services\Savings\SavingsLedger;
use App\Services\Savings\SavingsLocks;
use App\Services\Savings\TimeDeposits\Placement;
use App\Services\Savings\TimeDeposits\Quote;
use App\Services\Savings\TimeDeposits\Settlement;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PDOException;
use Tests\TestCase;

/**
 * The S3 review's findings (L2–L8 and the GL dating rule), each shown first
 * by a failing test.
 */
class TimeDepositReviewFixesTest extends TestCase
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

    private function settle(TimeDeposit $deposit, array $body = [])
    {
        return $this->idem("/api/savings/time-deposits/{$deposit->id}/settle", ['date' => now()->toDateString(), 'action' => 'payout', 'payout_method' => 'cash', ...$body]);
    }

    // ── M1: a pending payout blocks another until it is decided or expired by the 00:30 job ──

    public function test_a_payout_past_its_expiry_but_still_pending_still_blocks_a_second_request(): void
    {
        $deposit = $this->placed();
        $this->travelTo(Carbon::parse('2027-02-08 09:00:00', 'Asia/Manila'));
        $this->settle($deposit)->assertStatus(202);

        $this->travelTo(Carbon::parse('2027-02-09 00:10:00', 'Asia/Manila'));

        $this->settle($deposit, ['date' => '2027-02-09'])->assertConflict();
    }

    // ── L2: only a running placement can be broken early ──

    public function test_a_matured_placement_cant_be_preterminated_or_quoted_for_it(): void
    {
        $deposit = $this->placed(['principal' => '9000.00']);
        $deposit->forceFill(['status' => 'matured'])->saveQuietly();
        $this->travelTo(Carbon::parse('2026-12-10 09:00:00', 'Asia/Manila'));
        $message = "{$deposit->certificate_number} has matured, so it can't be pre-terminated. Settle it instead.";

        $this->getJson("/api/savings/time-deposits/{$deposit->id}/pretermination-quote?date=2026-12-10")
            ->assertUnprocessable()->assertJsonFragment(['time_deposit' => [$message]]);

        $this->idem("/api/savings/time-deposits/{$deposit->id}/preterminate", ['date' => '2026-12-10', 'reason' => 'Probe', 'payout_method' => 'cash'])
            ->assertUnprocessable()->assertJsonFragment(['time_deposit' => [$message]]);

        $this->assertSame('matured', $deposit->refresh()->status);
    }

    // ── L3: a named pre-termination product with no rate is refused, never a silent 0% ──

    public function test_a_pretermination_product_with_no_rate_in_force_is_refused(): void
    {
        $later = SavingsProduct::factory()->create(['name' => 'Passbook Savings']);
        $this->rate($later, null, '1.0000', '2026-12-01');
        $this->product->forceFill(['pretermination_product_id' => $later->id])->save();
        $message = "{$this->product->name} pays early terminations at Passbook Savings' rate, which has none in force on Nov 10, 2026. Add one in Savings → Products.";

        $this->postJson('/api/savings/time-deposits/quote', collect($this->placementBody())->only(['product_id', 'principal', 'term_days', 'placement_date'])->all())
            ->assertUnprocessable()->assertJsonFragment(['product_id' => [$message]]);
        $this->place()->assertUnprocessable()->assertJsonFragment(['product_id' => [$message]]);

        $this->assertSame(0, TimeDeposit::query()->count());
    }

    // ── L4: a principal too large to compute is a 422 in words ──

    public function test_a_principal_too_large_to_work_out_is_422_in_words(): void
    {
        $body = collect($this->placementBody(['principal' => '9999999999.99', 'term_days' => 360]))
            ->only(['product_id', 'principal', 'term_days', 'placement_date'])->all();

        $this->postJson('/api/savings/time-deposits/quote', $body)
            ->assertUnprocessable()
            ->assertJsonFragment(['principal' => ['That principal is too large to work out its interest. Enter a smaller amount.']]);
    }

    // ── L5: a deadlock on one placement doesn't stop the maturity run ──

    public function test_the_maturity_job_logs_a_deadlock_and_carries_on(): void
    {
        $stuck = $this->placed(['maturity_instruction' => 'rollover_all']);
        $fine = $this->placed(['maturity_instruction' => 'rollover_all']);
        DeadlockingSettlement::$failOn = $stuck->id;
        $this->app->bind(Settlement::class, fn ($app) => new DeadlockingSettlement(
            $app->make(Placement::class), $app->make(Quote::class), $app->make(SavingsLedger::class),
            $app->make(SavingsLocks::class), $app->make(ApprovalGate::class), $app->make(AutomaticPoster::class),
        ));
        Log::spy();

        $this->travelTo(Carbon::parse('2027-02-08 00:40:00', 'Asia/Manila'));
        $this->artisan('savings:mature-time-deposits')->assertSuccessful();

        $this->assertSame('matured', $stuck->refresh()->status);
        $this->assertSame('rolled_over', $fine->refresh()->status);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $context['time_deposit_id'] === $stuck->id)->once();
    }

    // ── L6: staff choose the next instruction on a rollover ──

    public function test_a_rollover_takes_the_next_instruction_given_at_settlement(): void
    {
        $deposit = $this->placed(['maturity_instruction' => 'rollover_all']);
        $this->travelTo(Carbon::parse('2027-02-08 09:00:00', 'Asia/Manila'));

        $this->settle($deposit, ['action' => 'rollover_all', 'next_instruction' => 'bogus'])
            ->assertUnprocessable()
            ->assertJsonFragment(['next_instruction' => ['After the rollover, a placement is paid out, has its principal rolled over, or has principal and interest rolled over.']]);

        $this->settle($deposit, ['action' => 'rollover_all', 'next_instruction' => 'payout'])
            ->assertCreated()
            ->assertJsonPath('data.maturity_instruction', 'payout')
            ->assertJsonPath('data.rolled_over_from_id', $deposit->id);
    }

    public function test_a_rollover_keeps_the_original_instruction_by_default(): void
    {
        $deposit = $this->placed(['maturity_instruction' => 'rollover_principal']);
        $this->travelTo(Carbon::parse('2027-02-08 09:00:00', 'Asia/Manila'));

        $this->settle($deposit, ['action' => 'rollover_principal'])
            ->assertCreated()
            ->assertJsonPath('data.maturity_instruction', 'rollover_principal');
    }

    // ── L7: maturing and settling are audited, by staff or the system ──

    public function test_maturing_and_each_settlement_write_audit_rows(): void
    {
        $byJob = $this->placed(['maturity_instruction' => 'rollover_all']);
        $byStaff = $this->placed(['principal' => '9000.00']);

        $this->travelTo(Carbon::parse('2027-02-08 00:40:00', 'Asia/Manila'));
        $this->artisan('savings:mature-time-deposits')->assertSuccessful();

        $this->travelTo(Carbon::parse('2027-02-08 09:00:00', 'Asia/Manila'));
        $this->settle($byStaff)->assertCreated();

        foreach ([$byJob, $byStaff] as $deposit) {
            $matured = AuditLog::query()->where('action', 'time_deposit_matured')->where('auditable_id', $deposit->id)->sole();
            $this->assertSame(['status' => 'active'], $matured->old_values);
            $this->assertSame(['status' => 'matured'], $matured->new_values);
        }

        $system = AuditLog::query()->where('action', 'time_deposit_settled')->where('auditable_id', $byJob->id)->sole();
        $this->assertSame('rolled_over', $system->new_values['status']);
        $this->assertStringContainsString('by the system', $system->description);

        $staff = AuditLog::query()->where('action', 'time_deposit_settled')->where('auditable_id', $byStaff->id)->sole();
        $this->assertSame($this->admin->id, $staff->user_id);
        $this->assertSame('withdrawn', $staff->new_values['status']);
        $this->assertSame('9066.58', $staff->new_values['settled_amount']);
    }

    // ── 10: journals are dated as the GL control reads the subledger ──

    public function test_the_placement_journal_is_dated_the_placement_date_and_the_settlement_journal_the_settlement_date(): void
    {
        $this->rate($this->regular, null, '1.0000', '2026-11-09');
        $this->rate($this->product, 90, '3.0000', '2026-11-09');
        $yesterday = $this->placed(['placement_date' => '2026-11-09']);
        $this->assertSame('2026-11-09', $this->journalFor(TimeDeposit::class, $yesterday->id, 'time_deposit')->date->toDateString());

        $late = $this->placed(['principal' => '9000.00']);
        $this->travelTo(Carbon::parse('2027-02-10 09:00:00', 'Asia/Manila'));
        $this->settle($late, ['date' => '2027-02-09'])->assertCreated();

        $this->assertSame('2027-02-09', $late->refresh()->settled_on->toDateString());
        $this->assertSame('2027-02-09', $this->journalFor(TimeDeposit::class, $late->id, 'time_deposit_settlement')->date->toDateString());
    }

    public function test_a_late_rollover_starts_on_the_settlement_date_at_the_rate_in_force_on_maturity(): void
    {
        $deposit = $this->placed(['maturity_instruction' => 'rollover_all']);
        $this->rate($this->product, 90, '4.5000', '2027-02-09');
        $this->travelTo(Carbon::parse('2027-02-09 09:00:00', 'Asia/Manila'));

        $new = $this->settle($deposit, ['action' => 'rollover_all', 'date' => '2027-02-09'])->assertCreated()->json('data');

        $this->assertSame('2027-02-09', $new['placement_date']);
        $this->assertSame('2027-05-10', $new['maturity_date']);
        $this->assertSame('3.0000', $new['annual_rate']);
        $this->assertSame(
            $this->journalFor(TimeDeposit::class, $deposit->id, 'time_deposit_settlement')->date->toDateString(),
            $new['placement_date'],
        );

        // GL 2060 on each day equals the principal the subledger holds that day:
        // placed on or before it, and not yet settled on or before it.
        foreach (['2027-02-08', '2027-02-09'] as $day) {
            $subledger = (int) round(100 * (float) TimeDeposit::query()
                ->where('placement_date', '<=', $day)
                ->where(fn ($q) => $q->whereNull('settled_on')->orWhere('settled_on', '>', $day))
                ->sum('principal'));
            $gl = (int) AccountingJournal::query()
                ->join('accounting_journal_lines AS l', 'l.accounting_journal_id', '=', 'accounting_journals.id')
                ->join('accounting_accounts AS a', 'a.id', '=', 'l.accounting_account_id')
                ->where('a.code', '2060')
                ->where('accounting_journals.status', 'posted')
                ->where('accounting_journals.date', '<=', $day)
                ->selectRaw('COALESCE(SUM(l.credit), 0) - COALESCE(SUM(l.debit), 0) AS balance')
                ->value('balance');

            $this->assertSame($subledger, $gl, "GL 2060 differs from the time deposit subledger on {$day}.");
        }
    }
}

/** A Settlement that hits a deadlock on one placement, for the maturity job test. */
class DeadlockingSettlement extends Settlement
{
    public static ?int $failOn = null;

    public function settle(TimeDeposit $locked, string $date, string $action, ?string $payoutMethod, ?int $userId, ?string $nextInstruction = null, ?string $postMaturityTaxRate = null, ?SavingsAccount $payoutAccount = null): array
    {
        if ($locked->id === self::$failOn) {
            throw new QueryException('mysql', 'update `time_deposits` set `status` = ?', [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
        }

        return parent::settle($locked, $date, $action, $payoutMethod, $userId, $nextInstruction, $postMaturityTaxRate, $payoutAccount);
    }
}
