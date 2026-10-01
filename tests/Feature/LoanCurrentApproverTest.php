<?php

namespace Tests\Feature;

use App\Models\ApprovalWorkflowSetting;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanApprovalStep;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\LoanService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * `current_approver` on GET /loans and GET /loans/{id}.
 *
 * The loans list labels a loan under review "For Approval - Manager", and it
 * cannot call GET /loans/{id}/approval-steps once per row to find out who
 * "Manager" is. So each loan row carries the name of the step its chain is
 * waiting on — the same step the detail page picks out of approval-steps —
 * and null whenever there is no such step to name.
 */
class LoanCurrentApproverTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        ApprovalWorkflowSetting::updateOrCreate(
            ['type' => ApprovalWorkflowSetting::TYPE_NORMAL],
            ['steps' => [
                ['id' => 'processor', 'name' => 'Loan Processor', 'role' => 'loan_processor', 'kind' => 'submit'],
                ['id' => 'manager', 'name' => 'Manager', 'role' => 'manager', 'kind' => 'approve'],
                ['id' => 'bod', 'name' => 'BOD Chairman', 'role' => 'bod1', 'kind' => 'approve'],
                ['id' => 'teller', 'name' => 'Teller', 'role' => 'cashier', 'kind' => 'release'],
            ]],
        );
    }

    public function test_a_loan_under_review_names_the_step_it_is_waiting_on(): void
    {
        $loan = $this->submittedLoan();

        $this->assertSame('Manager', $this->listedApprover($loan));
        $this->assertSame('Manager', $this->detailApprover($loan));
    }

    public function test_a_loan_outside_review_reports_null(): void
    {
        $draft = $this->draftLoan();

        // Approved with its release step still pending: the null has to come
        // from the status, not from the chain having nothing pending.
        $approved = $this->submittedLoan();
        $this->approveAs('manager', $approved);
        $this->approveAs('bod1', $approved);
        $this->assertSame('approved', $approved->fresh()->status);
        $this->assertSame('Teller', $this->pendingStep($approved)->name);

        $released = $this->createReleasedLoan();

        foreach ([$draft, $approved, $released] as $loan) {
            $this->assertNull($this->listedApprover($loan), "{$loan->status} loan on the list");
            $this->assertNull($this->detailApprover($loan), "{$loan->status} loan on the detail");
        }
    }

    public function test_a_loan_under_review_with_no_pending_step_reports_null(): void
    {
        // No chain at all: a loan put into review before chains existed.
        $unchained = $this->draftLoan();
        Loan::whereKey($unchained->id)->update(['status' => 'for_review']);

        // A chain, but nothing in it pending.
        $stalled = $this->submittedLoan();
        $stalled->approvalSteps()
            ->where('status', LoanApprovalStep::STATUS_PENDING)
            ->update(['status' => LoanApprovalStep::STATUS_WAITING]);

        foreach ([$unchained, $stalled] as $loan) {
            $this->assertSame('for_review', $loan->fresh()->status);
            $this->assertNull($this->listedApprover($loan));
            $this->assertNull($this->detailApprover($loan));
        }
    }

    public function test_a_blank_step_name_reports_null_never_an_empty_string(): void
    {
        $loan = $this->submittedLoan();

        foreach (['', '   '] as $blank) {
            $this->pendingStep($loan)->update(['name' => $blank]);

            $this->assertNull($this->listedApprover($loan), "step named '{$blank}' on the list");
            $this->assertNull($this->detailApprover($loan), "step named '{$blank}' on the detail");
        }
    }

    public function test_list_and_detail_agree_with_approval_steps_across_rounds(): void
    {
        $loan = $this->submittedLoan();
        $this->assertAgreesWithApprovalSteps($loan, 'Manager');

        $this->approveAs('manager', $loan);
        $this->assertAgreesWithApprovalSteps($loan, 'BOD Chairman');

        // Round 1 is frozen with no pending step and round 2 reopens Manager.
        // The answer has to come from the latest round only.
        $bod = $this->pendingStep($loan);
        $this->actingAs($this->userWithRole('bod1'))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$bod->id}/send-back", [
                'target_step_order' => 1,
                'remarks' => 'Collateral appraisal is out of date.',
            ])
            ->assertOk();

        $this->actingAs($this->admin);
        $this->assertAgreesWithApprovalSteps($loan, 'Manager');

        $this->approveAs('manager', $loan);
        $this->assertAgreesWithApprovalSteps($loan, 'BOD Chairman');
    }

    public function test_the_list_costs_a_fixed_number_of_queries_however_many_loans_are_under_review(): void
    {
        $this->submittedLoan();
        $this->submittedLoan();
        $this->createReleasedLoan();

        // Warm-up: the first authorized request also resolves the permission
        // tables, which Spatie then keeps in memory.
        $this->getJson('/api/loans')->assertOk();

        $small = $this->countQueriesForOnePage();

        for ($i = 0; $i < 6; $i++) {
            $this->submittedLoan();
        }

        $rows = collect($this->getJson('/api/loans?per_page=100')->assertOk()->json('data'))
            ->where('status', 'for_review');
        $this->assertCount(8, $rows);
        $this->assertSame(['Manager'], $rows->pluck('current_approver')->unique()->values()->all());

        $this->assertSame($small, $this->countQueriesForOnePage(), 'current_approver is doing per-row work');
    }

    private function assertAgreesWithApprovalSteps(Loan $loan, string $expected): void
    {
        $fromChain = collect($this->getJson("/api/loans/{$loan->id}/approval-steps")->assertOk()->json('data.current_steps'))
            ->firstWhere('status', LoanApprovalStep::STATUS_PENDING)['name'] ?? null;

        $this->assertSame($expected, $fromChain);
        $this->assertSame($fromChain, $this->listedApprover($loan), 'list disagrees with approval-steps');
        $this->assertSame($fromChain, $this->detailApprover($loan), 'detail disagrees with approval-steps');
    }

    /**
     * The loan's row on GET /loans. Asserts the key is present: null is an
     * answer, a missing key would mean "unknown" to the frontend.
     */
    private function listedApprover(Loan $loan): ?string
    {
        $row = collect($this->getJson('/api/loans?per_page=100')->assertOk()->json('data'))
            ->firstWhere('id', $loan->id);

        $this->assertNotNull($row, "loan {$loan->id} is not on the list");
        $this->assertArrayHasKey('current_approver', $row);

        return $row['current_approver'];
    }

    private function detailApprover(Loan $loan): ?string
    {
        $data = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data');

        $this->assertArrayHasKey('current_approver', $data);

        return $data['current_approver'];
    }

    private function countQueriesForOnePage(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/loans?per_page=100')->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return count($queries);
    }

    private function draftLoan(): Loan
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
        ]);

        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        return app(LoanService::class)->createLoan([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
        ], $this->admin);
    }

    private function submittedLoan(): Loan
    {
        $loan = $this->draftLoan();

        app(LoanService::class)->submitForReview($loan, $this->admin);

        return $loan->fresh();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole(Role::where('name', $role)->firstOrFail());

        return $user;
    }

    private function pendingStep(Loan $loan): LoanApprovalStep
    {
        return $loan->approvalSteps()
            ->where('status', LoanApprovalStep::STATUS_PENDING)
            ->orderByDesc('round')
            ->firstOrFail();
    }

    private function approveAs(string $role, Loan $loan): void
    {
        $step = $this->pendingStep($loan);

        $this->actingAs($this->userWithRole($role))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$step->id}/approve")
            ->assertOk();

        $this->actingAs($this->admin);
    }
}
