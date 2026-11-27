<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * `display_status`, and the Current tab that follows it.
 *
 * A loan past grace read a green "Current", even in the Past Due tab, because
 * `released` and `ongoing` both read "Current" and past due is not a status.
 * `display_status` says past_due by the rule Loan::scopePastDue() filters on;
 * `loans.status` never changes.
 */
class LoanDisplayStatusTest extends TestCase
{
    use SetupLendyPH;

    private Borrower $borrower;

    private LoanProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $this->product = LoanProduct::factory()->create();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function liveStatuses(): array
    {
        return ['released' => ['released'], 'ongoing' => ['ongoing']];
    }

    #[DataProvider('liveStatuses')]
    public function test_a_live_loan_past_grace_displays_past_due_and_keeps_its_status(string $status): void
    {
        $loan = $this->loanWithPeriod($status, today()->subDays(4), grace: 3);

        $row = $this->row($loan);

        $this->assertSame('past_due', $row['display_status']);
        $this->assertSame($status, $row['status']);
        $this->assertSame($status, $loan->fresh()->status);
        $this->assertSame('past_due', $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data.display_status'));
    }

    #[DataProvider('liveStatuses')]
    public function test_a_period_inside_grace_or_not_yet_due_stays_on_the_status(string $status): void
    {
        $insideGrace = $this->loanWithPeriod($status, today()->subDays(3), grace: 3);
        $dueToday = $this->loanWithPeriod($status, today(), grace: 0);
        $notDue = $this->loanWithPeriod($status, today()->addDays(5), grace: 0);

        foreach ([$insideGrace, $dueToday, $notDue] as $loan) {
            $this->assertSame($status, $this->row($loan)['display_status']);
        }
    }

    public function test_a_paid_period_past_grace_does_not_make_a_loan_past_due(): void
    {
        $loan = $this->loanWithPeriod('ongoing', today()->subDays(30), grace: 0, period: ['status' => 'paid']);

        $this->assertSame('ongoing', $this->row($loan)['display_status']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function otherStatuses(): array
    {
        return [
            'draft' => ['draft'],
            'for_review' => ['for_review'],
            'approved' => ['approved'],
            'rejected' => ['rejected'],
            'completed' => ['completed'],
            'void' => ['void'],
            'restructured' => ['restructured'],
            'defaulted' => ['defaulted'],
        ];
    }

    #[DataProvider('otherStatuses')]
    public function test_any_other_status_is_shown_as_it_is_even_with_a_late_period(string $status): void
    {
        $loan = $this->loanWithPeriod($status, today()->subDays(30), grace: 0);

        $this->assertSame($status, $this->row($loan)['display_status']);
    }

    public function test_a_loan_read_without_its_periods_still_answers_correctly(): void
    {
        $late = $this->loanWithPeriod('ongoing', today()->subDays(30), grace: 0);
        $onTime = $this->loanWithPeriod('ongoing', today()->addDays(5), grace: 0);

        $this->assertSame('past_due', Loan::find($late->id)->displayStatus());
        $this->assertSame('ongoing', Loan::find($onTime->id)->displayStatus());
    }

    public function test_display_status_never_disagrees_with_the_past_due_tab(): void
    {
        $this->loanWithPeriod('ongoing', today()->subDays(30), grace: 0);
        $this->loanWithPeriod('released', today()->subDays(10), grace: 3);
        $this->loanWithPeriod('released', today()->subDays(2), grace: 3);
        $this->loanWithPeriod('ongoing', today()->addDays(3), grace: 0);
        $this->loanWithPeriod('ongoing', today()->subDays(30), grace: 0, period: ['status' => 'paid']);

        $rows = collect($this->getJson('/api/loans?status=active&per_page=100')->assertOk()->json('data'));
        $displayed = $rows->where('display_status', 'past_due')->pluck('id')->sort()->values()->all();

        $pastDueTab = collect($this->getJson('/api/loans?status=past_due&per_page=100')->assertOk()->json('data'))
            ->pluck('id')->sort()->values()->all();

        $this->assertCount(2, $displayed);
        $this->assertSame($pastDueTab, $displayed);
    }

    public function test_status_current_leaves_out_past_due_loans_and_counts_the_same(): void
    {
        $late = $this->loanWithPeriod('ongoing', today()->subDays(30), grace: 0);
        $lateReleased = $this->loanWithPeriod('released', today()->subDays(30), grace: 0);
        $ongoing = $this->loanWithPeriod('ongoing', today()->addDays(5), grace: 0);
        $released = $this->loanWithPeriod('released', today()->addDays(5), grace: 0);
        $paid = $this->loanWithPeriod('ongoing', today()->subDays(30), grace: 0, period: ['status' => 'paid']);
        $noPeriods = $this->loan('released');
        $this->loanWithPeriod('defaulted', today()->addDays(5), grace: 0);
        $this->loan('draft');
        $this->loan('completed');

        $current = $this->getJson('/api/loans?status=current&per_page=100')->assertOk();

        $this->assertEqualsCanonicalizing(
            [$ongoing->id, $released->id, $paid->id, $noPeriods->id],
            array_column($current->json('data'), 'id'),
        );
        $this->assertSame(4, $current->json('meta.total'));
        $this->assertSame(4, $current->json('meta.stats.current'));

        $this->assertNotContains($late->id, array_column($current->json('data'), 'id'));
        $this->assertNotContains($lateReleased->id, array_column($current->json('data'), 'id'));
    }

    public function test_status_active_and_past_due_are_unchanged(): void
    {
        $late = $this->loanWithPeriod('ongoing', today()->subDays(30), grace: 0);
        $onTime = $this->loanWithPeriod('released', today()->addDays(5), grace: 0);
        $defaultedLate = $this->loanWithPeriod('defaulted', today()->subDays(30), grace: 0);

        $active = $this->getJson('/api/loans?status=active&per_page=100')->assertOk();
        $pastDue = $this->getJson('/api/loans?status=past_due&per_page=100')->assertOk();

        $this->assertEqualsCanonicalizing([$late->id, $onTime->id], array_column($active->json('data'), 'id'));
        $this->assertEqualsCanonicalizing([$late->id, $defaultedLate->id], array_column($pastDue->json('data'), 'id'));
        $this->assertSame(2, $active->json('meta.stats.active'));
        $this->assertSame(2, $active->json('meta.stats.past_due'));
    }

    public function test_status_current_mixes_with_other_values_as_an_or(): void
    {
        $this->loanWithPeriod('ongoing', today()->subDays(30), grace: 0);
        $onTime = $this->loanWithPeriod('ongoing', today()->addDays(5), grace: 0);
        $completed = $this->loan('completed');
        $this->loan('draft');

        $rows = $this->getJson('/api/loans?status=current,completed&per_page=100')->assertOk();

        $this->assertEqualsCanonicalizing([$onTime->id, $completed->id], array_column($rows->json('data'), 'id'));
    }

    public function test_the_current_tab_totals_cover_only_its_own_loans(): void
    {
        $this->loanWithPeriod('ongoing', today()->subDays(30), grace: 0);
        $this->loanWithPeriod('ongoing', today()->addDays(5), grace: 0);

        $totals = $this->getJson('/api/loans?status=current')->assertOk()->json('meta.totals');

        $this->assertSame(0, $totals['overdue_count']);
        $this->assertEquals(0, $totals['overdue_amount']);
        $this->assertEquals(9583.33, $totals['total_outstanding']);
    }

    public function test_display_status_costs_no_query_per_row(): void
    {
        $this->loanWithPeriod('ongoing', today()->subDays(30), grace: 0);
        $this->loanWithPeriod('released', today()->addDays(5), grace: 0);

        $this->getJson('/api/loans')->assertOk();
        $small = $this->queriesForAPage();

        for ($i = 0; $i < 8; $i++) {
            $this->loanWithPeriod($i % 2 === 0 ? 'ongoing' : 'released', today()->subDays(30), grace: 0);
        }

        $this->assertSame($small, $this->queriesForAPage(), 'display_status is doing per-row work');
    }

    private function queriesForAPage(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/loans?per_page=100')->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Loan $loan): array
    {
        $row = collect($this->getJson('/api/loans?per_page=100')->assertOk()->json('data'))->firstWhere('id', $loan->id);

        $this->assertNotNull($row, "loan {$loan->id} is not on the list");
        $this->assertArrayHasKey('display_status', $row);

        return $row;
    }

    private function loan(string $status, int $grace = 0): Loan
    {
        return Loan::factory()->create([
            'status' => $status,
            'grace_period_days' => $grace,
            'borrower_id' => $this->borrower->id,
            'loan_product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $period
     */
    private function loanWithPeriod(string $status, mixed $dueDate, int $grace, array $period = []): Loan
    {
        $loan = $this->loan($status, $grace);

        AmortizationSchedule::factory()->create(array_merge([
            'loan_id' => $loan->id,
            'period_number' => 1,
            'due_date' => $dueDate,
        ], $period));

        return $loan;
    }
}
