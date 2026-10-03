<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\LoanService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * POST /loans/preview: every figure the loan form shows — the collateral
 * total, the security status, the "Short by" amount and the amortization
 * preview — computed by the server, the schedule by the same code that writes
 * the real one at release.
 */
class LoanFormPreviewTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_it_totals_the_collateral_and_reports_a_partly_secured_loan_short_by_the_difference(): void
    {
        $this->postJson('/api/loans/preview', [
            'principal_amount' => 50000,
            'collaterals' => [
                ['collateral_id' => 1, 'snapshot_value' => 25000.50],
                ['collateral_id' => 2, 'snapshot_value' => '10000.25'],
            ],
        ])->assertOk()->assertExactJson(['data' => [
            'collateral' => ['total_value' => 35000.75, 'security_status' => 'partially_secured', 'short_by' => 14999.25],
            'maturity_date' => null,
            'deductions' => null,
            'amortization' => null,
        ]]);
    }

    /**
     * @return array<string, array{float|int|null, list<float>, array{total_value: float|int, security_status: string, short_by: float|int}}>
     */
    public static function securityCases(): array
    {
        return [
            'collateral equal to the principal' => [50000, [30000, 20000], ['total_value' => 50000, 'security_status' => 'secured', 'short_by' => 0]],
            'collateral above the principal' => [50000, [60000.10], ['total_value' => 60000.1, 'security_status' => 'secured', 'short_by' => 0]],
            'no collateral' => [50000, [], ['total_value' => 0, 'security_status' => 'unsecured', 'short_by' => 50000]],
            'collateral worth nothing' => [50000, [0], ['total_value' => 0, 'security_status' => 'unsecured', 'short_by' => 50000]],
            'no principal yet' => [null, [1000], ['total_value' => 1000, 'security_status' => 'unsecured', 'short_by' => 0]],
            'a centavo short' => [50000, [49999.99], ['total_value' => 49999.99, 'security_status' => 'partially_secured', 'short_by' => 0.01]],
        ];
    }

    /**
     * @param  list<float>  $values
     * @param  array{total_value: float|int, security_status: string, short_by: float|int}  $expected
     */
    #[DataProvider('securityCases')]
    public function test_it_states_the_security_status_and_shortfall(float|int|null $principal, array $values, array $expected): void
    {
        $collateral = $this->postJson('/api/loans/preview', array_filter([
            'principal_amount' => $principal,
            'collaterals' => array_map(fn (float|int $value): array => ['snapshot_value' => $value], $values),
        ], fn (mixed $value): bool => $value !== null))->assertOk()->json('data.collateral');

        $this->assertEquals($expected, $collateral);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function products(): array
    {
        return [
            'straight, monthly' => [['interest_method' => 'straight', 'frequency' => 'monthly'], 'monthly'],
            'diminishing, monthly' => [['interest_method' => 'diminishing', 'frequency' => 'monthly'], 'monthly'],
            'straight, semi-monthly' => [['interest_method' => 'straight', 'frequency' => 'semi_monthly'], 'semi_monthly'],
            'diminishing, weekly' => [['interest_method' => 'diminishing', 'frequency' => 'weekly'], 'weekly'],
            'straight, upon maturity' => [['interest_method' => 'straight', 'frequency' => 'upon_maturity'], 'upon_maturity'],
        ];
    }

    /**
     * The preview's rows are the rows a release of the same loan writes.
     *
     * @param  array<string, mixed>  $productAttributes
     */
    #[DataProvider('products')]
    public function test_the_schedule_is_the_one_a_release_of_the_same_loan_writes(array $productAttributes, string $frequency): void
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.25,
            'term' => 6,
            'min_term' => 1,
            'max_term' => 12,
            'processing_fee' => 0,
            'service_fee' => 0,
            'notarial_fee' => 0,
            ...$productAttributes,
        ]);
        $terms = [
            'loan_product_id' => $product->id,
            'principal_amount' => 50000.10,
            'interest_rate' => 3.25,
            'term' => 5,
            'frequency' => $frequency,
            'start_date' => '2026-01-31',
        ];

        $preview = $this->postJson('/api/loans/preview', $terms)->assertOk()->json('data.amortization');

        $released = $this->releasedLoan($terms);
        $written = $released->amortizationSchedules()->orderBy('period_number')->get()
            ->map(fn (AmortizationSchedule $row): array => [
                'period_number' => $row->period_number,
                'due_date' => $row->due_date->toDateString(),
                'principal_due' => (float) $row->principal_due,
                'interest_due' => (float) $row->interest_due,
                'total_due' => (float) $row->total_due,
                'remaining_balance' => (float) $row->remaining_balance,
            ])->all();

        $shown = array_map(fn (array $row): array => [
            'period_number' => $row['period_number'],
            'due_date' => $row['due_date'],
            'principal_due' => (float) $row['principal_due'],
            'interest_due' => (float) $row['interest_due'],
            'total_due' => (float) $row['total_due'],
            'remaining_balance' => (float) $row['remaining_balance'],
        ], $preview['rows']);

        $this->assertSame($written, $shown);
        $this->assertSame($released->maturity_date->toDateString(), $preview['maturity_date']);
        $this->assertSame($productAttributes['interest_method'], $preview['interest_method']);
    }

    public function test_it_adds_the_share_capital_build_up_to_each_period_and_totals_every_column(): void
    {
        $product = LoanProduct::factory()->create(['interest_rate' => 3, 'interest_method' => 'straight', 'frequency' => 'monthly', 'term' => 3, 'max_term' => 3]);

        $amortization = $this->postJson('/api/loans/preview', [
            'loan_product_id' => $product->id,
            'principal_amount' => 10000,
            'interest_rate' => 3,
            'term' => 3,
            'frequency' => 'monthly',
            'start_date' => '2026-10-03',
            'scb_amount' => 100.50,
        ])->assertOk()->json('data.amortization');

        $this->assertCount(3, $amortization['rows']);

        foreach ($amortization['rows'] as $row) {
            $this->assertEquals(100.5, $row['share_capital_build_up']);
            $this->assertEquals(round($row['total_due'] + 100.5, 2), $row['total_payment']);
        }

        $this->assertEquals([
            'principal_due' => 10000,
            'interest_due' => 900,
            'share_capital_build_up' => 301.5,
            'total_payment' => 11201.5,
        ], $amortization['totals']);
    }

    public function test_the_schedule_uses_the_product_s_interest_method_whatever_the_form_says(): void
    {
        $product = LoanProduct::factory()->create(['interest_rate' => 3, 'interest_method' => 'straight', 'frequency' => 'monthly', 'term' => 3, 'max_term' => 3]);

        $amortization = $this->postJson('/api/loans/preview', [
            'loan_product_id' => $product->id,
            'principal_amount' => 10000,
            'interest_rate' => 3,
            'term' => 3,
            'frequency' => 'monthly',
            'start_date' => '2026-10-03',
            'interest_method' => 'diminishing',
        ])->assertOk()->json('data.amortization');

        $this->assertSame('straight', $amortization['interest_method']);
        $this->assertEquals([300, 300, 300], array_column($amortization['rows'], 'interest_due'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function missingScheduleInputs(): array
    {
        return [
            'product' => ['loan_product_id'],
            'principal' => ['principal_amount'],
            'rate' => ['interest_rate'],
            'term' => ['term'],
            'frequency' => ['frequency'],
            'start date' => ['start_date'],
        ];
    }

    #[DataProvider('missingScheduleInputs')]
    public function test_there_is_no_schedule_until_every_schedule_input_is_given(string $missing): void
    {
        $product = LoanProduct::factory()->create(['interest_rate' => 3, 'interest_method' => 'straight', 'frequency' => 'monthly', 'term' => 3, 'max_term' => 3]);
        $terms = [
            'loan_product_id' => $product->id,
            'principal_amount' => 10000,
            'interest_rate' => 3,
            'term' => 3,
            'frequency' => 'monthly',
            'start_date' => '2026-10-03',
        ];
        unset($terms[$missing]);

        $this->postJson('/api/loans/preview', $terms)->assertOk()->assertJsonPath('data.amortization', null);
    }

    /**
     * A term outside the product's range is one saving would refuse, so there
     * is no schedule to preview, however long the term.
     */
    public function test_there_is_no_schedule_for_a_term_outside_the_product_s_range(): void
    {
        $product = LoanProduct::factory()->create(['interest_rate' => 3, 'interest_method' => 'straight', 'frequency' => 'monthly', 'term' => 3, 'min_term' => 2, 'max_term' => 5]);
        $terms = [
            'loan_product_id' => $product->id,
            'principal_amount' => 10000,
            'interest_rate' => 3,
            'frequency' => 'monthly',
            'start_date' => '2026-10-03',
        ];

        foreach ([1, 6, 1_000_000] as $term) {
            $this->postJson('/api/loans/preview', [...$terms, 'term' => $term])->assertOk()->assertJsonPath('data.amortization', null);
        }

        $this->assertCount(5, $this->postJson('/api/loans/preview', [...$terms, 'term' => 5])->assertOk()->json('data.amortization.rows'));
    }

    public function test_it_writes_nothing(): void
    {
        $product = LoanProduct::factory()->create(['interest_rate' => 3, 'interest_method' => 'straight', 'frequency' => 'monthly', 'term' => 3, 'max_term' => 3]);
        $before = [Loan::count(), AmortizationSchedule::count(), AuditLog::count()];

        $this->postJson('/api/loans/preview', [
            'loan_product_id' => $product->id,
            'principal_amount' => 10000,
            'interest_rate' => 3,
            'term' => 3,
            'frequency' => 'monthly',
            'start_date' => '2026-10-03',
            'collaterals' => [['snapshot_value' => 5000]],
        ])->assertOk();

        $this->assertSame($before, [Loan::count(), AmortizationSchedule::count(), AuditLog::count()]);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function malformedInputs(): array
    {
        return [
            'a principal that is not a number' => [['principal_amount' => 'fifty thousand'], 'principal_amount'],
            'an unknown frequency' => [['frequency' => 'fortnightly'], 'frequency'],
            'a start date that is not a date' => [['start_date' => 'soon'], 'start_date'],
            'a negative collateral value' => [['collaterals' => [['snapshot_value' => -1]]], 'collaterals.0.snapshot_value'],
            'a collateral with no value' => [['collaterals' => [['collateral_id' => 3]]], 'collaterals.0.snapshot_value'],
            'an unknown product' => [['loan_product_id' => 999999], 'loan_product_id'],
            'a rate with more places than saving accepts' => [['interest_rate' => 2.12345], 'interest_rate'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('malformedInputs')]
    public function test_malformed_input_is_a_422(array $payload, string $field): void
    {
        $this->postJson('/api/loans/preview', $payload)->assertStatus(422)->assertJsonValidationErrors($field);
    }

    public function test_it_needs_loans_create_or_loans_update(): void
    {
        $this->actingAs(User::factory()->create(['branch_id' => $this->branch->id]));
        $this->postJson('/api/loans/preview', ['principal_amount' => 1000])->assertForbidden();

        $creator = User::factory()->create(['branch_id' => $this->branch->id]);
        $creator->givePermissionTo('loans:create');
        $this->actingAs($creator);
        $this->postJson('/api/loans/preview', ['principal_amount' => 1000])->assertOk();

        $editor = User::factory()->create(['branch_id' => $this->branch->id]);
        $editor->givePermissionTo('loans:update');
        $this->actingAs($editor);
        $this->postJson('/api/loans/preview', ['principal_amount' => 1000])->assertOk();
    }

    /**
     * @param  array<string, mixed>  $terms
     */
    private function releasedLoan(array $terms): Loan
    {
        $service = app(LoanService::class);

        $loan = $service->createLoan([
            ...$terms,
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'deductions' => [],
        ], $this->admin);

        $service->submitForReview($loan);
        $service->approve($loan, $this->admin, 'Approved for testing');

        return $service->release($loan->fresh(), $this->admin)->fresh();
    }
}
