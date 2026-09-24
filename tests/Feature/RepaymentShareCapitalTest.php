<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use App\Services\RepaymentService;
use Illuminate\Database\QueryException;
use PDOException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * RepaymentService::processRepayment() now credits share capital build-up
 * (SCB) in the SAME transaction as the payment, instead of relying on a
 * second, independent /api/share-capital/ledger call the frontend used to
 * make after the fact. voidRepayment() mirrors this on the way out, reversing
 * the credit unless doing so would take the member's balance negative.
 */
class RepaymentShareCapitalTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    protected function tearDown(): void
    {
        // Undoes test_a_forced_ledger_failure_rolls_back_the_whole_payment()'s
        // registerModelEvent() below, so it cannot leak into a later test in
        // this process.
        ShareCapitalLedger::flushEventListeners();

        parent::tearDown();
    }

    public function test_an_scb_eligible_repayment_credits_share_capital_atomically(): void
    {
        $loan = $this->createReleasedLoan();
        $loan->update(['scb_amount' => 500]);

        $firstSchedule = $loan->amortizationSchedules->first();
        $excess = 750.0;

        $response = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => (float) $firstSchedule->total_due + $excess,
            'method' => 'cash',
        ]);

        $response->assertCreated();
        $repaymentId = $response->json('data.id');

        $this->assertEquals($excess, $response->json('data.overpayment'));
        $this->assertEquals($excess, $response->json('data.scb_paid'));

        $this->assertDatabaseCount('share_capital_ledger', 1);
        $this->assertDatabaseHas('share_capital_ledger', [
            'repayment_id' => $repaymentId,
            'borrower_id' => $loan->borrower_id,
            'debit' => 0,
            'credit' => $excess,
        ]);
    }

    /**
     * The regression test for the `$hasScb` guard: a full payoff plus excess
     * cash on a loan carrying NO scb_amount is ordinary overpayment, not share
     * capital, and must credit nothing.
     */
    public function test_overpayment_on_a_non_scb_loan_never_touches_share_capital(): void
    {
        $loan = $this->createReleasedLoan();
        $this->assertEquals(0.0, (float) $loan->scb_amount);

        $totalDue = (float) $loan->amortizationSchedules->sum('total_due');
        $excess = 300.0;

        $response = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => $totalDue + $excess,
            'method' => 'cash',
        ]);

        $response->assertCreated();
        $this->assertEquals($excess, $response->json('data.overpayment'));
        $this->assertEquals(0, $response->json('data.scb_paid'));
        $this->assertDatabaseCount('share_capital_ledger', 0);
    }

    /**
     * The ledger row and the repayment row live or die together.
     *
     * Forces the DB-layer failure via a model event throwing a fabricated
     * QueryException, the same technique CsvImportUploadApiTest and
     * CsvImportRowRetentionTest use to simulate a driver-level refusal without
     * a real constraint violation. `tearDown()` flushes this listener so it
     * cannot reach any other test in this process.
     */
    public function test_a_forced_ledger_failure_rolls_back_the_whole_payment(): void
    {
        $loan = $this->createReleasedLoan();
        $loan->update(['scb_amount' => 500]);
        $firstSchedule = $loan->amortizationSchedules->first();

        $driver = new PDOException("SQLSTATE[HY000]: General error: 1364 Field 'reference' doesn't have a default value");
        $driver->errorInfo = ['HY000', 1364, "Field 'reference' doesn't have a default value"];

        $failure = new QueryException(
            'mysql',
            'insert into `share_capital_ledger` (`borrower_id`, `repayment_id`, `date`, `description`, `debit`, `credit`, `created_by`) values (?, ?, ?, ?, ?, ?, ?)',
            [],
            $driver,
        );

        ShareCapitalLedger::creating(function () use ($failure): void {
            throw $failure;
        });

        $this->expectException(QueryException::class);

        try {
            app(RepaymentService::class)->processRepayment(
                $loan,
                (float) $firstSchedule->total_due + 750.0,
                now()->toDateString(),
                $this->admin,
            );
        } finally {
            // The ledger failure must roll back the repayment too, not just the ledger row.
            $this->assertDatabaseCount('repayments', 0);
            $this->assertDatabaseCount('share_capital_ledger', 0);
            $this->assertDatabaseHas('amortization_schedules', [
                'id' => $firstSchedule->id,
                'status' => 'pending',
                'principal_paid' => 0,
            ]);
        }
    }

    public function test_collector_can_record_a_fully_credited_scb_payment_without_the_share_capital_permission(): void
    {
        $loan = $this->createReleasedLoan();
        $loan->update(['scb_amount' => 500]);

        $collector = User::factory()->create();
        $collector->assignRole(Role::where('name', 'collector')->firstOrFail());

        $this->assertFalse(
            $collector->can('share_capital:create'),
            'This only proves something if collector truly lacks the permission the old standalone endpoint required.',
        );

        $this->actingAs($collector);

        $firstSchedule = $loan->amortizationSchedules->first();
        $excess = 400.0;

        $response = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => (float) $firstSchedule->total_due + $excess,
            'method' => 'cash',
        ]);

        $response->assertCreated();
        $this->assertEquals($excess, $response->json('data.scb_paid'));
        $this->assertDatabaseHas('share_capital_ledger', [
            'borrower_id' => $loan->borrower_id,
            'credit' => $excess,
        ]);
    }

    /**
     * The more consequential of the two role checks: cashier is the DEFAULT
     * payment-recording role, so this is the regression that matters most —
     * before this fix, every cashier-recorded SCB payment silently failed to
     * credit the member the moment the frontend's second call 403'd.
     */
    public function test_cashier_can_record_a_fully_credited_scb_payment_without_the_share_capital_permission(): void
    {
        $loan = $this->createReleasedLoan();
        $loan->update(['scb_amount' => 500]);

        $cashier = User::factory()->create();
        $cashier->assignRole(Role::where('name', 'cashier')->firstOrFail());

        $this->assertFalse(
            $cashier->can('share_capital:create'),
            'This only proves something if cashier truly lacks the permission the old standalone endpoint required.',
        );

        $this->actingAs($cashier);

        $firstSchedule = $loan->amortizationSchedules->first();
        $excess = 400.0;

        $response = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => (float) $firstSchedule->total_due + $excess,
            'method' => 'cash',
        ]);

        $response->assertCreated();
        $this->assertEquals($excess, $response->json('data.scb_paid'));
        $this->assertDatabaseHas('share_capital_ledger', [
            'borrower_id' => $loan->borrower_id,
            'credit' => $excess,
        ]);
    }

    /**
     * AutoPayService::process() is a second, independent caller of
     * processRepayment() — a batch loop over every auto-pay loan, not a single
     * HTTP request — and deserves its own proof that SCB crediting reaches it
     * too.
     *
     * The window spans two schedules' due dates so the amount handed to
     * processRepayment() covers period 1 fully plus the whole of period 2.
     * On an SCB-bearing loan, allocation stops cascading once the current
     * schedule (period 1) is settled — see RepaymentService::processRepayment
     * — so period 2's untouched due amount surfaces as overpayment, and here,
     * as a share capital credit.
     */
    public function test_autopay_batch_run_credits_share_capital_for_an_scb_eligible_loan(): void
    {
        $loan = $this->createReleasedLoan();
        $loan->update([
            'scb_amount' => 500,
            'auto_pay' => true,
            'cbs_reference' => 'CBS-2026-SCB01',
            'auto_pay_enabled_at' => now(),
            'auto_pay_enabled_by' => $this->admin->id,
        ]);

        $schedules = $loan->amortizationSchedules()->orderBy('period_number')->get();
        [$first, $second] = [$schedules[0], $schedules[1]];

        $response = $this->postJson('/api/auto-pay/process', [
            'date_from' => $first->due_date->toDateString(),
            'date_to' => $second->due_date->toDateString(),
        ]);

        $response->assertCreated();
        $this->assertSame(1, $response->json('data.processed'));
        $this->assertSame(0, $response->json('data.failed'));

        $repaymentId = $response->json('data.repayments.0.repayment_id');
        $expectedScb = round((float) $second->total_due, 2);

        $this->assertDatabaseHas('share_capital_ledger', [
            'repayment_id' => $repaymentId,
            'borrower_id' => $loan->borrower_id,
            'debit' => 0,
            'credit' => $expectedScb,
        ]);
    }

    public function test_void_reverses_the_share_capital_credit_when_the_balance_allows_it(): void
    {
        $loan = $this->createReleasedLoan();
        $loan->update(['scb_amount' => 500]);
        $firstSchedule = $loan->amortizationSchedules->first();
        $excess = 750.0;

        $repaymentId = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => (float) $firstSchedule->total_due + $excess,
            'method' => 'cash',
        ])->assertCreated()->json('data.id');

        $voidResponse = $this->patchJson("/api/repayments/{$repaymentId}/void", [
            'void_reason' => 'Testing SCB reversal',
        ])->assertOk()->assertJsonPath('data.status', 'voided');

        // NET of the reversal, not the original gross credit — a plain
        // sum('credit') would still report $excess here, because the
        // reversal debit shares this repayment's repayment_id.
        $this->assertEquals(0, $voidResponse->json('data.scb_paid'));

        $this->assertDatabaseHas('share_capital_ledger', [
            'repayment_id' => $repaymentId,
            'debit' => $excess,
            'credit' => 0,
        ]);

        // The credit and its reversal must net to zero — the same
        // SUM(credit) - SUM(debit) the Share Capital report uses.
        $balance = (float) ShareCapitalLedger::where('borrower_id', $loan->borrower_id)
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) as balance')
            ->value('balance');

        $this->assertEquals(0.0, $balance);
    }

    public function test_void_is_blocked_when_reversing_the_credit_would_go_negative(): void
    {
        $loan = $this->createReleasedLoan();
        $loan->update(['scb_amount' => 500]);
        $firstSchedule = $loan->amortizationSchedules->first();
        $excess = 750.0;

        $repaymentId = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => (float) $firstSchedule->total_due + $excess,
            'method' => 'cash',
        ])->assertCreated()->json('data.id');

        // The member drew part of that credit down before anyone tried to
        // void the payment that produced it.
        ShareCapitalLedger::create([
            'borrower_id' => $loan->borrower_id,
            'date' => now()->toDateString(),
            'description' => 'Withdrawal',
            'debit' => 400,
            'credit' => 0,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->patchJson("/api/repayments/{$repaymentId}/void", [
            'void_reason' => 'Testing blocked SCB reversal',
        ]);

        $response->assertUnprocessable();
        $this->assertArrayHasKey('share_capital', $response->json('errors'));

        // Blocked means blocked: the repayment is still posted and no
        // reversal row was written alongside the original credit.
        $this->assertDatabaseHas('repayments', ['id' => $repaymentId, 'status' => 'posted']);
        $this->assertSame(1, ShareCapitalLedger::where('repayment_id', $repaymentId)->count());
    }

    public function test_void_is_a_no_op_for_share_capital_when_the_repayment_never_credited_it(): void
    {
        $loan = $this->createReleasedLoan();
        $this->assertEquals(0.0, (float) $loan->scb_amount);
        $totalDue = $loan->amortizationSchedules->sum('total_due');

        $repaymentId = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => $totalDue,
            'method' => 'cash',
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/repayments/{$repaymentId}/void", [
            'void_reason' => 'No SCB involved',
        ])->assertOk()->assertJsonPath('data.status', 'voided');

        $this->assertDatabaseCount('share_capital_ledger', 0);
    }
}
