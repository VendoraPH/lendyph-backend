<?php

namespace Tests\Feature\Ledger\Adjustments;

use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Services\LoanAdjustmentService;
use App\Services\RepaymentService;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A balance adjustment that changes nothing is refused when it is requested
 * (422), so a no-op never goes through approval: a target equal to the
 * current unpaid principal, or an `adjustment_amount` of ₱0.00. Other
 * adjustment types are unaffected.
 *
 * The loan from createReleasedLoan(): ₱60,000 of principal over 6 monthly
 * periods, all unpaid.
 */
class ZeroBalanceAdjustmentRequestTest extends TestCase
{
    use SetupLendyPH;

    private const TARGET_MESSAGE = 'The target equals the current principal balance, so there is nothing to adjust.';

    private const AMOUNT_MESSAGE = 'The adjustment amount is ₱0.00, so there is nothing to adjust.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_a_target_equal_to_the_unpaid_principal_is_refused(): void
    {
        $loan = $this->createReleasedLoan();

        $this->request($loan, ['target_principal_balance' => 60000])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => self::TARGET_MESSAGE,
                'errors' => ['new_values.target_principal_balance' => [self::TARGET_MESSAGE]],
            ]);

        $this->assertSame(0, LoanAdjustment::count());
    }

    public function test_a_target_equal_to_the_principal_left_after_a_payment_is_refused_to_the_centavo(): void
    {
        $loan = $this->createReleasedLoan();
        // ₱1,800.00 of interest and ₱3,200.37 of principal: ₱56,799.63 unpaid.
        app(RepaymentService::class)->processRepayment($loan->fresh(), 5000.37, now()->toDateString(), $this->admin);

        $this->request($loan, ['target_principal_balance' => 56799.63])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['new_values.target_principal_balance' => self::TARGET_MESSAGE]);

        $this->request($loan, ['target_principal_balance' => 56799.62])->assertCreated();
    }

    /**
     * @return array<string, array{int|float|string}>
     */
    public static function zeroAmounts(): array
    {
        return [
            'integer' => [0],
            'two decimals' => ['0.00'],
            'negative zero' => ['-0.00'],
        ];
    }

    #[DataProvider('zeroAmounts')]
    public function test_an_amount_of_zero_is_refused(int|float|string $amount): void
    {
        $loan = $this->createReleasedLoan();

        $this->request($loan, ['adjustment_amount' => $amount])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => self::AMOUNT_MESSAGE,
                'errors' => ['new_values.adjustment_amount' => [self::AMOUNT_MESSAGE]],
            ]);

        $this->assertSame(0, LoanAdjustment::count());
    }

    public function test_a_centavo_either_way_is_still_accepted(): void
    {
        $loan = $this->createReleasedLoan();

        $this->request($loan, ['adjustment_amount' => 0.01])->assertCreated();
        $this->request($loan, ['adjustment_amount' => -0.01])->assertCreated();
        $this->request($loan, ['target_principal_balance' => 60000.01])->assertCreated();
        $this->request($loan, ['target_principal_balance' => 59999.99])->assertCreated();
    }

    public function test_the_service_refuses_it_too(): void
    {
        $loan = $this->createReleasedLoan();

        try {
            app(LoanAdjustmentService::class)->createAdjustment($loan, [
                'adjustment_type' => 'balance_adjustment',
                'new_values' => ['target_principal_balance' => 60000],
            ], $this->admin);
            $this->fail('A target equal to the unpaid principal was accepted.');
        } catch (ValidationException $e) {
            $this->assertSame(['new_values.target_principal_balance' => [self::TARGET_MESSAGE]], $e->errors());
        }
    }

    public function test_the_preview_still_shows_a_zero_amount(): void
    {
        $loan = $this->createReleasedLoan();

        $this->getJson("/api/loans/{$loan->id}/balance-adjustment-preview?target_principal_balance=60000")
            ->assertOk()
            ->assertJsonPath('data.adjustment_amount', 0);
    }

    public function test_other_adjustment_types_are_unaffected(): void
    {
        $loan = $this->createReleasedLoan();

        $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'penalty_waiver',
            'new_values' => ['waive_all' => true],
        ])->assertCreated();
        $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'term_extension',
            'new_values' => ['additional_terms' => 1],
        ])->assertCreated();
        $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'restructure',
            'new_values' => ['term' => 6],
        ])->assertCreated();

        $this->assertSame(3, LoanAdjustment::count());
    }

    /**
     * @param  array<string, mixed>  $newValues
     */
    private function request(Loan $loan, array $newValues): TestResponse
    {
        return $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'balance_adjustment',
            'new_values' => $newValues,
        ]);
    }
}
