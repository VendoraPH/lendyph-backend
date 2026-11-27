<?php

namespace Tests\Feature\Accounting\Allowance;

use App\Models\Branch;
use App\Services\Accounting\AllowanceCalculator;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The allowance base (decision 12, Lead's call 7): each past-due loan's
 * outstanding principal, in the aging bucket of its oldest unpaid instalment
 * (LoanAgingBuilder, no grace), at the provisioning rates, rounded half up to
 * the centavo once per bucket.
 */
class AllowanceCalculatorTest extends TestCase
{
    use AllowanceTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /** @return array<string, mixed> */
    private function base(): array
    {
        return app(AllowanceCalculator::class)->forDate($this->today());
    }

    /** @return array<string, array<string, mixed>> one branch's buckets, keyed by bucket */
    private function bucketsOf(array $base, Branch $branch): array
    {
        $row = collect($base['branches'])->firstWhere('branch.id', $branch->id);
        $this->assertNotNull($row, "Branch {$branch->name} is missing.");

        return collect($row['buckets'])->keyBy('bucket')->all();
    }

    public function test_a_loan_ten_days_late_provides_5_percent_of_its_whole_outstanding_principal(): void
    {
        // ₱4,000.00 late and ₱6,000.00 not yet due: the base is all ₱10,000.00.
        $loan = $this->agedLoan();
        $this->period($loan, 10, principal: 4000, interest: 300);
        $this->period($loan, -20, principal: 6000, interest: 300);

        $buckets = $this->bucketsOf($this->base(), $this->branch);

        $this->assertSame(
            ['bucket' => '1_30', 'loan_count' => 1, 'principal' => 1_000_000, 'rate_percent' => '5', 'required' => 50_000],
            $buckets['1_30'],
        );
        $this->assertSame(['1_30', '31_60', '61_90', '91_120', 'over_120'], array_keys($buckets));
    }

    public function test_a_loan_one_hundred_days_late_is_in_91_120_at_50_percent(): void
    {
        $loan = $this->agedLoan();
        $this->period($loan, 100, principal: 1500);
        $this->period($loan, 70, principal: 500);

        $buckets = $this->bucketsOf($this->base(), $this->branch);

        $this->assertSame(
            ['bucket' => '91_120', 'loan_count' => 1, 'principal' => 200_000, 'rate_percent' => '50', 'required' => 100_000],
            $buckets['91_120'],
        );
        $this->assertSame(0, $buckets['61_90']['loan_count'], 'a loan sits only in the bucket of its oldest unpaid instalment');
        $this->assertSame('50', $buckets['over_120']['rate_percent']);
    }

    public function test_a_current_loan_is_left_out(): void
    {
        $loan = $this->agedLoan();
        $this->period($loan, -5, principal: 9000);
        // Late, but fully paid: no longer unpaid, so it doesn't age the loan.
        $this->period($loan, 40, principal: 1000, status: 'paid', principalPaid: 1000);

        $base = $this->base();

        $this->assertSame(['loan_count' => 0, 'principal' => 0, 'required' => 0], $base['totals']);
    }

    public function test_each_bucket_is_rounded_half_up_to_the_centavo_once(): void
    {
        // ₱333.33 × 15% = ₱49.9995, which reads ₱50.00.
        $this->period($this->agedLoan(), 45, principal: 333.33);
        // ₱10.10 × 5% = 50.5 centavos exactly: half up is 51.
        $this->period($this->agedLoan(), 5, principal: 10.10);
        // ₱3.30 × 25% = 82.5 centavos exactly: half up is 83.
        $this->period($this->agedLoan(), 75, principal: 3.30);

        $buckets = $this->bucketsOf($this->base(), $this->branch);

        $this->assertSame(5_000, $buckets['31_60']['required']);
        $this->assertSame(51, $buckets['1_30']['required']);
        $this->assertSame(83, $buckets['61_90']['required']);
    }

    public function test_large_amounts_are_exact_with_integer_arithmetic_and_no_bcmath(): void
    {
        // ₱9,999,999,999.99 × 15% = 149,999,999,999.85 centavos; × 5% = 49,999,999,999.95.
        $this->assertSame(150_000_000_000, AllowanceCalculator::requiredFor(999_999_999_999, '31_60'));
        $this->assertSame(50_000_000_000, AllowanceCalculator::requiredFor(999_999_999_999, '1_30'));
        $this->assertSame(250_000_000_000, AllowanceCalculator::requiredFor(999_999_999_999, '61_90'));
        $this->assertSame(500_000_000_000, AllowanceCalculator::requiredFor(999_999_999_999, 'over_120'));
        $this->assertSame(0, AllowanceCalculator::requiredFor(0, '1_30'));

        // The servers may not have the BCMath extension.
        $this->assertDoesNotMatchRegularExpression(
            '/\bbc[a-z]+\s*\(/',
            (string) file_get_contents(app_path('Services/Accounting/AllowanceCalculator.php')),
        );
    }

    public function test_a_rate_with_many_decimals_never_throws_and_stays_exact(): void
    {
        // A third, to fifteen decimals: the old guard refused anything over
        // ₱138.33 at this rate. Expected values are exact, worked out with
        // fractions and rounded half up.
        $third = '0.333333333333333';
        $this->assertSame(4_611, AllowanceCalculator::requiredAt(13_834, $third));
        $this->assertSame(333_333_333_333, AllowanceCalculator::requiredAt(999_999_999_999, $third));
        $this->assertSame(123_456_789_012, AllowanceCalculator::requiredAt(999_999_999_999, '0.123456789012345'));
        $this->assertSame(7, AllowanceCalculator::requiredAt(7, '0.999999999999999'));
        $this->assertSame(0, AllowanceCalculator::requiredAt(500, '0.000000000000001'));

        // Halves still round up, at any size.
        $this->assertSame(500_000_000_000, AllowanceCalculator::requiredAt(999_999_999_999, '0.5'));
        $this->assertSame(2, AllowanceCalculator::requiredAt(3, '0.5'));
        $this->assertSame(1_543, AllowanceCalculator::requiredAt(12_345, '0.125'));
        $this->assertSame(999_999_999_999, AllowanceCalculator::requiredAt(999_999_999_999, '1'));
        $this->assertSame(0, AllowanceCalculator::requiredAt(0, $third));

        // The bucket rates go through the same arithmetic.
        $this->assertSame(AllowanceCalculator::requiredAt(999_999_999_999, '0.15'), AllowanceCalculator::requiredFor(999_999_999_999, '31_60'));
    }

    public function test_the_bucket_is_rounded_not_each_loan(): void
    {
        // Two loans of ₱0.10 at 5%: 0.5 centavo each. Rounded per loan that
        // is 2 centavos; on the bucket's ₱0.20 it is exactly 1.
        $this->period($this->agedLoan(), 3, principal: 0.10);
        $this->period($this->agedLoan(), 4, principal: 0.10);

        $bucket = $this->bucketsOf($this->base(), $this->branch)['1_30'];

        $this->assertSame(2, $bucket['loan_count']);
        $this->assertSame(20, $bucket['principal']);
        $this->assertSame(1, $bucket['required']);
    }

    public function test_loans_are_grouped_by_branch_and_every_active_branch_is_listed(): void
    {
        $far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR', 'is_active' => true]);
        $empty = Branch::factory()->create(['name' => 'Quiet Branch', 'code' => 'QUI', 'is_active' => true]);
        $closed = Branch::factory()->create(['name' => 'Closed Branch', 'code' => 'CLO', 'is_active' => false]);

        $this->period($this->agedLoan(), 20, principal: 1000);
        $this->period($this->agedLoan(['branch_id' => $far->id]), 65, principal: 2000);
        $this->period($this->agedLoan(['branch_id' => $far->id]), 130, principal: 3000);

        $base = $this->base();
        $listed = collect($base['branches'])->pluck('branch.id')->all();

        $this->assertSame(
            Branch::query()->where('is_active', true)->orderBy('id')->pluck('id')->all(),
            $listed,
            'every active branch, in id order, and no inactive branch without figures',
        );
        $this->assertNotContains($closed->id, $listed);

        $main = collect($base['branches'])->firstWhere('branch.id', $this->branch->id);
        $this->assertSame(['id' => $this->branch->id, 'name' => $this->branch->name], $main['branch']);
        $this->assertSame([100_000, 5_000], [$main['principal'], $main['required']]);

        $farRow = collect($base['branches'])->firstWhere('branch.id', $far->id);
        $farBuckets = collect($farRow['buckets'])->keyBy('bucket');
        $this->assertSame(50_000, $farBuckets['61_90']['required']);
        $this->assertSame(150_000, $farBuckets['over_120']['required']);
        $this->assertSame([500_000, 200_000], [$farRow['principal'], $farRow['required']]);

        $quiet = collect($base['branches'])->firstWhere('branch.id', $empty->id);
        $this->assertSame([0, 0], [$quiet['principal'], $quiet['required']]);
        $this->assertSame([0, 0, 0, 0, 0], array_column($quiet['buckets'], 'loan_count'));

        $this->assertSame(['loan_count' => 3, 'principal' => 600_000, 'required' => 205_000], $base['totals']);
    }

    public function test_an_inactive_branch_with_past_due_loans_is_listed_so_the_rows_add_up(): void
    {
        $closed = Branch::factory()->create(['name' => 'Closed Branch', 'code' => 'CLO', 'is_active' => false]);
        $this->period($this->agedLoan(['branch_id' => $closed->id]), 20, principal: 1000);

        $base = $this->base();

        $this->assertContains($closed->id, collect($base['branches'])->pluck('branch.id')->all());
        $this->assertSame(5_000, $base['totals']['required']);
    }

    public function test_the_rates_come_from_the_provisioning_constant(): void
    {
        $this->assertSame([
            ['bucket' => '1_30', 'label' => '1–30 days', 'rate_percent' => '5'],
            ['bucket' => '31_60', 'label' => '31–60 days', 'rate_percent' => '15'],
            ['bucket' => '61_90', 'label' => '61–90 days', 'rate_percent' => '25'],
            ['bucket' => '91_120', 'label' => '91–120 days', 'rate_percent' => '50'],
            ['bucket' => 'over_120', 'label' => 'Over 120 days', 'rate_percent' => '50'],
        ], app(AllowanceCalculator::class)->rates());
    }
}
