<?php

namespace Tests\Feature\LoanApprovalEdit;

use App\Models\ApprovalWorkflowSetting;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanApprovalStep;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\LoanService;
use Illuminate\Support\Arr;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The Chairman/Chairwoman edits a loan application while it waits at their
 * approval step (finish run round 2, decision 14; ClickUp 14zfbg0e89e):
 * PATCH /loans/{loan}/approval-edit, its preview and its state.
 *
 * Only the holder of `loans:edit_in_approval` (the Chair's role, `bod1`), at
 * that role's pending step. The product's limits hold, the schedule is the
 * loan form preview's, every change is audited with its old and new value,
 * and an edit made from a stale version answers 409. The loan stays at the
 * step for the Chair to approve or forward.
 */
class ChairEditInApprovalTest extends TestCase
{
    use SetupLendyPH;

    private const CHAIN = [
        ['id' => 'loan-processor', 'name' => 'Loan Processor', 'role' => 'loan_processor', 'kind' => 'submit'],
        ['id' => 'manager', 'name' => 'Manager', 'role' => 'manager', 'kind' => 'approve'],
        ['id' => 'chairwoman', 'name' => 'BOD Chairwoman', 'role' => 'bod1', 'kind' => 'approve'],
        ['id' => 'general-bookkeeper', 'name' => 'General Bookkeeper', 'role' => 'general_bookkeeper', 'kind' => 'release'],
    ];

    /** What the Chair changes in most tests. */
    private const EDIT = [
        'principal_amount' => 80000,
        'interest_rate' => 2.5,
        'term' => 12,
        'frequency' => 'semi_monthly',
        'start_date' => '2026-12-05',
        'purpose' => 'Store expansion',
    ];

    private LoanProduct $product;

    private User $manager;

    private User $chair;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        ApprovalWorkflowSetting::updateOrCreate(['type' => ApprovalWorkflowSetting::TYPE_NORMAL], ['steps' => self::CHAIN]);

        $this->product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'min_interest_rate' => 1.5,
            'interest_method' => 'straight',
            'term' => 6,
            'min_term' => 3,
            'max_term' => 12,
            'frequency' => 'monthly',
            'frequencies' => ['monthly', 'semi_monthly'],
            'min_amount' => 5000,
            'max_amount' => 100000,
            'processing_fee' => 2.0,
            'min_processing_fee' => 1.0,
            'max_processing_fee' => 2.0,
            'service_fee' => 1.0,
            'max_service_fee' => 1.0,
        ]);
        $this->manager = $this->staff('manager');
        $this->chair = $this->staff('bod1', ['first_name' => 'Celia', 'last_name' => 'Santos']);
    }

    // -----------------------------------------------------------------
    // The edit
    // -----------------------------------------------------------------

    public function test_the_chair_edits_the_loan_at_their_step_and_it_waits_there_for_their_sign_off(): void
    {
        $loan = $this->loanAtChairStep();
        $step = $this->pendingStep($loan);

        $response = $this->editAs($this->chair, $loan, self::EDIT)->assertOk();

        $this->assertSame('Loan updated. It is still at the "BOD Chairwoman" step for you to approve or forward.', $response->json('message'));
        $fresh = $loan->fresh();
        $this->assertSame('for_review', $fresh->status);
        $this->assertSame('80000.00', $fresh->getRawOriginal('principal_amount'));
        $this->assertSame('2.5000', $fresh->getRawOriginal('interest_rate'));
        $this->assertSame(12, (int) $fresh->term);
        $this->assertSame('semi_monthly', $fresh->frequency);
        $this->assertSame('2026-12-05', $fresh->start_date->toDateString());
        $this->assertSame('Store expansion', $fresh->purpose);
        $this->assertSame(
            app(LoanService::class)->maturityDateFor('2026-12-05', 12, 'months', 'semi_monthly')->toDateString(),
            $fresh->maturity_date->toDateString(),
        );
        // The loan's own fees, worked out again on the new principal.
        $this->assertSame([1600.0, 800.0], array_map(fn (array $item): float => (float) $item['amount'], $fresh->deductions));
        $this->assertSame('2400.00', $fresh->getRawOriginal('total_deductions'));
        $this->assertSame('77600.00', $fresh->getRawOriginal('net_proceeds'));

        // Still at the same step, which the Chair then signs off.
        $this->assertSame($step->id, $this->pendingStep($loan)->id);
        $this->actingAs($this->chair)
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$step->id}/approve", ['step_id' => $step->id])
            ->assertOk();
        $this->assertSame('approved', $loan->fresh()->status);
    }

    public function test_the_saved_schedule_and_deductions_equal_the_loan_form_preview(): void
    {
        $loan = $this->loanAtChairStep();

        $chairPreview = $this->actingAs($this->chair)
            ->postJson("/api/loans/{$loan->id}/approval-edit/preview", self::EDIT)
            ->assertOk()
            ->json('data');
        $formPreview = $this->actingAs($this->admin)
            ->postJson('/api/loans/preview', [...self::EDIT, 'loan_id' => $loan->id, 'loan_product_id' => $this->product->id])
            ->assertOk()
            ->json('data');

        $saved = $this->editAs($this->chair, $loan, self::EDIT)->assertOk();
        $stored = $this->actingAs($this->admin)->getJson("/api/loans/{$loan->id}/amortization-preview")->assertOk();

        $keys = ['period_number', 'due_date', 'principal_due', 'interest_due', 'total_due', 'share_capital_build_up', 'total_payment', 'remaining_balance'];
        $rows = fn (array $rows): array => array_map(
            fn (array $row): array => array_combine($keys, array_map(fn (string $key): mixed => $row[$key] ?? null, $keys)),
            $rows,
        );

        $this->assertCount(24, $formPreview['amortization']['rows']);
        $this->assertSame($rows($formPreview['amortization']['rows']), $rows($chairPreview['amortization']['rows']));
        $this->assertSame($rows($formPreview['amortization']['rows']), $rows($stored->json('data')));
        $this->assertSame($rows($formPreview['amortization']['rows']), $rows($saved->json('amortization.rows')));
        $this->assertEquals($formPreview['amortization']['totals'], $chairPreview['amortization']['totals']);
        $this->assertEquals($formPreview['amortization']['totals']['total_payment'], $stored->json('totals.total_payment'));
        $this->assertSame($formPreview['maturity_date'], $loan->fresh()->maturity_date->toDateString());
        $this->assertSame($formPreview['maturity_date'], $chairPreview['maturity_date']);
        $this->assertEquals($formPreview['deductions']['total_deductions'], (float) $loan->fresh()->total_deductions);
        $this->assertEquals($formPreview['deductions']['net_proceeds'], (float) $loan->fresh()->net_proceeds);
        $this->assertEquals($formPreview['deductions'], $chairPreview['deductions']);
    }

    public function test_each_change_is_audited_with_its_old_and_new_value_who_and_when(): void
    {
        $loan = $this->loanAtChairStep();
        $before = AuditLog::query()->max('id');

        $this->editAs($this->chair, $loan, self::EDIT)->assertOk();

        $rows = AuditLog::query()->where('id', '>', $before)->where('auditable_id', $loan->id)->where('auditable_type', Loan::class)->get();
        $this->assertSame(['loan_edited_in_approval'], $rows->pluck('action')->all(), 'One row for the edit, and no second generic "updated" row.');

        $row = $rows->first();
        $this->assertSame($this->chair->id, $row->user_id);
        $this->assertNotNull($row->created_at);
        // The fee items, worked out again on the new principal.
        $this->assertSame([1200.0, 600.0], array_map(fn (array $item): float => (float) $item['amount'], $row->old_values['deductions']));
        $this->assertSame([1600.0, 800.0], array_map(fn (array $item): float => (float) $item['amount'], $row->new_values['deductions']));
        $this->assertEquals([
            'principal_amount' => 60000.0,
            'interest_rate' => 3.0,
            'term' => 6,
            'frequency' => 'monthly',
            'start_date' => '2026-12-01',
            'maturity_date' => '2027-06-01',
            'purpose' => 'Sari-sari store',
            'total_deductions' => 1800.0,
            'net_proceeds' => 58200.0,
        ], Arr::except($row->old_values, 'deductions'));
        $this->assertEquals([
            'principal_amount' => 80000.0,
            'interest_rate' => 2.5,
            'term' => 12,
            'frequency' => 'semi_monthly',
            'start_date' => '2026-12-05',
            'maturity_date' => $loan->fresh()->maturity_date->toDateString(),
            'purpose' => 'Store expansion',
            'total_deductions' => 2400.0,
            'net_proceeds' => 77600.0,
        ], Arr::except($row->new_values, 'deductions'));
        $this->assertStringContainsString('at the "BOD Chairwoman" step', $row->description);
        $this->assertStringContainsString('principal ₱60,000.00 → ₱80,000.00', $row->description);
        $this->assertStringContainsString('term 6 months → 12 months', $row->description);
        $this->assertStringContainsString('payment frequency Monthly → Semi-Monthly', $row->description);
        $this->assertStringContainsString('interest rate 3% → 2.5% per month', $row->description);
    }

    public function test_an_edit_that_changes_nothing_writes_nothing(): void
    {
        $loan = $this->loanAtChairStep();
        $before = AuditLog::query()->max('id');
        $updatedAt = $loan->fresh()->getRawOriginal('updated_at');

        $this->editAs($this->chair, $loan, ['principal_amount' => 60000, 'term' => 6, 'purpose' => 'Sari-sari store'])->assertOk();

        $this->assertSame(0, AuditLog::query()->where('id', '>', $before)->count());
        $this->assertSame($updatedAt, $loan->fresh()->getRawOriginal('updated_at'));
    }

    public function test_the_loans_own_fees_sent_back_in_another_order_change_nothing(): void
    {
        $loan = $this->loanAtChairStep();
        $before = AuditLog::query()->max('id');

        $this->editAs($this->chair, $loan, ['deductions' => [
            ['name' => 'Service Fee', 'amount' => 1, 'type' => 'percentage'],
            ['name' => 'Processing Fee', 'amount' => 2, 'type' => 'percentage'],
        ]])
            ->assertOk()
            ->assertJsonPath('message', 'Nothing changed. The loan is still at the "BOD Chairwoman" step for you to approve or forward.');

        $this->assertSame(0, AuditLog::query()->where('id', '>', $before)->count());
        $this->assertSame(['Processing Fee', 'Service Fee'], array_column($loan->fresh()->deductions, 'name'));
    }

    // -----------------------------------------------------------------
    // A stale version
    // -----------------------------------------------------------------

    public function test_a_stale_version_answers_409_and_changes_nothing(): void
    {
        $loan = $this->loanAtChairStep();
        $version = $this->version($loan);

        // Someone else changes the loan after the Chair opened it.
        $this->actingAs($this->admin)->putJson("/api/loans/{$loan->id}", ['purpose' => 'Changed meanwhile'])->assertOk();
        $before = AuditLog::query()->max('id');

        $this->actingAs($this->chair)
            ->patchJson("/api/loans/{$loan->id}/approval-edit", [...self::EDIT, 'version' => $version])
            ->assertConflict()
            ->assertJsonPath('message', 'This loan changed after you opened it. Reload it to see the changes, then make your edit again.');

        $fresh = $loan->fresh();
        $this->assertSame('60000.00', $fresh->getRawOriginal('principal_amount'));
        $this->assertSame('Changed meanwhile', $fresh->purpose);
        $this->assertSame(0, AuditLog::query()->where('id', '>', $before)->count());
    }

    public function test_the_same_version_saves_only_once(): void
    {
        $loan = $this->loanAtChairStep();
        $version = $this->version($loan);

        $this->actingAs($this->chair)
            ->patchJson("/api/loans/{$loan->id}/approval-edit", [...self::EDIT, 'version' => $version])
            ->assertOk();
        $this->actingAs($this->chair)
            ->patchJson("/api/loans/{$loan->id}/approval-edit", ['principal_amount' => 90000, 'version' => $version])
            ->assertConflict();

        $this->assertSame('80000.00', $loan->fresh()->getRawOriginal('principal_amount'));
    }

    public function test_a_version_is_required(): void
    {
        $loan = $this->loanAtChairStep();

        $this->actingAs($this->chair)
            ->patchJson("/api/loans/{$loan->id}/approval-edit", self::EDIT)
            ->assertUnprocessable()
            ->assertJsonPath('errors.version.0', 'Reload the loan and open the edit again: this save doesn\'t say which version of the loan it was made from.');
    }

    // -----------------------------------------------------------------
    // Who and when
    // -----------------------------------------------------------------

    public function test_only_the_chairs_role_may_edit(): void
    {
        $loan = $this->loanAtChairStep();
        $admin = $this->staff('admin');
        $officer = $this->staff('loan_officer');
        $bod2 = $this->staff('bod2');

        foreach ([$this->manager, $admin, $officer, $bod2] as $user) {
            $this->actingAs($user)
                ->patchJson("/api/loans/{$loan->id}/approval-edit", [...self::EDIT, 'version' => 'x'])
                ->assertForbidden();
            $this->actingAs($user)->getJson("/api/loans/{$loan->id}/approval-edit")->assertForbidden();
            $this->actingAs($user)->postJson("/api/loans/{$loan->id}/approval-edit/preview", self::EDIT)->assertForbidden();
        }

        // super_admin passes every permission check, but doesn't hold the
        // Chair's role, so the step isn't theirs.
        $version = $this->version($loan);
        $this->actingAs($this->admin)
            ->patchJson("/api/loans/{$loan->id}/approval-edit", [...self::EDIT, 'version' => $version])
            ->assertForbidden()
            ->assertJsonPath('message', 'You can edit this loan only while it waits at your approval step. It is at the "BOD Chairwoman" step.');

        $this->assertSame('60000.00', $loan->fresh()->getRawOriginal('principal_amount'));
    }

    public function test_a_role_given_the_permission_edits_only_at_its_own_step(): void
    {
        // A super admin ticks it for bod2 in the role editor; the loan waits
        // at bod1's step, so bod2's holder still can't edit it.
        Role::where('name', 'bod2')->firstOrFail()->givePermissionTo('loans:edit_in_approval');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $loan = $this->loanAtChairStep();

        $version = $this->version($loan);
        $this->actingAs($this->staff('bod2'))
            ->patchJson("/api/loans/{$loan->id}/approval-edit", [...self::EDIT, 'version' => $version])
            ->assertForbidden();
    }

    public function test_a_deactivated_chair_role_grants_nothing(): void
    {
        $loan = $this->loanAtChairStep();
        $version = $this->version($loan);
        Role::where('name', 'bod1')->update(['is_active' => false]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->chair->fresh())
            ->patchJson("/api/loans/{$loan->id}/approval-edit", [...self::EDIT, 'version' => $version])
            ->assertForbidden();
    }

    public function test_the_chair_is_refused_at_another_step(): void
    {
        // Waiting at the Manager's step.
        $loan = $this->submittedLoan();

        $this->actingAs($this->chair)
            ->getJson("/api/loans/{$loan->id}/approval-edit")
            ->assertOk()
            ->assertJsonPath('data.can_edit', false)
            ->assertJsonPath('data.version', null)
            ->assertJsonPath('data.reason', 'You can edit this loan only while it waits at your approval step. It is at the "Manager" step.');
        $this->actingAs($this->chair)
            ->patchJson("/api/loans/{$loan->id}/approval-edit", [...self::EDIT, 'version' => 'x'])
            ->assertForbidden()
            ->assertJsonPath('message', 'You can edit this loan only while it waits at your approval step. It is at the "Manager" step.');

        // Sent back to the Loan Processor by the Chair.
        $loan = $this->loanAtChairStep();
        $step = $this->pendingStep($loan);
        $this->actingAs($this->chair)
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$step->id}/send-back", ['target_step_order' => 0, 'remarks' => 'Fix the purpose'])
            ->assertOk();
        $this->actingAs($this->chair)
            ->patchJson("/api/loans/{$loan->id}/approval-edit", [...self::EDIT, 'version' => 'x'])
            ->assertForbidden();
    }

    public function test_a_loan_that_isnt_waiting_for_approval_is_refused(): void
    {
        $draft = $this->draftLoan();
        $approved = $this->loanAtChairStep();
        $step = $this->pendingStep($approved);
        $this->actingAs($this->chair)->patchJson("/api/loans/{$approved->id}/approval-steps/{$step->id}/approve")->assertOk();

        foreach ([$draft, $approved] as $loan) {
            $this->actingAs($this->chair)
                ->patchJson("/api/loans/{$loan->id}/approval-edit", [...self::EDIT, 'version' => 'x'])
                ->assertUnprocessable()
                ->assertJsonPath('errors.status.0', 'This loan isn\'t waiting for approval, so it can\'t be edited here.');
            $this->actingAs($this->chair)
                ->getJson("/api/loans/{$loan->id}/approval-edit")
                ->assertOk()
                ->assertJsonPath('data.can_edit', false)
                ->assertJsonPath('data.reason', 'This loan isn\'t waiting for approval, so it can\'t be edited here.');
        }
    }

    public function test_the_preview_keeps_the_loans_own_term_once_its_product_counts_another_unit(): void
    {
        $loan = $this->loanAtChairStep();
        // Since the loan was made, its product counts its term in days.
        $this->product->update(['term_unit' => 'days', 'term' => 180, 'min_term' => 90, 'max_term' => 360]);

        $kept = $this->actingAs($this->chair)
            ->postJson("/api/loans/{$loan->id}/approval-edit/preview", ['principal_amount' => 80000, 'term' => 6])
            ->assertOk()
            ->json('data');

        $this->assertSame('2027-06-01', $kept['maturity_date']);
        $this->assertCount(6, $kept['amortization']['rows']);

        // Saving keeps the term too, with the schedule the preview showed.
        $saved = $this->editAs($this->chair, $loan, ['principal_amount' => 80000])->assertOk();
        $this->assertSame($kept['amortization']['rows'], $saved->json('amortization.rows'));

        // A changed term gets no schedule: outside the product's range, or in
        // the product's new unit, which saving refuses.
        foreach ([9, 120] as $term) {
            $changed = $this->actingAs($this->chair)
                ->postJson("/api/loans/{$loan->id}/approval-edit/preview", ['term' => $term])
                ->assertOk()
                ->json('data');
            $this->assertNull($changed['maturity_date'], "term {$term}");
            $this->assertNull($changed['amortization'], "term {$term}");
        }
    }

    public function test_only_an_approve_step_is_edited_even_where_the_chairs_role_holds_another(): void
    {
        ApprovalWorkflowSetting::updateOrCreate(['type' => ApprovalWorkflowSetting::TYPE_NORMAL], ['steps' => [
            ['id' => 'chair-intake', 'name' => 'Chair Intake', 'role' => 'bod1', 'kind' => 'submit'],
            ['id' => 'manager', 'name' => 'Manager', 'role' => 'manager', 'kind' => 'approve'],
            ['id' => 'general-bookkeeper', 'name' => 'General Bookkeeper', 'role' => 'general_bookkeeper', 'kind' => 'release'],
        ]]);
        $loan = $this->submittedLoan();
        $this->actingAs($this->manager)
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$this->pendingStep($loan)->id}/send-back", ['target_step_order' => 0, 'remarks' => 'Check the purpose'])
            ->assertOk();
        $this->assertSame('submit', $this->pendingStep($loan)->kind);

        $this->actingAs($this->chair)
            ->getJson("/api/loans/{$loan->id}/approval-edit")
            ->assertOk()
            ->assertJsonPath('data.can_edit', false)
            ->assertJsonPath('data.reason', 'You can edit this loan only while it waits at your approval step. It is at the "Chair Intake" step.');
        $this->actingAs($this->chair)
            ->patchJson("/api/loans/{$loan->id}/approval-edit", ['purpose' => 'x', 'version' => 'x'])
            ->assertForbidden();
    }

    public function test_another_branchs_loan_answers_404(): void
    {
        $loan = $this->loanAtChairStep();
        // A Chair who sees only another branch: the board's all-branch access taken away.
        Role::where('name', 'bod1')->firstOrFail()->revokePermissionTo(BranchAccess::VIEW_ALL);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $farChair = User::factory()->inBranches(Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']))->create();
        $farChair->assignRole('bod1');

        $this->actingAs($farChair)->getJson("/api/loans/{$loan->id}/approval-edit")->assertNotFound();
        $this->actingAs($farChair)->postJson("/api/loans/{$loan->id}/approval-edit/preview", self::EDIT)->assertNotFound();
        $this->actingAs($farChair)
            ->patchJson("/api/loans/{$loan->id}/approval-edit", [...self::EDIT, 'version' => 'x'])
            ->assertNotFound();
        $this->assertSame('60000.00', $loan->fresh()->getRawOriginal('principal_amount'));
    }

    public function test_a_step_forwarded_to_another_chair_is_theirs_alone(): void
    {
        $other = $this->staff('bod1', ['first_name' => 'Rosa', 'last_name' => 'Lim']);
        $loan = $this->submittedLoan();
        $this->actingAs($this->manager)
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$this->pendingStep($loan)->id}/approve", ['forward_to_user_id' => $other->id])
            ->assertOk();

        $this->actingAs($this->chair)
            ->patchJson("/api/loans/{$loan->id}/approval-edit", [...self::EDIT, 'version' => 'x'])
            ->assertForbidden()
            ->assertJsonPath('message', 'This step was forwarded to Rosa Lim, so only they can edit the loan now.');

        $version = $this->version($loan, $other);
        $this->actingAs($other)
            ->patchJson("/api/loans/{$loan->id}/approval-edit", [...self::EDIT, 'version' => $version])
            ->assertOk();
    }

    public function test_the_state_says_whether_the_chair_may_edit_now(): void
    {
        $loan = $this->loanAtChairStep();
        $step = $this->pendingStep($loan);

        $state = $this->actingAs($this->chair)->getJson("/api/loans/{$loan->id}/approval-edit")->assertOk()->json('data');

        $this->assertTrue($state['can_edit']);
        $this->assertNull($state['reason']);
        $this->assertSame(['id' => $step->id, 'name' => 'BOD Chairwoman'], $state['step']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $state['version']);
        $this->assertSame($state['version'], $this->version($loan), 'The same loan reads the same version.');
    }

    // -----------------------------------------------------------------
    // The product's limits
    // -----------------------------------------------------------------

    public function test_the_products_limits_hold(): void
    {
        $loan = $this->loanAtChairStep();
        $before = AuditLog::query()->max('id');

        $cases = [
            ['principal_amount', ['principal_amount' => 4000], 'The principal must be at least ₱5,000.00 for this product.'],
            ['principal_amount', ['principal_amount' => 100000.01], 'The principal can be at most ₱100,000.00 for this product.'],
            ['interest_rate', ['interest_rate' => 1.25], 'The interest rate must be between 1.5% and 3% per month for this product.'],
            ['interest_rate', ['interest_rate' => 3.5], 'The interest rate must be between 1.5% and 3% per month for this product.'],
            ['term', ['term' => 2], 'The term must be between 3 and 12 months for this product.'],
            ['term', ['term' => 13], 'The term must be between 3 and 12 months for this product.'],
            ['frequency', ['frequency' => 'weekly'], 'The payment frequency must be Monthly or Semi-Monthly for this product.'],
            ['deductions', ['deductions' => [['name' => 'Processing Fee', 'amount' => 3, 'type' => 'percentage']]], 'The processing fee must be between 1% and 2% for this product.'],
            ['deductions', ['deductions' => [['name' => 'Processing Fee', 'amount' => 2, 'type' => 'percentage'], ['name' => 'Service Fee', 'amount' => 1.5, 'type' => 'percentage']]], 'The service fee must be between 0% and 1% for this product.'],
            ['deductions', ['deductions' => [['name' => 'Processing Fee', 'amount' => 2, 'type' => 'percentage'], ['name' => 'Other', 'amount' => 70000, 'type' => 'fixed']]], 'Total deductions exceed the principal amount.'],
        ];

        foreach ($cases as [$field, $edit, $message]) {
            $version = $this->version($loan);
            $this->actingAs($this->chair)
                ->patchJson("/api/loans/{$loan->id}/approval-edit", [...$edit, 'version' => $version])
                ->assertUnprocessable()
                ->assertJsonPath("errors.{$field}.0", $message);
        }

        $fresh = $loan->fresh();
        $this->assertSame('60000.00', $fresh->getRawOriginal('principal_amount'));
        $this->assertSame('3.0000', $fresh->getRawOriginal('interest_rate'));
        $this->assertSame(6, (int) $fresh->term);
        $this->assertSame('monthly', $fresh->frequency);
        $this->assertSame(0, AuditLog::query()->where('id', '>', $before)->count());
    }

    public function test_a_fee_the_product_requires_cant_be_left_out_or_made_a_fixed_amount(): void
    {
        $loan = $this->loanAtChairStep();

        foreach ([
            'left out' => [['name' => 'Service Fee', 'amount' => 1, 'type' => 'percentage']],
            'fixed' => [
                ['name' => 'Processing Fee', 'amount' => 500, 'type' => 'fixed'],
                ['name' => 'Service Fee', 'amount' => 1, 'type' => 'percentage'],
            ],
        ] as $case => $deductions) {
            $version = $this->version($loan);
            $this->actingAs($this->chair)
                ->patchJson("/api/loans/{$loan->id}/approval-edit", ['deductions' => $deductions, 'version' => $version])
                ->assertUnprocessable()
                ->assertJsonPath('errors.deductions.0', 'The processing fee must be between 1% and 2% for this product.');
            $this->assertSame('1800.00', $loan->fresh()->getRawOriginal('total_deductions'), $case);
        }

        // The service fee's minimum is 0%, so it may be left out.
        $this->editAs($this->chair, $loan, ['deductions' => [['name' => 'Processing Fee', 'amount' => 2, 'type' => 'percentage']]])->assertOk();
        $this->assertSame('1200.00', $loan->fresh()->getRawOriginal('total_deductions'));
    }

    public function test_a_rate_or_term_isnt_changed_once_the_product_quotes_another_period_or_unit(): void
    {
        $loan = $this->loanAtChairStep();
        // Since the loan was made, its product quotes rates per year and counts its term in days.
        $this->product->update([
            'interest_rate_frequency' => 'annual',
            'interest_rate' => 36,
            'min_interest_rate' => 18,
            'term_unit' => 'days',
            'term' => 180,
            'min_term' => 90,
            'max_term' => 360,
        ]);

        $version = $this->version($loan);
        $this->actingAs($this->chair)
            ->patchJson("/api/loans/{$loan->id}/approval-edit", ['interest_rate' => 30, 'version' => $version])
            ->assertUnprocessable()
            ->assertJsonPath('errors.interest_rate.0', 'This loan\'s rate is quoted per month, but its product now quotes rates per year, so the rate can\'t be changed during approval.');

        $version = $this->version($loan);
        $this->actingAs($this->chair)
            ->patchJson("/api/loans/{$loan->id}/approval-edit", ['term' => 200, 'version' => $version])
            ->assertUnprocessable()
            ->assertJsonPath('errors.term.0', 'This loan\'s term is counted in months, but its product now counts days, so the term can\'t be changed during approval.');

        // The rest of the loan still can be, and its own rate and term stay.
        $this->editAs($this->chair, $loan, ['interest_rate' => 3, 'term' => 6, 'purpose' => 'Store expansion'])->assertOk();
        $this->assertSame('3.0000', $loan->fresh()->getRawOriginal('interest_rate'));
        $this->assertSame(6, (int) $loan->fresh()->term);
        $this->assertSame('Store expansion', $loan->fresh()->purpose);
    }

    public function test_the_build_up_holds_to_the_products_range(): void
    {
        $this->product->update(['scb_required' => true, 'min_scb' => 100, 'max_scb' => 500]);
        $loan = $this->loanAtChairStep(['scb_amount' => 200]);

        foreach ([[0, 'This product needs a share capital build-up of at least ₱100.00.'], [50, 'The share capital build-up must be between ₱100.00 and ₱500.00 for this product.'], [600, 'The share capital build-up must be between ₱100.00 and ₱500.00 for this product.']] as [$scb, $message]) {
            $version = $this->version($loan);
            $this->actingAs($this->chair)
                ->patchJson("/api/loans/{$loan->id}/approval-edit", ['scb_amount' => $scb, 'version' => $version])
                ->assertUnprocessable()
                ->assertJsonPath('errors.scb_amount.0', $message);
        }

        $this->editAs($this->chair, $loan, ['scb_amount' => 300])->assertOk();
        $this->assertSame('300.00', $loan->fresh()->getRawOriginal('scb_amount'));
    }

    public function test_a_limit_is_checked_only_when_the_edit_moves_that_field(): void
    {
        $loan = $this->loanAtChairStep();
        // The product's range narrowed after the loan was made.
        $this->product->update(['max_amount' => 50000]);

        $this->editAs($this->chair, $loan, ['term' => 9, 'principal_amount' => 60000])->assertOk();
        $this->assertSame(9, (int) $loan->fresh()->term);
    }

    public function test_dates_are_year_month_day(): void
    {
        $loan = $this->loanAtChairStep();

        $version = $this->version($loan);
        $this->actingAs($this->chair)
            ->patchJson("/api/loans/{$loan->id}/approval-edit", ['start_date' => '12/05/2026', 'version' => $version])
            ->assertUnprocessable()
            ->assertJsonPath('errors.start_date.0', 'The start date must be a date written as year-month-day, such as 2026-11-24.');
    }

    public function test_the_member_product_and_policy_exception_stay_as_they_are(): void
    {
        $loan = $this->loanAtChairStep();
        $other = LoanProduct::factory()->create();

        $cases = [
            ['borrower_id', ['borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id], 'The member can\'t be changed.'],
            ['loan_product_id', ['loan_product_id' => $other->id], 'The loan product can\'t be changed during approval.'],
            ['policy_exception', ['policy_exception' => true], 'The policy exception can\'t be changed during approval: it decides which approval chain the loan follows. Send the loan back to the Submit step to change it.'],
        ];

        foreach ($cases as [$field, $edit, $message]) {
            $version = $this->version($loan);
            $this->actingAs($this->chair)
                ->patchJson("/api/loans/{$loan->id}/approval-edit", [...$edit, 'version' => $version])
                ->assertUnprocessable()
                ->assertJsonPath("errors.{$field}.0", $message);
        }

        // The form sends them back unchanged with every save, which is fine.
        $this->editAs($this->chair, $loan, [
            ...self::EDIT,
            'borrower_id' => $loan->borrower_id,
            'loan_product_id' => $this->product->id,
            'policy_exception' => false,
        ])->assertOk();
    }

    public function test_a_principal_that_brings_in_a_step_the_chain_left_out_is_refused(): void
    {
        ApprovalWorkflowSetting::updateOrCreate(['type' => ApprovalWorkflowSetting::TYPE_NORMAL], ['steps' => [
            ...array_slice(self::CHAIN, 0, 3),
            ['id' => 'head-office', 'name' => 'Head Office', 'role' => 'bod2', 'kind' => 'approve', 'min_principal' => '70000.00'],
            self::CHAIN[3],
        ]]);
        $loan = $this->loanAtChairStep();

        $version = $this->version($loan);
        $this->actingAs($this->chair)
            ->patchJson("/api/loans/{$loan->id}/approval-edit", ['principal_amount' => 80000, 'version' => $version])
            ->assertUnprocessable()
            ->assertJsonPath('errors.principal_amount.0', 'A principal of ₱80,000.00 brings in the "Head Office" step (from ₱70,000.00), which this loan\'s approval chain left out. Send the loan back to the Submit step so its approval chain is set again.');

        $this->editAs($this->chair, $loan, ['principal_amount' => 69000])->assertOk();
    }

    public function test_a_restructure_is_refused(): void
    {
        $source = $this->createReleasedLoan();
        $restructure = app(LoanService::class)->restructure($source, [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => 70800,
            'start_date' => now()->toDateString(),
        ], $this->admin);
        app(LoanService::class)->submitForReview($restructure, $this->admin);
        $this->actingAs($this->manager)
            ->patchJson("/api/loans/{$restructure->id}/approval-steps/{$this->pendingStep($restructure)->id}/approve")
            ->assertOk();

        $this->actingAs($this->chair)
            ->patchJson("/api/loans/{$restructure->id}/approval-edit", ['purpose' => 'x', 'version' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.loan.0', 'A restructure can\'t be edited during approval: its figures were approved against the loan it replaces. Send it back to the Submit step to change it.');
    }

    public function test_collateral_changes_need_collaterals_update_too(): void
    {
        $loan = $this->loanAtChairStep();

        $version = $this->version($loan);
        $this->actingAs($this->chair)
            ->patchJson("/api/loans/{$loan->id}/approval-edit", ['collaterals' => [], 'version' => $version])
            ->assertForbidden();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function staff(string $role, array $attributes = []): User
    {
        $user = User::factory()->inBranches($this->branch)->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function draftLoan(array $attributes = []): Loan
    {
        return app(LoanService::class)->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $this->product->id,
            'principal_amount' => 60000,
            'interest_rate' => 3.0,
            'term' => 6,
            'frequency' => 'monthly',
            'start_date' => '2026-12-01',
            'purpose' => 'Sari-sari store',
            ...$attributes,
        ], $this->admin);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function submittedLoan(array $attributes = []): Loan
    {
        $loan = $this->draftLoan($attributes);
        app(LoanService::class)->submitForReview($loan, $this->admin);

        return $loan->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function loanAtChairStep(array $attributes = []): Loan
    {
        $loan = $this->submittedLoan($attributes);

        $this->actingAs($this->manager)
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$this->pendingStep($loan)->id}/approve")
            ->assertOk();
        $this->actingAs($this->admin);
        $this->assertSame('BOD Chairwoman', $this->pendingStep($loan)->name);

        return $loan->fresh();
    }

    private function pendingStep(Loan $loan): LoanApprovalStep
    {
        return $loan->approvalSteps()
            ->where('status', LoanApprovalStep::STATUS_PENDING)
            ->reorder()
            ->orderByDesc('round')
            ->orderBy('step_order')
            ->firstOrFail();
    }

    /**
     * The version `$as` (the Chair by default) reads, leaving the admin acting:
     * read it before acting as anyone for the request it goes with.
     */
    private function version(Loan $loan, ?User $as = null): string
    {
        $version = $this->actingAs($as ?? $this->chair)
            ->getJson("/api/loans/{$loan->id}/approval-edit")
            ->assertOk()
            ->json('data.version');
        $this->actingAs($this->admin);

        return (string) $version;
    }

    /**
     * @param  array<string, mixed>  $edit
     */
    private function editAs(User $user, Loan $loan, array $edit): TestResponse
    {
        $version = $this->version($loan, $user);

        return $this->actingAs($user)->patchJson("/api/loans/{$loan->id}/approval-edit", [...$edit, 'version' => $version]);
    }
}
