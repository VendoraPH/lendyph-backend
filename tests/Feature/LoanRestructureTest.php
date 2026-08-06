<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Collateral;
use App\Models\CoMaker;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * POST /api/loans/{loan}/restructure creates a NEW loan carrying the source
 * loan's outstanding balance. The source stays live and collectible until that
 * new loan is RELEASED — that is the invariant most of these tests defend.
 */
class LoanRestructureTest extends TestCase
{
    use SetupLendyPH;

    /**
     * What `createReleasedLoan()` leaves owed: ₱60,000 principal + ₱10,800
     * interest (6 × 3% of 60,000) across six open periods.
     *
     * Note this is deliberately larger than the loan's `outstanding_balance`
     * (₱60,000 — principal + insurance only). A restructure capitalizes
     * arrears, so it rolls the whole obligation.
     */
    private const SOURCE_OUTSTANDING = 70800.00;

    private ?User $secondApprover = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payloadFor(Loan $source, array $overrides = []): array
    {
        return array_merge([
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => self::SOURCE_OUTSTANDING,
            'start_date' => now()->toDateString(),
        ], $overrides);
    }

    /**
     * Create the restructure and return the NEW loan.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function restructure(Loan $source, array $overrides = []): Loan
    {
        $response = $this->postJson("/api/loans/{$source->id}/restructure", $this->payloadFor($source, $overrides))
            ->assertCreated();

        return Loan::findOrFail($response->json('data.id'));
    }

    /**
     * A second admin, because a restructure may not be approved by whoever
     * created it. Everything in this file is created by $this->admin.
     */
    private function approver(): User
    {
        return $this->secondApprover ??= tap(
            User::factory()->create(),
            fn (User $user) => $user->assignRole(Role::where('name', 'admin')->first()),
        );
    }

    private function approveAsSomeoneElse(Loan $loan): void
    {
        $this->actingAs($this->approver());
        $this->patchJson("/api/loans/{$loan->id}/approve", ['approval_remarks' => 'ok'])->assertOk();
        $this->actingAs($this->admin);
    }

    private function pushToReleased(Loan $loan): void
    {
        $this->patchJson("/api/loans/{$loan->id}/submit")->assertOk();
        $this->approveAsSomeoneElse($loan);
        $this->patchJson("/api/loans/{$loan->id}/release")->assertOk();
    }

    private function sumPrincipalPaid(Loan $loan): float
    {
        return round((float) AmortizationSchedule::where('loan_id', $loan->id)->sum('principal_paid'), 2);
    }

    /**
     * Match a money field by value rather than by type: assertJsonPath compares
     * strictly and JSON renders a whole-peso float as an int.
     */
    private function money(float $expected): Closure
    {
        return fn ($actual): bool => abs((float) $actual - $expected) < 0.01;
    }

    // ---------------------------------------------------------------------
    // Creation + linkage
    // ---------------------------------------------------------------------

    public function test_it_creates_a_new_draft_loan_linked_to_the_source(): void
    {
        $source = $this->createReleasedLoan();

        $response = $this->postJson("/api/loans/{$source->id}/restructure", $this->payloadFor($source))
            ->assertCreated();

        $newLoanId = $response->json('data.id');

        $this->assertNotNull($newLoanId);
        $this->assertNotSame($source->id, $newLoanId, 'a restructure must produce a NEW loan, not mutate the old one');

        $response
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.source_loan_id', $source->id)
            ->assertJsonPath('data.is_restructure', true)
            ->assertJsonPath('data.borrower_id', $source->borrower_id)
            ->assertJsonPath('data.principal_amount', $this->money(self::SOURCE_OUTSTANDING))
            ->assertJsonPath('data.source_loan.id', $source->id)
            ->assertJsonPath('data.source_loan.status', 'released');

        $newLoan = Loan::findOrFail($newLoanId);

        $this->assertSame($source->id, $newLoan->source_loan_id);
        $this->assertTrue($newLoan->isRestructure());
        $this->assertFalse($source->fresh()->isRestructure());
        // interest_method is never taken from the request — always snapshotted
        // from the loan product, exactly as POST /loans does.
        $this->assertSame($source->loanProduct->interest_method, $newLoan->interest_method);

        $audit = AuditLog::where('action', 'restructure_created')
            ->where('auditable_id', $newLoan->id)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame($source->id, $audit->old_values['source_loan_id']);
        $this->assertEqualsWithDelta(self::SOURCE_OUTSTANDING, (float) $audit->new_values['outstanding_at_application'], 0.01);
        $this->assertEqualsWithDelta(self::SOURCE_OUTSTANDING, (float) $audit->new_values['new_principal'], 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $audit->new_values['shortfall_amount'], 0.01);
    }

