<?php

namespace Tests\Feature\Phase6\Audit;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Collateral;
use App\Models\Loan;
use App\Models\LoanApprovalStep;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 MB-BE6 (multi-branch decision 12): each new audit row records the
 * audited record's branch, through one resolver: the record's own
 * `branch_id`, else its loan's or member's branch, else none. The Audit Trail
 * list and CSV filter on it (`branch_id`), which needs `branches:view_all`
 * like the trail itself.
 */
class AuditBranchTest extends TestCase
{
    use SetupLendyPH;

    private Branch $north;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->north = Branch::factory()->create(['name' => 'North', 'code' => 'NOR']);
    }

    public function test_a_record_with_its_own_branch_records_it(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->north->id]);

        $this->assertSame($this->north->id, $this->rowFor($member, 'created')->branch_id);
    }

    public function test_a_record_on_a_loan_records_the_loans_branch(): void
    {
        $loan = $this->createReleasedLoan();
        Loan::query()->whereKey($loan->id)->update(['branch_id' => $this->north->id]);

        $step = LoanApprovalStep::query()->where('loan_id', $loan->id)->firstOrFail();
        $step->update(['remarks' => 'Noted.']);

        $this->assertSame($this->north->id, $this->rowFor($step, 'updated')->branch_id);
    }

    public function test_a_record_of_a_member_records_the_members_branch(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->north->id]);
        $collateral = Collateral::factory()->create(['borrower_id' => $member->id]);

        $this->assertSame($this->north->id, $this->rowFor($collateral, 'created')->branch_id);
    }

    public function test_a_record_with_no_branch_records_none(): void
    {
        $role = Role::create(['name' => 'mb6_no_branch', 'guard_name' => 'web']);

        $row = AuditLogService::log('role_created', $role, newValues: ['name' => 'mb6_no_branch']);
        $this->assertNull($row->fresh()->branch_id);

        $this->assertNull(AuditLogService::log('login')->fresh()->branch_id);
    }

    public function test_the_audit_trail_filters_by_branch(): void
    {
        $north = Borrower::factory()->create(['branch_id' => $this->north->id]);
        $main = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $ids = collect($this->getJson("/api/audit-logs?branch_id={$this->north->id}&per_page=100")->assertOk()->json('data'))
            ->pluck('id');

        $this->assertTrue($ids->contains($this->rowFor($north, 'created')->id));
        $this->assertFalse($ids->contains($this->rowFor($main, 'created')->id));
        $this->assertSame(
            [$this->north->id],
            AuditLog::query()->whereIn('id', $ids)->distinct()->pluck('branch_id')->all(),
        );
    }

    public function test_the_csv_filters_by_branch(): void
    {
        $north = Borrower::factory()->create(['branch_id' => $this->north->id, 'first_name' => 'Nora']);
        $main = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $csv = $this->get("/api/audit-logs/export?branch_id={$this->north->id}", ['Accept' => 'application/json'])
            ->assertOk()
            ->streamedContent();

        $rows = array_map('str_getcsv', array_slice(array_values(array_filter(explode("\n", trim($csv)))), 1));
        $memberIds = array_column(array_filter($rows, fn (array $row) => ($row[3] ?? null) === 'Member'), 4);

        $this->assertContains((string) $north->id, $memberIds);
        $this->assertNotContains((string) $main->id, $memberIds);
    }

    public function test_the_branch_filter_needs_branches_view_all(): void
    {
        $role = Role::create(['name' => 'mb6_audit_scoped', 'guard_name' => 'web']);
        $role->givePermissionTo(['audit_logs:view', 'audit_logs:export']);
        $caller = User::factory()->inBranches($this->north)->create();
        $caller->assignRole($role);

        $this->actingAs($caller)->getJson("/api/audit-logs?branch_id={$this->north->id}")->assertForbidden();
        $this->actingAs($caller)->getJson("/api/audit-logs/export?branch_id={$this->north->id}")->assertForbidden();
    }

    public function test_a_bad_branch_filter_is_refused(): void
    {
        $this->getJson('/api/audit-logs?branch_id=abc')->assertStatus(422)->assertJsonValidationErrors('branch_id');
        $this->getJson('/api/audit-logs?branch_id=0')->assertStatus(422)->assertJsonValidationErrors('branch_id');
    }

    public function test_rows_carry_their_branch(): void
    {
        $north = Borrower::factory()->create(['branch_id' => $this->north->id]);
        $row = $this->rowFor($north, 'created');

        $this->getJson("/api/audit-logs/{$row->id}")
            ->assertOk()
            ->assertJsonPath('data.branch_id', $this->north->id)
            ->assertJsonPath('data.branch_name', 'North');
    }

    private function rowFor(Model $model, string $action): AuditLog
    {
        return AuditLog::query()
            ->where('auditable_type', $model->getMorphClass())
            ->where('auditable_id', $model->getKey())
            ->where('action', $action)
            ->latest('id')
            ->firstOrFail();
    }
}
