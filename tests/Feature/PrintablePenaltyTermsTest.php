<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LoanService;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The promissory note and the disclosure statement carry the loan's own late
 * penalty terms, so they can print them: the configuration copied at creation
 * (its name and day ranges, as LoanResource sends them) or, on a loan charged
 * by a fixed rate, that rate with null bracket fields.
 */
class PrintablePenaltyTermsTest extends TestCase
{
    use SetupLendyPH;

    private const BRACKETS = [
        ['days_from' => 1, 'days_to' => 30, 'rate' => 2.5],
        ['days_from' => 31, 'days_to' => null, 'rate' => 5],
    ];

    private LoanProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'penalty_rate' => 3.0,
            'grace_period_days' => 5,
            'processing_fee' => 0,
            'service_fee' => 0,
            'notarial_fee' => 0,
            'min_amount' => 1000,
            'max_amount' => 1000000,
        ]);
    }

    private function approvedLoan(): Loan
    {
        $service = app(LoanService::class);
        $loan = $service->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $this->product->id,
            'principal_amount' => 30000,
            'start_date' => '2026-01-15',
            'deductions' => [],
        ], $this->admin);
        $service->submitForReview($loan);
        $service->approve($loan, $this->admin, 'Approved for testing');

        return $loan->fresh();
    }

    /** @return array{note: array<string, mixed>, disclosure: array<string, mixed>} */
    private function terms(Loan $loan): array
    {
        return [
            'note' => $this->getJson("/api/loans/{$loan->id}/promissory-note")->assertOk()->json('data.loan_terms'),
            'disclosure' => $this->getJson("/api/loans/{$loan->id}/disclosure")->assertOk()->json('data.loan_terms'),
        ];
    }

    public function test_a_loan_on_a_penalty_configuration_sends_its_name_and_day_ranges(): void
    {
        $this->postJson('/api/penalty-configs', [
            'name' => 'Standard Loan Penalty',
            'status' => 'active',
            'applicable_product_ids' => [$this->product->id],
            'conditions' => self::BRACKETS,
        ])->assertCreated();

        $loan = $this->approvedLoan();
        $this->assertSame('Standard Loan Penalty', $loan->penalty_config_name);

        $resource = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data');

        foreach ($this->terms($loan) as $document => $terms) {
            $this->assertSame('Standard Loan Penalty', $terms['penalty_config_name'], $document);
            $this->assertEquals(self::BRACKETS, $terms['penalty_brackets'], $document);
            $this->assertSame($resource['penalty_brackets'], $terms['penalty_brackets'], $document);
            $this->assertEquals(0, $terms['penalty_rate'], $document);
            $this->assertSame(5, $terms['grace_period_days'], $document);
        }
    }

    public function test_a_loan_charged_by_a_fixed_rate_sends_the_rate_and_no_brackets(): void
    {
        $loan = $this->approvedLoan();

        foreach ($this->terms($loan) as $document => $terms) {
            $this->assertArrayHasKey('penalty_brackets', $terms, $document);
            $this->assertArrayHasKey('penalty_config_name', $terms, $document);
            $this->assertNull($terms['penalty_brackets'], $document);
            $this->assertNull($terms['penalty_config_name'], $document);
            $this->assertEquals(3, $terms['penalty_rate'], $document);
            $this->assertSame(5, $terms['grace_period_days'], $document);
        }
    }
}