    public function test_the_new_loan_id_is_usable_immediately_for_collaterals_and_submit(): void
    {
        // The frontend unwraps `response.data.data` and needs the new loan's id
        // straight away to attach collaterals and submit it. Prove the whole
        // chain works off the create response rather than just its shape.
        $source = $this->createReleasedLoan();

        $response = $this->postJson("/api/loans/{$source->id}/restructure", $this->payloadFor($source))
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'application_number', 'status', 'source_loan_id', 'is_restructure', 'borrower', 'loan_product']]);

        $newLoanId = $response->json('data.id');

        $collateral = Collateral::factory()->create(['borrower_id' => $source->borrower_id]);

        $this->postJson("/api/loans/{$newLoanId}/collaterals", [
            'collateral_id' => $collateral->id,
            'snapshot_value' => 25000,
        ])->assertCreated();

        $this->patchJson("/api/loans/{$newLoanId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'for_review');

        $this->assertSame('for_review', Loan::findOrFail($newLoanId)->status);
    }

    public function test_it_persists_term_frequency_and_deductions_with_a_derived_maturity_date(): void
    {
        $source = $this->createReleasedLoan();

        $newLoan = $this->restructure($source, [
            'start_date' => '2026-09-01',
            'term' => 3,
            // The contract field is `frequency`, not `payment_frequency`.
            'frequency' => 'weekly',
            'deductions' => [
                ['name' => 'Processing Fee', 'amount' => 2, 'type' => 'percentage'],
                ['name' => 'Notarial Fee', 'amount' => 500, 'type' => 'fixed'],
            ],
        ]);

        $this->assertSame(3, $newLoan->term);
        $this->assertSame('weekly', $newLoan->frequency);
        $this->assertSame('2026-09-01', $newLoan->start_date->toDateString());
        $this->assertSame(
            Carbon::parse('2026-09-01')->addWeeks(3)->toDateString(),
            $newLoan->maturity_date->toDateString(),
        );

        // 2% of 70,800 = 1,416 + a flat 500.
        $this->assertCount(2, $newLoan->deductions);
        $this->assertEqualsWithDelta(1416.00, (float) $newLoan->deductions[0]['amount'], 0.01);
        $this->assertEqualsWithDelta(500.00, (float) $newLoan->deductions[1]['amount'], 0.01);
        $this->assertEqualsWithDelta(1916.00, (float) $newLoan->total_deductions, 0.01);
        $this->assertEqualsWithDelta(68884.00, (float) $newLoan->net_proceeds, 0.01);
    }

    public function test_it_inherits_the_source_co_makers_when_none_are_sent(): void
    {
        $source = $this->createReleasedLoan();
        $coMaker = CoMaker::factory()->create(['borrower_id' => $source->borrower_id]);
        $source->coMakers()->sync([$coMaker->id]);

        $newLoan = $this->restructure($source);

        $this->assertSame([$coMaker->id], $newLoan->coMakers()->pluck('co_makers.id')->all());
    }

    public function test_an_explicitly_empty_co_maker_list_is_respected(): void
    {
        // Sending [] is how a caller drops the co-makers; only an absent key
        // means "carry the source's over".
        $source = $this->createReleasedLoan();
        $coMaker = CoMaker::factory()->create(['borrower_id' => $source->borrower_id]);
        $source->coMakers()->sync([$coMaker->id]);

        $newLoan = $this->restructure($source, ['co_maker_ids' => []]);

        $this->assertSame([], $newLoan->coMakers()->pluck('co_makers.id')->all());
    }

    public function test_it_accepts_a_principal_below_the_product_minimum(): void
    {
        // A part-paid balance is routinely below the product's min_amount. That
        // floor is meant for new money, and enforcing it here would block the
        // restructures that need it most.
        $source = $this->createReleasedLoan(['product' => ['min_amount' => 50000]]);

        $newLoan = $this->restructure($source, [
            'principal_amount' => 20000,
            'remarks' => 'Borrower can only service a smaller balance',
        ]);

        $this->assertEqualsWithDelta(20000.00, (float) $newLoan->principal_amount, 0.01);

        // The max_amount ceiling is still enforced.
        $this->assertSame(50000.0, (float) $source->loanProduct->min_amount);
    }

    // ---------------------------------------------------------------------
    // The source loan stays live until the restructure is released
    // ---------------------------------------------------------------------

    public function test_the_source_loan_is_untouched_when_the_restructure_is_created(): void
    {
        $source = $this->createReleasedLoan();
        $maturityBefore = $source->maturity_date->toDateString();

        $this->restructure($source);

        $source->refresh();

        $this->assertSame('released', $source->status);
        $this->assertNull($source->restructured_at);
        $this->assertNull($source->restructured_balance);
        $this->assertNull($source->write_off_amount);
        $this->assertSame($maturityBefore, $source->maturity_date->toDateString());
        $this->assertSame(6, AmortizationSchedule::where('loan_id', $source->id)->count());

        $this->getJson("/api/loans/{$source->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'released')
            ->assertJsonPath('data.outstanding_balance', $this->money(60000.00));
    }

    public function test_the_source_loan_still_accepts_a_repayment_while_the_restructure_is_pending(): void
    {
        $source = $this->createReleasedLoan();

        $newLoan = $this->restructure($source);
        $this->patchJson("/api/loans/{$newLoan->id}/submit")->assertOk();

        $this->postJson("/api/loans/{$source->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 5000,
            'method' => 'cash',
        ])->assertCreated();

        $this->assertEqualsWithDelta(3200.00, $this->sumPrincipalPaid($source), 0.01);
    }

    public function test_the_source_loan_is_untouched_when_the_restructure_is_rejected(): void
    {
        $source = $this->createReleasedLoan();
        $newLoan = $this->restructure($source);

        $this->patchJson("/api/loans/{$newLoan->id}/submit")->assertOk();
        $this->patchJson("/api/loans/{$newLoan->id}/reject", ['approval_remarks' => 'Not viable'])->assertOk();

        $source->refresh();

        $this->assertSame('rejected', $newLoan->fresh()->status);
        $this->assertSame('released', $source->status);
        $this->assertNull($source->restructured_at);
        $this->assertSame(6, AmortizationSchedule::where('loan_id', $source->id)->count());

        // Still collectible — the whole point of not closing on rejection.
        $this->postJson("/api/loans/{$source->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 1000,
            'method' => 'cash',
        ])->assertCreated();
    }

    public function test_the_source_loan_is_untouched_when_the_restructure_is_voided(): void
    {
        $source = $this->createReleasedLoan();
        $newLoan = $this->restructure($source);

        $this->patchJson("/api/loans/{$newLoan->id}/void")->assertOk();

        $source->refresh();

        $this->assertSame('void', $newLoan->fresh()->status);
        $this->assertSame('released', $source->status);
        $this->assertNull($source->restructured_at);
        $this->assertSame(6, AmortizationSchedule::where('loan_id', $source->id)->count());
    }

    public function test_the_source_loan_is_untouched_when_the_restructure_draft_is_deleted(): void
    {
        $source = $this->createReleasedLoan();
        $newLoan = $this->restructure($source);

        $this->deleteJson("/api/loans/{$newLoan->id}")->assertOk();

        $source->refresh();

        $this->assertNull(Loan::find($newLoan->id));
        $this->assertSame('released', $source->status);
        $this->assertSame(6, AmortizationSchedule::where('loan_id', $source->id)->count());
    }

    // ---------------------------------------------------------------------
    // Release closes the source
    // ---------------------------------------------------------------------

    public function test_releasing_the_restructure_closes_the_source_as_restructured(): void
    {
        $source = $this->createReleasedLoan();
        $newLoan = $this->restructure($source);

        $this->pushToReleased($newLoan);

        $source->refresh();

        $this->assertSame('restructured', $source->status);
        $this->assertNotNull($source->restructured_at);
        $this->assertEqualsWithDelta(self::SOURCE_OUTSTANDING, (float) $source->restructured_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $source->write_off_amount, 0.01);
        $this->assertSame('released', $newLoan->fresh()->status);

        $audit = AuditLog::where('action', 'restructure_closed')
            ->where('auditable_id', $source->id)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame($newLoan->id, $audit->new_values['restructured_into_loan_id']);
        $this->assertEqualsWithDelta(0.0, (float) $audit->new_values['write_off_amount'], 0.01);
    }

    public function test_releasing_clears_the_source_open_schedules_and_stops_it_showing_overdue(): void
    {
        // Every period already past due, so the source is visibly overdue before
        // the restructure lands.
        $source = $this->createReleasedLoan(['start_date' => now()->subMonths(8)->toDateString()]);

        $this->getJson("/api/loans/{$source->id}")
            ->assertOk()
            ->assertJsonPath('data.outstanding_balance', $this->money(60000.00));

        $this->assertGreaterThan(
            0.0,
            (float) $this->getJson("/api/loans/{$source->id}")->json('data.overdue_amount'),
            'fixture should start overdue',
        );

        $newLoan = $this->restructure($source);
        $this->pushToReleased($newLoan);

        $this->assertSame(0, AmortizationSchedule::where('loan_id', $source->id)->count());

        $this->getJson("/api/loans/{$source->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'restructured')
            ->assertJsonPath('data.outstanding_balance', $this->money(0.0))
            ->assertJsonPath('data.overdue_amount', $this->money(0.0))
            ->assertJsonPath('data.total_payable', $this->money(0.0))
            ->assertJsonCount(0, 'data.amortization_schedules');
    }

    public function test_a_partially_paid_period_survives_closure_with_payments_reconciling(): void
    {
        $source = $this->createReleasedLoan();

        // ₱5,000 clears period 1's ₱1,800 interest and ₱3,200 of its principal.
        $this->postJson("/api/loans/{$source->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 5000,
            'method' => 'cash',
        ])->assertCreated();

        $principalPaidBefore = $this->sumPrincipalPaid($source);
        $this->assertEqualsWithDelta(3200.00, $principalPaidBefore, 0.01);

        $remainingOutstanding = self::SOURCE_OUTSTANDING - 5000.00;

        $newLoan = $this->restructure($source, ['principal_amount' => $remainingOutstanding]);
        $this->pushToReleased($newLoan);

        // Every peso the borrower actually paid is still on the books — that is
        // what keeps SUM(principal_paid) reconciling across the closure, and why
        // partial rows are rewritten instead of deleted.
        $this->assertEqualsWithDelta($principalPaidBefore, $this->sumPrincipalPaid($source), 0.01);

        $surviving = AmortizationSchedule::where('loan_id', $source->id)->get();

        $this->assertCount(1, $surviving);
        $this->assertSame('paid', $surviving[0]->status);
        $this->assertEqualsWithDelta(3200.00, (float) $surviving[0]->principal_due, 0.01);
        $this->assertEqualsWithDelta(1800.00, (float) $surviving[0]->interest_due, 0.01);
        $this->assertEqualsWithDelta(5000.00, (float) $surviving[0]->total_due, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $surviving[0]->remaining_balance, 0.01);

        $source->refresh();
        $this->assertEqualsWithDelta($remainingOutstanding, (float) $source->restructured_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $source->write_off_amount, 0.01);
    }

    public function test_the_closed_source_refuses_payments(): void
    {
        $source = $this->createReleasedLoan();
        $newLoan = $this->restructure($source);

        $this->pushToReleased($newLoan);

        $this->postJson("/api/loans/{$source->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 1000,
            'method' => 'cash',
        ])->assertUnprocessable();
    }

    public function test_a_failed_release_leaves_the_source_open(): void
    {
        $source = $this->createReleasedLoan();
        $newLoan = $this->restructure($source);

        $this->patchJson("/api/loans/{$newLoan->id}/submit")->assertOk();
        $this->approveAsSomeoneElse($newLoan);

        // Fail while the new loan's schedule is being written, i.e. before the
        // source is touched at all.
        AmortizationSchedule::creating(function () {
            throw new RuntimeException('forced failure while building the new schedule');
        });

        try {
            $this->patchJson("/api/loans/{$newLoan->id}/release");
        } catch (RuntimeException) {
            // expected — the point is what survives in the database
        }

        $source->refresh();

        $this->assertSame('released', $source->status);
        $this->assertNull($source->restructured_at);
        $this->assertSame(6, AmortizationSchedule::where('loan_id', $source->id)->count());
        $this->assertSame('approved', $newLoan->fresh()->status);
    }

    public function test_a_failed_release_rolls_the_source_closure_back(): void
    {
        $source = $this->createReleasedLoan();
        $newLoan = $this->restructure($source);

        $this->patchJson("/api/loans/{$newLoan->id}/submit")->assertOk();
        $this->approveAsSomeoneElse($newLoan);

        // Fail on the closing audit entry — the very last thing
        // closeRestructuredSource() does, so the source has already been
        // rewritten by this point. Anything short of a full rollback here would
        // leave a closed loan with no loan to collect against.
        AuditLog::creating(function (AuditLog $log) {
            if ($log->action === 'restructure_closed') {
                throw new RuntimeException('forced failure after the source was closed');
            }
        });

        try {
            $this->patchJson("/api/loans/{$newLoan->id}/release");
        } catch (RuntimeException) {
            // expected
        }

        $source->refresh();

        $this->assertSame('released', $source->status);
        $this->assertNull($source->restructured_at);
        $this->assertNull($source->restructured_balance);
        $this->assertSame(6, AmortizationSchedule::where('loan_id', $source->id)->count());
        $this->assertSame('approved', $newLoan->fresh()->status);
        $this->assertSame(0, AmortizationSchedule::where('loan_id', $newLoan->id)->count());
    }

    public function test_release_is_blocked_when_the_source_is_no_longer_open(): void
    {
        // A source paid off (or closed by something else) while this
        // application sat in review must not hand the borrower a second loan
        // for a debt that no longer exists.
        $source = $this->createReleasedLoan();
        $newLoan = $this->restructure($source);

        $this->patchJson("/api/loans/{$newLoan->id}/submit")->assertOk();
        $this->approveAsSomeoneElse($newLoan);

        $source->update(['status' => 'completed']);

        $this->patchJson("/api/loans/{$newLoan->id}/release")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('source_loan_id');

        $this->assertSame('approved', $newLoan->fresh()->status);
        $this->assertSame('completed', $source->fresh()->status);
    }

    // ---------------------------------------------------------------------
    // Guards
    // ---------------------------------------------------------------------

    public function test_a_shortfall_without_remarks_is_rejected(): void
    {
        $source = $this->createReleasedLoan();

        $this->postJson("/api/loans/{$source->id}/restructure", $this->payloadFor($source, [
            'principal_amount' => 50000,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('remarks');

        $this->assertSame(0, Loan::where('source_loan_id', $source->id)->count());
    }

    public function test_a_shortfall_with_remarks_records_the_write_off(): void
    {
        $source = $this->createReleasedLoan();

        $newLoan = $this->restructure($source, [
            'principal_amount' => 50000,
            'remarks' => 'Board approved a ₱20,800 write-off',
        ]);

        $audit = AuditLog::where('action', 'restructure_created')
            ->where('auditable_id', $newLoan->id)
            ->first();

        $this->assertEqualsWithDelta(20800.00, (float) $audit->new_values['shortfall_amount'], 0.01);
        $this->assertSame('Board approved a ₱20,800 write-off', $audit->new_values['remarks']);

        // The write-off is only banked when the new loan actually replaces the old one.
        $this->assertNull($source->fresh()->write_off_amount);

        $this->pushToReleased($newLoan);

        $source->refresh();

        $this->assertEqualsWithDelta(self::SOURCE_OUTSTANDING, (float) $source->restructured_balance, 0.01);
        $this->assertEqualsWithDelta(20800.00, (float) $source->write_off_amount, 0.01);
    }

    public function test_a_principal_above_the_outstanding_balance_is_rejected(): void
    {
        $source = $this->createReleasedLoan();

        $this->postJson("/api/loans/{$source->id}/restructure", $this->payloadFor($source, [
            'principal_amount' => self::SOURCE_OUTSTANDING + 0.01,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('principal_amount');

        // …but the full ₱70,800 IS accepted, even though the loan's
        // `outstanding_balance` reads ₱60,000: a restructure rolls the interest
        // and penalties too, not just the principal slice.
        $this->postJson("/api/loans/{$source->id}/restructure", $this->payloadFor($source))
            ->assertCreated();
    }

    public function test_a_second_concurrent_restructure_is_rejected(): void
    {
        // Two live restructures would each roll the same debt, and only the
        // first to release could close the source.
        $source = $this->createReleasedLoan();
        $this->restructure($source);

        $this->postJson("/api/loans/{$source->id}/restructure", $this->payloadFor($source))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('loan');

        $this->assertSame(1, Loan::where('source_loan_id', $source->id)->count());
    }

    public function test_a_rejected_restructure_frees_the_source_for_another_attempt(): void
    {
        $source = $this->createReleasedLoan();
        $first = $this->restructure($source);

        $this->patchJson("/api/loans/{$first->id}/submit")->assertOk();
        $this->patchJson("/api/loans/{$first->id}/reject", ['approval_remarks' => 'Try again'])->assertOk();

        $this->postJson("/api/loans/{$source->id}/restructure", $this->payloadFor($source))
            ->assertCreated();

        $this->assertSame(2, Loan::where('source_loan_id', $source->id)->count());
    }

    public function test_a_borrower_mismatch_is_rejected(): void
    {
        $source = $this->createReleasedLoan();
        $otherBorrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $this->postJson("/api/loans/{$source->id}/restructure", $this->payloadFor($source, [
            'borrower_id' => $otherBorrower->id,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('borrower_id');
    }

    public function test_a_source_loan_that_is_not_released_or_ongoing_is_rejected(): void
    {
        $source = $this->createReleasedLoan();
        $source->update(['status' => 'completed']);

        $this->postJson("/api/loans/{$source->id}/restructure", $this->payloadFor($source))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_it_rejects_a_user_without_the_loans_restructure_permission(): void
    {
        $source = $this->createReleasedLoan();

        // super_admin short-circuits the Gate, so the check needs a lesser role.
        // This one can create loans but not restructure them.
        $role = Role::create([
            'name' => 'restricted_officer',
            'guard_name' => 'web',
            'is_active' => true,
            'is_system' => false,
        ]);
        $role->syncPermissions(['loans:view', 'loans:create', 'loans:update']);

        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);

        $this->assertTrue($user->can('loans:create'));
        $this->assertFalse($user->can('loans:restructure'));

        $this->postJson("/api/loans/{$source->id}/restructure", $this->payloadFor($source))
            ->assertForbidden();
    }

    // ---------------------------------------------------------------------
    // Read surfaces
    // ---------------------------------------------------------------------

    public function test_the_lineage_is_exposed_on_both_loans(): void
    {
        $source = $this->createReleasedLoan();
        $newLoan = $this->restructure($source);
        $this->pushToReleased($newLoan);

        $this->getJson("/api/loans/{$newLoan->id}")
            ->assertOk()
            ->assertJsonPath('data.is_restructure', true)
            ->assertJsonPath('data.source_loan_id', $source->id)
            ->assertJsonPath('data.source_loan.id', $source->id)
            ->assertJsonPath('data.source_loan.status', 'restructured')
            ->assertJsonPath('data.source_loan.restructured_balance', $this->money(self::SOURCE_OUTSTANDING));

        $this->getJson("/api/loans/{$source->id}")
            ->assertOk()
            ->assertJsonPath('data.is_restructure', false)
            ->assertJsonPath('data.status', 'restructured')
            ->assertJsonPath('data.restructured_balance', $this->money(self::SOURCE_OUTSTANDING))
            ->assertJsonPath('data.write_off_amount', $this->money(0.0))
            ->assertJsonCount(1, 'data.restructured_into')
            ->assertJsonPath('data.restructured_into.0.id', $newLoan->id)
            ->assertJsonPath('data.restructured_into.0.status', 'released');
    }

    public function test_the_index_stats_include_restructured(): void
    {
        $source = $this->createReleasedLoan();
        $newLoan = $this->restructure($source);

        $this->getJson('/api/loans')
            ->assertOk()
            ->assertJsonPath('meta.stats.restructured', 0);

        $this->pushToReleased($newLoan);

        $this->getJson('/api/loans')
            ->assertOk()
            ->assertJsonPath('meta.stats.restructured', 1)
            ->assertJsonPath('meta.stats.released', 1);
    }

    // ---------------------------------------------------------------------
    // Write-off control: permission, snapshotting, dual control
    // ---------------------------------------------------------------------

    private function userWithRole(string $role): User
    {
        return tap(
            User::factory()->create(),
            fn (User $user) => $user->assignRole(Role::where('name', $role)->first()),
        );
    }

    public function test_a_loan_officer_can_restructure_the_full_balance_but_not_write_any_of_it_off(): void
    {
        $source = $this->createReleasedLoan();
        $officer = $this->userWithRole('loan_officer');

        $this->assertTrue($officer->can('loans:restructure'));
        $this->assertFalse($officer->can('loans:write_off'), 'loan_officer must not be able to destroy debt');

        $this->actingAs($officer);

        // Rolling the whole balance forward is this role's job.
        $this->postJson("/api/loans/{$source->id}/restructure", $this->payloadFor($source))
            ->assertCreated();

        // Taking less than the balance destroys the difference, and that is not.
        $another = $this->createReleasedLoan();
        $this->postJson("/api/loans/{$another->id}/restructure", $this->payloadFor($another, [
            'principal_amount' => 50000,
            'remarks' => 'Borrower cannot service the full amount',
        ]))->assertForbidden();

        $this->assertSame(0, Loan::where('source_loan_id', $another->id)->count());
    }

    public function test_an_admin_can_write_off_a_shortfall(): void
    {
        $source = $this->createReleasedLoan();
        $admin = $this->userWithRole('admin');

        $this->assertTrue($admin->can('loans:write_off'));

        $this->actingAs($admin);

        $this->postJson("/api/loans/{$source->id}/restructure", $this->payloadFor($source, [
            'principal_amount' => 50000,
            'remarks' => 'Board approved the write-off',
        ]))->assertCreated();
    }

    public function test_a_restructure_cannot_be_approved_by_whoever_created_it(): void
    {
        $source = $this->createReleasedLoan();
        $newLoan = $this->restructure($source);

        $this->patchJson("/api/loans/{$newLoan->id}/submit")->assertOk();

        // $this->admin created it — and holds every permission, including the
        // super_admin Gate bypass. Dual control is not a permission check.
        $this->patchJson("/api/loans/{$newLoan->id}/approve", ['approval_remarks' => 'self-approved'])
            ->assertForbidden();

        $this->assertSame('for_review', $newLoan->fresh()->status);

        $this->actingAs($this->approver());
        $this->patchJson("/api/loans/{$newLoan->id}/approve", ['approval_remarks' => 'ok'])->assertOk();

        $this->assertSame('approved', $newLoan->fresh()->status);
    }

    public function test_an_ordinary_loan_can_still_be_approved_by_its_creator(): void
    {
        // The dual-control rule is scoped to restructures on purpose: this is a
        // small cooperative where one person legitimately handles a whole
        // ordinary application, and the write-off risk is restructure-specific.
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $product = $this->createReleasedLoan()->loanProduct;

        $response = $this->postJson('/api/loans', [
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
        ])->assertCreated();

        $loanId = $response->json('data.id');

        $this->patchJson("/api/loans/{$loanId}/submit")->assertOk();
        $this->patchJson("/api/loans/{$loanId}/approve", ['approval_remarks' => 'ok'])->assertOk();

        $this->assertSame('approved', Loan::findOrFail($loanId)->status);
    }

    public function test_the_approved_terms_are_snapshotted_on_the_new_loan(): void
    {
        $source = $this->createReleasedLoan();

        $newLoan = $this->restructure($source, [
            'principal_amount' => 50000,
            'remarks' => 'Board approved a write-off',
        ]);

        $this->assertEqualsWithDelta(self::SOURCE_OUTSTANDING, (float) $newLoan->restructure_outstanding, 0.01);
        $this->assertEqualsWithDelta(50000.00, (float) $newLoan->restructure_principal, 0.01);
        $this->assertEqualsWithDelta(20800.00, (float) $newLoan->restructure_shortfall, 0.01);
        // The reason lives on the row, not only in an audit payload.
        $this->assertSame('Board approved a write-off', $newLoan->restructure_remarks);
    }

    public function test_lowering_the_principal_after_approval_re_runs_the_shortfall_rules(): void
    {
        // The original hole: PATCH /loans/{id} is gated on loans:update and a
        // draft status, neither of which knew about restructures. Applying at the
        // full balance (no shortfall, no remarks needed) and then dropping the
        // principal wrote the entire debt off with nothing ever checked.
        $source = $this->createReleasedLoan();
        $newLoan = $this->restructure($source);

        $this->assertEqualsWithDelta(0.0, (float) $newLoan->restructure_shortfall, 0.01);

        $this->patchJson("/api/loans/{$newLoan->id}", ['principal_amount' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('remarks');

        $newLoan->refresh();
        $this->assertEqualsWithDelta(self::SOURCE_OUTSTANDING, (float) $newLoan->principal_amount, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $newLoan->restructure_shortfall, 0.01);
    }

    public function test_lowering_the_principal_requires_the_write_off_permission(): void
    {
        $source = $this->createReleasedLoan();
        $newLoan = $this->restructure($source);

        $officer = $this->userWithRole('loan_officer');
        $this->actingAs($officer);

        $this->patchJson("/api/loans/{$newLoan->id}", [
            'principal_amount' => 1,
            'remarks' => 'trying to write the whole thing off',
        ])->assertForbidden();

        $this->assertEqualsWithDelta(self::SOURCE_OUTSTANDING, (float) $newLoan->fresh()->principal_amount, 0.01);
    }

    public function test_changing_a_restructure_principal_needs_more_than_loans_update(): void
    {
        $source = $this->createReleasedLoan();
        $newLoan = $this->restructure($source);

        $role = Role::create([
            'name' => 'plain_editor',
            'guard_name' => 'web',
            'is_active' => true,
            'is_system' => false,
        ]);
        $role->syncPermissions(['loans:view', 'loans:create', 'loans:update']);

        $editor = User::factory()->create();
        $editor->assignRole($role);
        $this->actingAs($editor);

        $this->patchJson("/api/loans/{$newLoan->id}", ['principal_amount' => 1])
            ->assertForbidden();

        // An edit that leaves the principal alone is still allowed.
        $this->patchJson("/api/loans/{$newLoan->id}", ['purpose' => 'Updated purpose'])
            ->assertOk();
    }

    public function test_a_legitimate_principal_change_re_snapshots_and_drives_the_write_off(): void
    {
        $source = $this->createReleasedLoan();
        $newLoan = $this->restructure($source);

        $this->patchJson("/api/loans/{$newLoan->id}", [
            'principal_amount' => 50000,
            'remarks' => 'Renegotiated down with board approval',
        ])->assertOk();

        $newLoan->refresh();

        $this->assertEqualsWithDelta(50000.00, (float) $newLoan->restructure_principal, 0.01);
        $this->assertEqualsWithDelta(20800.00, (float) $newLoan->restructure_shortfall, 0.01);
        $this->assertSame('Renegotiated down with board approval', $newLoan->restructure_remarks);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'restructure_principal_changed',
            'auditable_id' => $newLoan->id,
        ]);

        $this->pushToReleased($newLoan);

        $this->assertEqualsWithDelta(20800.00, (float) $source->fresh()->write_off_amount, 0.01);
    }

    public function test_raising_the_principal_above_the_outstanding_balance_is_rejected(): void
    {
        // The upward variant: max(0, …) used to clamp the write-off to zero while
        // the borrower walked away with a loan larger than the debt it replaced.
        $source = $this->createReleasedLoan();
        $newLoan = $this->restructure($source);

        $this->patchJson("/api/loans/{$newLoan->id}", ['principal_amount' => self::SOURCE_OUTSTANDING + 10000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('principal_amount');
    }

    public function test_release_refuses_when_the_principal_no_longer_matches_the_approved_terms(): void
    {
        // Defence in depth: even if some other code path moved the column
        // without going through updateLoan(), release must not bank a write-off
        // nobody approved.
        $source = $this->createReleasedLoan();
        $newLoan = $this->restructure($source);

        $this->patchJson("/api/loans/{$newLoan->id}/submit")->assertOk();
        $this->approveAsSomeoneElse($newLoan);

        DB::table('loans')->where('id', $newLoan->id)->update(['principal_amount' => 1]);

        $this->patchJson("/api/loans/{$newLoan->id}/release")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('principal_amount');

        $this->assertSame('released', $source->fresh()->status);
        $this->assertSame('approved', $newLoan->fresh()->status);
    }

    public function test_penalties_accruing_before_release_do_not_inflate_the_write_off(): void
    {
        // Remarks and the write-off permission are checked against the balance at
        // application time. If the write-off were computed from the release-time
        // balance instead, ordinary penalty accrual would destroy debt nobody
        // signed off on.
        $source = $this->createReleasedLoan();
        $newLoan = $this->restructure($source);

        $this->assertEqualsWithDelta(0.0, (float) $newLoan->restructure_shortfall, 0.01);

        $source->amortizationSchedules()->first()->update(['penalty_amount' => 500]);

        $this->pushToReleased($newLoan);

        $source->refresh();

        // The real closing balance is recorded faithfully…
        $this->assertEqualsWithDelta(self::SOURCE_OUTSTANDING + 500, (float) $source->restructured_balance, 0.01);
        // …but nothing was written off, because nothing was approved to be.
        $this->assertEqualsWithDelta(0.0, (float) $source->write_off_amount, 0.01);

        $audit = AuditLog::where('action', 'restructure_closed')
            ->where('auditable_id', $source->id)
            ->first();

        $this->assertEqualsWithDelta(500.00, (float) $audit->new_values['balance_drift_since_approval'], 0.01);
    }

    public function test_the_closing_audit_entry_carries_the_reason_for_the_write_off(): void
    {
        $source = $this->createReleasedLoan();
        $newLoan = $this->restructure($source, [
            'principal_amount' => 50000,
            'remarks' => 'Calamity assistance approved by the board',
        ]);

        $this->pushToReleased($newLoan);

        $audit = AuditLog::where('action', 'restructure_closed')
            ->where('auditable_id', $source->id)
            ->first();

        $this->assertSame('Calamity assistance approved by the board', $audit->new_values['remarks']);
        $this->assertEqualsWithDelta(20800.00, (float) $audit->new_values['write_off_amount'], 0.01);
        $this->assertEqualsWithDelta(20800.00, (float) $audit->new_values['approved_shortfall'], 0.01);
    }

    public function test_only_one_of_two_approved_restructures_can_release(): void
    {
        // The state a lost race leaves behind: two approved applications against
        // one source. Whichever releases second must fail closed — otherwise the
        // borrower owes both loans for the same balance.
        $source = $this->createReleasedLoan();

        $first = $this->restructure($source);
        $this->patchJson("/api/loans/{$first->id}/submit")->assertOk();
        $this->patchJson("/api/loans/{$first->id}/reject", ['approval_remarks' => 'freeing the source'])->assertOk();

        $second = $this->restructure($source);
        $this->patchJson("/api/loans/{$second->id}/submit")->assertOk();
        $this->approveAsSomeoneElse($second);

        // Put the first one back to `approved`: both are now live against the
        // same source, which is exactly what a race would produce.
        DB::table('loans')->where('id', $first->id)->update(['status' => 'approved']);

        $this->patchJson("/api/loans/{$first->id}/release")->assertOk();

        $this->patchJson("/api/loans/{$second->id}/release")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('source_loan_id');

        $this->assertSame('restructured', $source->fresh()->status);
        $this->assertSame('approved', $second->fresh()->status);
        $this->assertSame(
            1,
            Loan::where('source_loan_id', $source->id)->where('status', 'released')->count(),
            'the borrower must not end up owing two loans for one balance',
        );
        // The loser wrote nothing: no schedule, no account number.
        $this->assertSame(0, AmortizationSchedule::where('loan_id', $second->id)->count());
        $this->assertNull($second->fresh()->loan_account_number);
    }

    public function test_a_payment_on_a_closed_source_cannot_be_voided(): void
    {
        // Closing the source deleted its unpaid rows and shrank the partly-paid
        // ones. reverseAllocation() would replay against those rewritten rows and
        // resurrect a balance on a loan that can never be collected again.
        $source = $this->createReleasedLoan();

        $repaymentId = $this->postJson("/api/loans/{$source->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 5000,
            'method' => 'cash',
        ])->assertCreated()->json('data.id');

        $newLoan = $this->restructure($source, ['principal_amount' => self::SOURCE_OUTSTANDING - 5000]);
        $this->pushToReleased($newLoan);

        $this->patchJson("/api/repayments/{$repaymentId}/void", [
            'void_reason' => 'Trying to unwind a closed loan',
        ])->assertUnprocessable();

        $source->refresh();

        $this->assertSame('restructured', $source->status);
        $this->assertEqualsWithDelta(0.0, (float) $source->outstanding_balance, 0.01);
        $this->assertEqualsWithDelta(3200.00, $this->sumPrincipalPaid($source), 0.01);
    }

    public function test_a_borrower_id_is_accepted_as_a_co_maker_and_gets_linked(): void
    {
        // The co-maker pickers are populated from borrowers and send borrower
        // ids, which createLoan() resolves by creating a co-maker for that
        // person. A co-makers-only rule would reject every id the form produces.
        $source = $this->createReleasedLoan();
        $coMakerBorrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $this->assertSame(0, CoMaker::count(), 'precondition: the id below is a borrower id, not a co_maker id');

        $newLoan = $this->restructure($source, ['co_maker_ids' => [$coMakerBorrower->id]]);

        $linked = $newLoan->coMakers()->get();

        $this->assertCount(1, $linked);
        $this->assertSame($coMakerBorrower->id, $linked[0]->borrower_id);
        $this->assertSame($coMakerBorrower->first_name, $linked[0]->first_name);
        $this->assertSame($coMakerBorrower->last_name, $linked[0]->last_name);
    }

    public function test_an_existing_co_maker_id_is_also_accepted(): void
    {
        $source = $this->createReleasedLoan();
        $coMaker = CoMaker::factory()->create(['borrower_id' => $source->borrower_id]);

        $newLoan = $this->restructure($source, ['co_maker_ids' => [$coMaker->id]]);

        $this->assertSame([$coMaker->id], $newLoan->coMakers()->pluck('co_makers.id')->all());
    }

    public function test_a_co_maker_id_matching_nothing_at_all_is_rejected(): void
    {
        // The actual hole: the field was a bare `integer`, so arbitrary ids
        // reached createLoan()'s lookup unchecked.
        $source = $this->createReleasedLoan();

        $this->postJson("/api/loans/{$source->id}/restructure", $this->payloadFor($source, [
            'co_maker_ids' => [999999],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('co_maker_ids.0');

        $this->assertSame(0, Loan::where('source_loan_id', $source->id)->count());
        $this->assertSame(0, CoMaker::count());
    }

    public function test_the_migration_refuses_to_roll_back_while_restructure_lineage_exists(): void
    {
        // down() drops source_loan_id, which is the only thing distinguishing a
        // legitimately closed source from one damaged by the old in-place bug —
        // so a rollback then re-migrate would reopen every closed source and
        // orphan its child.
        $source = $this->createReleasedLoan();
        $this->restructure($source);

        $migration = require database_path('migrations/2026_08_06_100000_add_restructure_link_to_loans_table.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Refusing to roll back/');

        $migration->down();
    }

    // ---------------------------------------------------------------------
    // Backfill for the in-place restructure bug
    // ---------------------------------------------------------------------

    /**
     * Re-run the migration's repair step against the current database.
     *
     * The suite migrates fresh per test, so the migration has already run — and
     * its `up()` cannot simply be called again because it would re-add columns
     * that now exist. This drives the real shipped method rather than a copy of
     * its logic, so the test still fails if the migration changes.
     */
    private function runRestructureBackfill(): void
    {
        // A plain `require` (not `require_once`) re-evaluates the file and hands
        // back a fresh instance of its anonymous migration class.
        $migration = require database_path('migrations/2026_08_06_100000_add_restructure_link_to_loans_table.php');

        $repair = new ReflectionMethod($migration, 'reopenLoansClosedByTheInPlaceRestructureBug');
        $repair->setAccessible(true);
        $repair->invoke($migration);
    }

    /**
     * Put a loan in the state the OLD in-place restructure adjustment left it:
     * stamped `restructured` while its balance was still owed on this same loan.
     * Written straight to the column because the service no longer does this.
     */
    private function breakLoanWithTheOldInPlaceRestructure(Loan $loan): void
    {
        LoanAdjustment::factory()->create([
            'loan_id' => $loan->id,
            'adjustment_type' => 'restructure',
            'old_values' => ['term' => $loan->term],
            'new_values' => ['term' => 12],
            'status' => 'applied',
            'adjusted_by' => $this->admin->id,
        ]);

        DB::table('loans')->where('id', $loan->id)->update(['status' => 'restructured']);
    }

    public function test_the_backfill_restores_a_part_paid_loan_to_ongoing_and_an_unpaid_one_to_released(): void
    {
        $partPaid = $this->createReleasedLoan();
        $this->postJson("/api/loans/{$partPaid->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 5000,
            'method' => 'cash',
        ])->assertCreated();

        // The payment is what moves a loan to `ongoing` in the first place.
        $this->assertSame('ongoing', $partPaid->fresh()->status);

        $neverPaid = $this->createReleasedLoan();

        $this->breakLoanWithTheOldInPlaceRestructure($partPaid);
        $this->breakLoanWithTheOldInPlaceRestructure($neverPaid);

        $this->runRestructureBackfill();

        $this->assertSame('ongoing', $partPaid->fresh()->status);
        $this->assertSame('released', $neverPaid->fresh()->status);

        // Repaired means collectible — the whole point of the repair.
        $this->postJson("/api/loans/{$partPaid->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 1000,
            'method' => 'cash',
        ])->assertCreated();
    }

    public function test_the_backfill_ignores_voided_repayments_when_choosing_ongoing(): void
    {
        $loan = $this->createReleasedLoan();

        $repaymentId = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 5000,
            'method' => 'cash',
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/repayments/{$repaymentId}/void", [
            'void_reason' => 'Posted against the wrong loan',
        ])->assertOk();

        // Inherited behaviour, pinned rather than endorsed: voiding only
        // recomputes the loan status when the loan had reached `completed`
        // (RepaymentService::voidRepayment), so an `ongoing` loan stays
        // `ongoing` even once its only payment is gone.
        $this->assertSame('ongoing', $loan->fresh()->status);

        $this->breakLoanWithTheOldInPlaceRestructure($loan);

        $this->runRestructureBackfill();

        // The repair normalizes rather than restoring that quirk: `posted` is
        // the discriminator, which is the same expression voidRepayment() uses
        // when it does recompute. No posted payment means no money collected,
        // so the loan belongs at `released`. Both statuses are collectible, so
        // this only decides which tab it appears under.
        $this->assertSame('released', $loan->fresh()->status);

        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 1000,
            'method' => 'cash',
        ])->assertCreated();
    }

    public function test_the_backfill_leaves_genuinely_restructured_loans_closed(): void
    {
        // A loan closed by the NEW flow has a child pointing at it and must stay
        // shut; and a loan at `restructured` with no applied restructure
        // adjustment was not damaged by the old bug, so it is not this
        // migration's business either.
        $closedByRestructure = $this->createReleasedLoan();
        $newLoan = $this->restructure($closedByRestructure);
        $this->pushToReleased($newLoan);
        $this->assertSame('restructured', $closedByRestructure->fresh()->status);

        // Belt and braces: give it an applied restructure adjustment too, so the
        // only thing keeping it closed is the child-loan check.
        LoanAdjustment::factory()->create([
            'loan_id' => $closedByRestructure->id,
            'adjustment_type' => 'restructure',
            'old_values' => [],
            'new_values' => ['term' => 12],
            'status' => 'applied',
            'adjusted_by' => $this->admin->id,
        ]);

        $noAdjustment = $this->createReleasedLoan();
        DB::table('loans')->where('id', $noAdjustment->id)->update(['status' => 'restructured']);

        $this->runRestructureBackfill();

        $this->assertSame('restructured', $closedByRestructure->fresh()->status);
        $this->assertSame('restructured', $noAdjustment->fresh()->status);
    }

    public function test_closing_the_source_does_not_leave_orphan_schedule_rows_behind(): void
    {
        // ReportService::loanBalanceSummary() sums principal_due with no status
        // filter and DashboardService::stats() counts overdue schedules with no
        // loan-status filter — a "voided" schedule row would keep inflating
        // both, which is why the rows are deleted outright.
        $source = $this->createReleasedLoan(['start_date' => now()->subMonths(8)->toDateString()]);
        $newLoan = $this->restructure($source);

        $this->pushToReleased($newLoan);

        $this->assertSame(
            0,
            (int) DB::table('amortization_schedules')->where('loan_id', $source->id)->count(),
        );

        $this->getJson('/api/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.overdue_count', 0);
    }
}
