<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\LoanAdjustmentController;
use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\User;
use App\Services\RepaymentService;
use OpenApi\Attributes as OA;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A balance adjustment may be requested as the principal balance the loan
 * should end on. The server works out the amount from the loan's actual
 * unpaid principal (never a balance that includes interest), shows it first
 * through a read-only preview, and stores it with the request, so what is
 * approved is what is applied.
 *
 * The loan from createReleasedLoan(): ₱60,000 over 6 monthly periods of
 * ₱10,000 principal and ₱1,800 interest. A ₱5,000 payment pays period 1's
 * ₱1,800 interest and ₱3,200 of its principal: ₱56,800 of principal is then
 * unpaid, while ₱65,800 is owed with the interest.
 */
class BalanceAdjustmentTargetTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_the_preview_works_from_unpaid_principal_not_a_balance_with_interest(): void
    {
        $loan = $this->loanWithInterestOwed();

        $this->getJson("/api/loans/{$loan->id}/balance-adjustment-preview?target_principal_balance=50000")
            ->assertOk()
            ->assertExactJson(['data' => [
                'outstanding_principal' => 56800,
                'target_principal_balance' => 50000,
                'adjustment_amount' => -6800,
                'resulting_principal_balance' => 50000,
            ]]);
    }

    public function test_the_preview_handles_centavos_and_increases(): void
    {
        $loan = $this->loanWithInterestOwed();

        $data = $this->getJson("/api/loans/{$loan->id}/balance-adjustment-preview?target_principal_balance=57000.55")
            ->assertOk()
            ->json('data');

        $this->assertEquals(200.55, $data['adjustment_amount']);
        $this->assertEquals(57000.55, $data['resulting_principal_balance']);
    }

    public function test_the_preview_writes_nothing(): void
    {
        $loan = $this->loanWithInterestOwed();
        $before = $this->snapshot($loan);

        $this->getJson("/api/loans/{$loan->id}/balance-adjustment-preview?target_principal_balance=0")->assertOk();

        $this->assertSame($before, $this->snapshot($loan));
    }

    public function test_the_preview_needs_the_permission_to_request_an_adjustment(): void
    {
        $loan = $this->createReleasedLoan();
        $this->actingAs(User::factory()->create(['branch_id' => $this->branch->id]));

        $this->getJson("/api/loans/{$loan->id}/balance-adjustment-preview?target_principal_balance=50000")->assertForbidden();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badTargets(): array
    {
        return [
            'missing' => [''],
            'negative' => ['target_principal_balance=-1'],
            'three decimals' => ['target_principal_balance=100.555'],
            'not a number' => ['target_principal_balance=abc'],
        ];
    }

    #[DataProvider('badTargets')]
    public function test_the_preview_refuses_a_bad_target(string $query): void
    {
        $loan = $this->createReleasedLoan();

        $this->getJson("/api/loans/{$loan->id}/balance-adjustment-preview?{$query}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('target_principal_balance');
    }

    public function test_the_preview_refuses_a_loan_that_cannot_be_adjusted(): void
    {
        $loan = $this->createReleasedLoan();
        $loan->update(['status' => 'completed']);

        $this->getJson("/api/loans/{$loan->id}/balance-adjustment-preview?target_principal_balance=50000")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('loan');
    }

    public function test_a_request_by_target_stores_the_server_s_amount_and_applying_it_lands_on_the_target(): void
    {
        $loan = $this->loanWithInterestOwed();

        $id = $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'balance_adjustment',
            'new_values' => ['target_principal_balance' => 50000, 'reason' => 'Agreed settlement'],
        ])->assertCreated()->json('data.id');

        $stored = LoanAdjustment::findOrFail($id)->new_values;
        $this->assertEquals(-6800, $stored['adjustment_amount']);
        $this->assertEquals(50000, $stored['target_principal_balance']);
        $this->assertEquals(56800, $stored['outstanding_principal_at_request']);

        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk();

        $this->assertSame(5000000, $this->unpaidPrincipal($loan));
    }

    public function test_a_request_by_amount_is_still_accepted(): void
    {
        $loan = $this->createReleasedLoan();

        $id = $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'balance_adjustment',
            'new_values' => ['adjustment_amount' => -1000],
        ])->assertCreated()->json('data.id');

        $this->assertEquals(-1000, LoanAdjustment::findOrFail($id)->new_values['adjustment_amount']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function badRequests(): array
    {
        return [
            'both the target and the amount' => [['target_principal_balance' => 50000, 'adjustment_amount' => -1000], 'new_values'],
            'neither' => [['reason' => 'No figure'], 'new_values.adjustment_amount'],
            'a negative target' => [['target_principal_balance' => -1], 'new_values.target_principal_balance'],
            'a target with three decimals' => [['target_principal_balance' => 100.555], 'new_values.target_principal_balance'],
            'a client-stated outstanding principal' => [['target_principal_balance' => 50000, 'outstanding_principal_at_request' => 1], 'new_values.outstanding_principal_at_request'],
        ];
    }

    /**
     * @param  array<string, mixed>  $newValues
     */
    #[DataProvider('badRequests')]
    public function test_a_balance_adjustment_request_must_state_exactly_one_valid_figure(array $newValues, string $field): void
    {
        $loan = $this->createReleasedLoan();

        $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'balance_adjustment',
            'new_values' => $newValues,
        ])->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertSame(0, LoanAdjustment::where('loan_id', $loan->id)->count());
    }

    public function test_the_preview_is_documented(): void
    {
        $attributes = (new ReflectionMethod(LoanAdjustmentController::class, 'balanceAdjustmentPreview'))->getAttributes(OA\Get::class);

        $this->assertCount(1, $attributes);
        $this->assertSame('/api/loans/{loan}/balance-adjustment-preview', $attributes[0]->newInstance()->path);
    }

    private function loanWithInterestOwed(): Loan
    {
        $loan = $this->createReleasedLoan();
        app(RepaymentService::class)->processRepayment($loan->fresh(), 5000, now()->toDateString(), $this->admin);

        return $loan->fresh();
    }

    private function unpaidPrincipal(Loan $loan): int
    {
        return AmortizationSchedule::where('loan_id', $loan->id)
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->get()
            ->sum(fn (AmortizationSchedule $s): int => (int) round((float) $s->principal_due * 100) - (int) round((float) $s->principal_paid * 100));
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Loan $loan): array
    {
        return [
            'adjustments' => LoanAdjustment::where('loan_id', $loan->id)->count(),
            'schedules' => AmortizationSchedule::where('loan_id', $loan->id)->orderBy('id')->get()->map->getRawOriginal()->all(),
            'loan' => Loan::findOrFail($loan->id)->getRawOriginal(),
        ];
    }
}
