<?php

namespace Tests\Feature\Phase6\ExecutiveBooks;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\User;
use App\Services\Dashboard\ApplicationTurnaround;
use App\Services\Dashboard\LoanScope;
use App\Services\LoanService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 EB-D2, ask 5: application turnaround. Average and median days, to
 * one decimal, for submitted → approved, approved → released and submitted →
 * released. Each loan counts in the period its last step falls in. Submitted
 * is Loan::submittedAt(); a loan missing either end is `not_measured`, never
 * estimated.
 */
class ApplicationTurnaroundTest extends TestCase
{
    use SetupLendyPH;

    private const PERIOD = 'preset=custom&from=2026-11-01&to=2026-11-15';

    private LoanProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->product = LoanProduct::factory()->create(['interest_rate' => 3.0, 'interest_method' => 'straight', 'term' => 6, 'frequency' => 'monthly']);

        // 2.5 days to approve, 0.5 to release: 3.0 in all.
        $this->loan('2026-11-01 08:00', '2026-11-03 20:00', '2026-11-04 08:00');
        // 1.0 to approve, 7.0 to release: 8.0 in all.
        $this->loan('2026-11-02 08:00', '2026-11-03 08:00', '2026-11-10 08:00');
        // 4.0 to approve, not released yet.
        $this->loan('2026-11-05 08:00', '2026-11-09 08:00');
        // Approved before the period: it counts only where its release falls.
        $this->loan('2026-10-20 08:00', '2026-10-25 08:00', '2026-11-02 08:00');
    }

    private function loan(string $submitted, string $approved, ?string $released = null, ?Branch $branch = null, ?LoanProduct $product = null): Loan
    {
        $service = app(LoanService::class);
        $this->travelTo(Carbon::parse($submitted));
        $loan = $service->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => ($branch ?? $this->branch)->id])->id,
            'loan_product_id' => ($product ?? $this->product)->id,
            'principal_amount' => 10000,
            'start_date' => CarbonImmutable::parse($submitted)->toDateString(),
        ], $this->admin);
        $service->submitForReview($loan);

        $this->travelTo(Carbon::parse($approved));
        $service->approve($loan, $this->admin, 'Approved');

        if ($released !== null) {
            $this->travelTo(Carbon::parse($released));
            $service->release($loan, $this->admin);
        }

        $this->travelTo(Carbon::parse('2026-11-19 10:00:00'));

        return $loan->fresh();
    }

    /** @return array<string, mixed> */
    private function turnaround(string $query = ''): array
    {
        return $this->getJson('/api/dashboard/executive/turnaround?'.self::PERIOD.($query === '' ? '' : "&{$query}"))->assertOk()->json('data');
    }

    public function test_it_gives_average_and_median_days_for_each_stretch(): void
    {
        $data = $this->turnaround();

        $this->assertSame('2026-11-01', $data['period']['from']);
        $this->assertEquals(['loans' => 3, 'average_days' => 2.5, 'median_days' => 2.5, 'not_measured' => 0], $data['submitted_to_approved']);
        // 0.5, 7.0 and 8.0 (approved 25 Oct, released 2 Nov): average 5.17, median 7.0.
        $this->assertEquals(['loans' => 3, 'average_days' => 5.2, 'median_days' => 7.0, 'not_measured' => 0], $data['approved_to_released']);
        // 3.0, 8.0 and 13.0: average 8.0, median 8.0.
        $this->assertEquals(['loans' => 3, 'average_days' => 8.0, 'median_days' => 8.0, 'not_measured' => 0], $data['submitted_to_released']);
    }

    public function test_a_loan_missing_an_end_is_not_measured_never_estimated(): void
    {
        $imported = $this->loan('2026-11-06 08:00', '2026-11-07 08:00', '2026-11-08 08:00');
        DB::table('loan_approval_steps')->where('loan_id', $imported->id)->delete();
        DB::table('audit_logs')->where('auditable_type', Loan::class)->where('auditable_id', $imported->id)->delete();
        $legacy = $this->loan('2026-11-06 08:00', '2026-11-07 08:00', '2026-11-08 08:00');
        DB::table('loans')->where('id', $legacy->id)->update(['approved_at' => null]);

        $data = $this->turnaround();

        $this->assertSame(1, $data['submitted_to_approved']['not_measured'], 'No submit step and no audit row.');
        $this->assertSame(3, $data['submitted_to_approved']['loans'], 'The legacy loan has no approval date, so it is not in this stretch.');
        $this->assertSame(1, $data['approved_to_released']['not_measured']);
        $this->assertSame(4, $data['approved_to_released']['loans']);
        $this->assertSame(1, $data['submitted_to_released']['not_measured']);
        $this->assertSame(4, $data['submitted_to_released']['loans']);
    }

    public function test_submitted_is_the_loan_s_own_submitted_at(): void
    {
        $loan = $this->loan('2026-11-06 08:00', '2026-11-08 08:00');
        DB::table('loan_approval_steps')->where('loan_id', $loan->id)->delete();
        DB::table('audit_logs')->where('auditable_type', Loan::class)->where('auditable_id', $loan->id)
            ->where('action', 'updated')->where('new_values->status', 'for_review')->update(['created_at' => '2026-11-07 08:00:00']);

        $submittedAt = Loan::query()->with('approvalSteps')->findOrFail($loan->id)->submittedAt(true);
        $this->assertSame('2026-11-07 08:00:00', $submittedAt?->format('Y-m-d H:i:s'), 'It falls back to the audit row.');

        $scope = ['date_from' => '2026-11-08', 'date_to' => '2026-11-08'];
        $data = app(ApplicationTurnaround::class)->build($scope['date_from'], $scope['date_to'], null, LoanScope::none());

        $this->assertEquals(['loans' => 1, 'average_days' => 1.0, 'median_days' => 1.0, 'not_measured' => 0], $data['submitted_to_approved']);
    }

    public function test_branch_product_and_officer_narrow_it(): void
    {
        $other = Branch::factory()->create();
        $elsewhere = $this->loan('2026-11-06 08:00', '2026-11-16 08:00', null, $other);
        $this->loan('2026-11-06 08:00', '2026-11-07 08:00', null, null, $product = LoanProduct::factory()->create(['interest_rate' => 3.0, 'interest_method' => 'straight', 'term' => 6, 'frequency' => 'monthly']));
        $officer = User::factory()->inBranches($this->branch)->create();
        DB::table('loans')->where('loan_product_id', $product->id)->update(['account_officer_id' => $officer->id]);
        DB::table('loans')->where('id', $elsewhere->id)->update(['approved_at' => '2026-11-10 08:00:00']);

        $this->assertSame(5, $this->turnaround()['submitted_to_approved']['loans']);
        $this->assertSame(4, $this->turnaround("branch_id={$this->branch->id}")['submitted_to_approved']['loans']);

        $byProduct = $this->turnaround("product_id={$product->id}");
        $this->assertEquals(['loans' => 1, 'average_days' => 1.0, 'median_days' => 1.0, 'not_measured' => 0], $byProduct['submitted_to_approved']);
        $this->assertSame(['product_id'], $byProduct['filters_applied']);
        $this->assertSame(1, $this->turnaround("officer_id={$officer->id}")['submitted_to_approved']['loans']);
        $this->assertSame([], $this->turnaround('collector_id='.$officer->id)['filters_applied'], 'The collector is not a loan filter.');

        $role = Role::create(['name' => 'ebd2_turnaround', 'guard_name' => 'web']);
        $role->givePermissionTo(['dashboard:analytics', 'reports:view']);
        $user = User::factory()->inBranches($other)->create();
        $user->assignRole($role);
        $this->actingAs($user);

        $this->assertSame(1, $this->turnaround()['submitted_to_approved']['loans']);
        $this->getJson('/api/dashboard/executive/turnaround?'.self::PERIOD."&branch_id={$this->branch->id}")->assertForbidden();
    }

    public function test_an_empty_stretch_reads_null_not_zero(): void
    {
        $data = $this->getJson('/api/dashboard/executive/turnaround?preset=custom&from=2026-01-01&to=2026-01-31')->assertOk()->json('data');

        $this->assertEquals(['loans' => 0, 'average_days' => null, 'median_days' => null, 'not_measured' => 0], $data['submitted_to_released']);
    }

    public function test_it_needs_the_dashboard_and_reports(): void
    {
        foreach ([['reports:view'], ['dashboard:analytics']] as $index => $permissions) {
            $role = Role::create(['name' => "ebd2_turnaround_{$index}", 'guard_name' => 'web']);
            $role->givePermissionTo($permissions);
            $user = User::factory()->inBranches($this->branch)->create();
            $user->assignRole($role);
            $this->actingAs($user);

            $this->getJson('/api/dashboard/executive/turnaround?'.self::PERIOD)->assertForbidden();
        }
    }

    public function test_a_bad_period_is_refused_in_words(): void
    {
        $this->getJson('/api/dashboard/executive/turnaround?preset=custom&from=2026-11-10&to=2026-11-01')
            ->assertUnprocessable()
            ->assertJsonPath('errors.preset.0', 'The end date can\'t be before the start date.');
    }
}
