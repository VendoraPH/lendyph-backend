<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\ApprovalWorkflowSetting;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\LoanService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /loans?view=summary: the list without every loan's whole schedule.
 *
 * Payment Entry downloaded every active loan, 32 MB raw at 2,000 loans. A
 * summary row carries what a list shows and no schedule, and each figure must
 * equal the full row's to the centavo.
 */
class LoanListSummaryViewTest extends TestCase
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

    public function test_a_row_carries_the_agreed_keys_and_no_schedule(): void
    {
        $loan = $this->mixedLoan();

        $row = $this->summaryRow($loan);

        foreach ([
            'id', 'application_number', 'loan_account_number', 'borrower', 'loan_product', 'branch',
            'principal_amount', 'term', 'frequency', 'status', 'display_status', 'current_status_label',
            'current_approver', 'released_at', 'next_due_date', 'next_due_amount', 'total_outstanding',
            'outstanding_principal', 'overdue_amount', 'amount_due_now', 'maturity_date', 'created_at', 'is_restructure',
        ] as $key) {
            $this->assertArrayHasKey($key, $row, "summary row is missing {$key}");
        }

        foreach (['amortization_schedules', 'amortization_schedule_totals', 'co_makers', 'created_by_user', 'deductions'] as $heavy) {
            $this->assertArrayNotHasKey($heavy, $row, "summary row still carries {$heavy}");
        }

        $this->assertSame(
            ['id' => $this->borrower->id, 'full_name' => $this->borrower->full_name, 'borrower_code' => $this->borrower->borrower_code],
            $row['borrower'],
        );
        $this->assertSame(['id' => $this->product->id, 'name' => $this->product->name], $row['loan_product']);
        $this->assertSame(['id' => $this->branch->id, 'name' => $this->branch->name], $row['branch']);
    }

    /**
     * The Loans list's Auto-Pay column reads `auto_pay_enabled`, so a summary
     * row carries it as the full row does: a loan with Auto-Pay on never reads
     * "Enable".
     */
    public function test_a_row_carries_auto_pay_enabled_as_the_full_row_does(): void
    {
        $on = $this->mixedLoan(['auto_pay' => true, 'cbs_reference' => 'CBS-1', 'auto_pay_enabled_at' => now()]);
        $off = $this->mixedLoan();

        $this->assertTrue($this->summaryRow($on)['auto_pay_enabled']);
        $this->assertFalse($this->summaryRow($off)['auto_pay_enabled']);
        $this->assertSame($this->fullRow($on)['auto_pay_enabled'], $this->summaryRow($on)['auto_pay_enabled']);
    }

    public function test_every_figure_equals_the_full_rows(): void
    {
        $loans = [
            $this->mixedLoan(),
            $this->mixedLoan(['insurance_remaining_balance' => 1234.56]),
            $this->loan(['status' => 'released']),
            $this->loan(['status' => 'draft']),
            $this->createReleasedLoan(['start_date' => now()->subMonths(3)->toDateString()]),
        ];

        foreach ($loans as $loan) {
            $full = $this->fullRow($loan);
            $summary = $this->summaryRow($loan);

            foreach ([
                'id', 'application_number', 'loan_account_number', 'principal_amount', 'term', 'frequency', 'status',
                'display_status', 'current_status_label', 'current_approver', 'released_at', 'next_due_date',
                'total_outstanding', 'outstanding_principal', 'overdue_amount', 'amount_due_now', 'current_due',
                'penalty_amount', 'total_payable', 'interest_rate', 'interest_method', 'maturity_date',
                'insurance_owed', 'borrower_id', 'borrower_name', 'loan_product_id', 'loan_product_name',
                'release_date', 'is_restructure', 'created_at',
            ] as $key) {
                $this->assertEquals($full[$key], $summary[$key], "{$key} differs on loan {$loan->id} ({$loan->status})");
            }

            $this->assertEquals($full['current_due'], $summary['next_due_amount']);
        }
    }

    public function test_a_closed_periods_leftover_still_counts_as_the_full_row_counts_it(): void
    {
        $loan = $this->loan(['status' => 'ongoing']);
        $this->period($loan, 1, [
            'status' => 'paid',
            'due_date' => today()->subMonths(2),
            'principal_due' => 1000,
            'principal_paid' => 1000,
            'interest_due' => 100,
            'interest_paid' => 100,
            'penalty_amount' => 75.25,
            'penalty_paid' => 0,
        ]);
        $this->period($loan, 2, ['due_date' => today()->addMonth(), 'principal_due' => 1000, 'interest_due' => 100]);

        $full = $this->fullRow($loan);
        $summary = $this->summaryRow($loan);

        $this->assertEquals(1175.25, $full['total_outstanding']);
        $this->assertEquals($full['total_outstanding'], $summary['total_outstanding']);
        $this->assertEquals($full['penalty_amount'], $summary['penalty_amount']);
    }

    public function test_a_late_loan_reads_past_due_with_its_next_due_figures(): void
    {
        $loan = $this->mixedLoan();

        $row = $this->summaryRow($loan);

        $this->assertSame('past_due', $row['display_status']);
        $this->assertSame('ongoing', $row['status']);
        $this->assertSame(today()->subDays(40)->toDateString(), $row['next_due_date']);
        $this->assertEquals(1120.50, $row['next_due_amount']);
        $this->assertEquals(1120.50, $row['overdue_amount']);
        $this->assertEquals(2600.50, $row['outstanding_principal']);
    }

    public function test_approval_fields_read_as_on_the_full_row(): void
    {
        ApprovalWorkflowSetting::updateOrCreate(
            ['type' => ApprovalWorkflowSetting::TYPE_NORMAL],
            ['steps' => [
                ['id' => 'processor', 'name' => 'Loan Processor', 'role' => 'loan_processor', 'kind' => 'submit'],
                ['id' => 'manager', 'name' => 'Manager', 'role' => 'manager', 'kind' => 'approve', 'status' => 'Credit Review'],
                ['id' => 'teller', 'name' => 'Teller', 'role' => 'cashier', 'kind' => 'release'],
            ]],
        );

        $service = app(LoanService::class);
        $loan = $service->createLoan([
            'borrower_id' => $this->borrower->id,
            'loan_product_id' => LoanProduct::factory()->create(['term' => 6, 'frequency' => 'monthly'])->id,
            'principal_amount' => 10000,
            'start_date' => now()->toDateString(),
        ], $this->admin);
        $service->submitForReview($loan, $this->admin);

        $row = $this->summaryRow($loan);

        $this->assertSame('Manager', $row['current_approver']);
        $this->assertSame('Credit Review', $row['current_status_label']);
        $this->assertSame('for_review', $row['display_status']);
    }

    public function test_meta_totals_and_stats_are_the_full_lists(): void
    {
        $this->mixedLoan();
        $this->mixedLoan(['insurance_remaining_balance' => 500]);
        $this->loan(['status' => 'draft']);

        $full = $this->getJson('/api/loans?status=active&per_page=1')->assertOk();
        $summary = $this->getJson('/api/loans?status=active&per_page=1&view=summary')->assertOk();

        $this->assertSame($full->json('meta.totals'), $summary->json('meta.totals'));
        $this->assertSame($full->json('meta.stats'), $summary->json('meta.stats'));
        $this->assertSame(2, $summary->json('meta.total'));
        $this->assertCount(1, $summary->json('data'));
        $this->assertNotNull($summary->json('links.next'));
    }

    public function test_it_pages_searches_and_sorts_like_the_full_list(): void
    {
        $other = Borrower::factory()->create(['branch_id' => $this->branch->id, 'first_name' => 'Zzyzx', 'last_name' => 'Quartermaine']);
        $found = $this->loan(['status' => 'ongoing', 'borrower_id' => $other->id]);
        $this->loan(['status' => 'ongoing']);

        $response = $this->getJson('/api/loans?view=summary&status=active&search=Quartermaine&sort=borrower&dir=asc')->assertOk();

        $this->assertSame([$found->id], array_column($response->json('data'), 'id'));
        $this->assertSame($other->id, $response->json('data.0.borrower.id'));
    }

    public function test_branch_scope_holds(): void
    {
        $far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        $mine = $this->loan(['status' => 'ongoing']);
        $this->loan(['status' => 'ongoing', 'branch_id' => $far->id]);

        $user = User::factory()->inBranches($this->branch)->create();
        $user->givePermissionTo(Permission::findByName('loans:view'));
        $this->actingAs($user);

        $response = $this->getJson('/api/loans?view=summary')->assertOk();

        $this->assertSame([$mine->id], array_column($response->json('data'), 'id'));
    }

    public function test_an_unknown_view_answers_422(): void
    {
        $this->getJson('/api/loans?view=everything')->assertStatus(422)->assertJsonValidationErrors('view');
    }

    public function test_without_the_parameter_a_row_is_still_the_full_row(): void
    {
        $row = $this->fullRow($this->mixedLoan());

        $this->assertArrayHasKey('amortization_schedules', $row);
        $this->assertArrayHasKey('co_makers', $row);
    }

    public function test_it_costs_a_fixed_number_of_queries_however_many_rows_it_returns(): void
    {
        $this->mixedLoan();
        $this->mixedLoan();

        $this->getJson('/api/loans?view=summary')->assertOk();
        $small = $this->queriesForAPage();

        for ($i = 0; $i < 8; $i++) {
            $this->mixedLoan();
        }

        $this->assertSame($small, $this->queriesForAPage(), 'view=summary is doing per-row work');
    }

    public function test_it_reads_only_the_open_periods(): void
    {
        $this->mixedLoan();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/loans?view=summary&per_page=100')->assertOk();
        $log = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $periodRows = $log->filter(fn (string $sql) => str_starts_with($sql, 'select * from `amortization_schedules`'));

        $this->assertCount(1, $periodRows);
        $this->assertStringContainsString('`status` in', $periodRows->first());
    }

    private function queriesForAPage(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/loans?view=summary&per_page=100')->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function fullRow(Loan $loan): array
    {
        $row = collect($this->getJson('/api/loans?per_page=100')->assertOk()->json('data'))->firstWhere('id', $loan->id);
        $this->assertNotNull($row);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryRow(Loan $loan): array
    {
        $row = collect($this->getJson('/api/loans?view=summary&per_page=100')->assertOk()->json('data'))->firstWhere('id', $loan->id);
        $this->assertNotNull($row);

        return $row;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function loan(array $attributes = []): Loan
    {
        return Loan::factory()->create(array_merge([
            'borrower_id' => $this->borrower->id,
            'loan_product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'created_by' => $this->admin->id,
            'grace_period_days' => 3,
            'released_at' => now()->subMonths(3),
            'status' => 'ongoing',
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function period(Loan $loan, int $number, array $attributes): AmortizationSchedule
    {
        return AmortizationSchedule::factory()->create(array_merge([
            'loan_id' => $loan->id,
            'period_number' => $number,
        ], $attributes));
    }

    /**
     * A paid period, a late part-paid one carrying penalty, and one still ahead.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function mixedLoan(array $attributes = []): Loan
    {
        $loan = $this->loan($attributes);

        $this->period($loan, 1, [
            'status' => 'paid',
            'due_date' => today()->subDays(70),
            'principal_due' => 1000,
            'principal_paid' => 1000,
            'interest_due' => 100,
            'interest_paid' => 100,
        ]);
        $this->period($loan, 2, [
            'status' => 'partial',
            'due_date' => today()->subDays(40),
            'principal_due' => 1000,
            'principal_paid' => 100.50,
            'interest_due' => 200,
            'interest_paid' => 0,
            'penalty_amount' => 21,
            'penalty_paid' => 0,
        ]);
        $this->period($loan, 3, [
            'status' => 'pending',
            'due_date' => today()->addDays(20),
            'principal_due' => 1701,
            'interest_due' => 100,
        ]);

        return $loan;
    }
}
