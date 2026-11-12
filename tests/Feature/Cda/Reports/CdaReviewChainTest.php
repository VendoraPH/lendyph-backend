<?php

namespace Tests\Feature\Cda\Reports;

use App\Models\ApprovalWorkflowSetting;
use App\Models\AuditLog;
use App\Models\CdaReport;
use App\Models\CdaReportApprovalStep;
use App\Models\Role;
use App\Models\User;
use App\Services\Cda\CdaAccess;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
use ReflectionProperty;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The CDA review chain (gap round B6): Settings → Approval Workflow's `cda`
 * type holds approve-only steps by role (saving and resetting need
 * `cda:settings`); submit for review copies them onto the report; each step
 * needs `cda:approve`, its role (admin and super_admin bypass) and a user who
 * is neither the submitter nor an earlier step's approver; only the last step
 * approves; the pending step's holders may return it; a return or reopen
 * closes the round; reports already in review keep the single review; the
 * approval notice goes only to the pending step's role.
 */
class CdaReviewChainTest extends TestCase
{
    use CdaReportTestHelpers, SetupLendyPH;

    private User $preparer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2027-01-15 10:00:00', 'Asia/Manila'));
        $this->brandAs('Binhi Multi-Purpose Cooperative');
        $this->member(['gender' => 'male']);
        $this->member(['gender' => 'female']);
        $this->preparer = $this->cdaStaff();
        Role::query()->firstOrCreate(['name' => 'cda_manager', 'guard_name' => 'web'], ['display_name' => 'CDA Manager'])
            ->givePermissionTo(['branches:view_all', CdaAccess::VIEW, CdaAccess::APPROVE]);
        Role::query()->firstOrCreate(['name' => 'cda_board', 'guard_name' => 'web'], ['display_name' => 'Board Chair'])
            ->givePermissionTo(['branches:view_all', CdaAccess::VIEW, CdaAccess::APPROVE]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  list<string>  $roles */
    private function holder(array $roles): User
    {
        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole($roles);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function saveTwoSteps(): void
    {
        $this->actingAs($this->admin)->putJson('/api/settings/approval-workflow', ['type' => 'cda', 'steps' => [
            ['name' => 'Manager review', 'role' => 'cda_manager'],
            ['name' => 'Board sign-off', 'role' => 'cda_board', 'status' => 'Board review'],
        ]])->assertOk();
    }

    /** A workflow action; an approve names the pending step (`step_id`), as the screen does. */
    private function act(CdaReport $report, string $action, User $as, array $body = [])
    {
        if ($action === 'approve' && ! array_key_exists('step_id', $body)) {
            $pending = $this->pendingStep($report);
            $body += $pending === null ? [] : ['step_id' => $pending->id];
        }

        return $this->actingAs($as)->postJson("/api/cda/reports/{$report->id}/workflow", ['action' => $action, ...$body]);
    }

    private function pendingStep(CdaReport $report): ?CdaReportApprovalStep
    {
        return CdaReportApprovalStep::query()->where('report_id', $report->id)->where('status', 'pending')->whereNull('closed_at')->first();
    }

    /** The test CAPR, filled in and submitted for review by the preparer. */
    private function submitted(): CdaReport
    {
        $report = $this->caprWithTestTemplate();
        $this->actingAs($this->preparer)->putJson("/api/cda/reports/{$report->id}", ['fields' => [
            ['field_key' => 'employment.employees_regular', 'value' => 5],
        ]])->assertOk();
        $this->act($report, 'submit_for_review', $this->preparer)->assertOk()->assertJsonPath('data.status', 'for_review');

        return $report->refresh();
    }

    /** @return list<string> */
    private function stepStatuses(CdaReport $report): array
    {
        return $this->actingAs($this->admin)->getJson("/api/cda/reports/{$report->id}")->assertOk()->json('data.approval_steps.*.status');
    }

    /** @return list<string> */
    private function noticesFor(User $user): array
    {
        return DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $user->id)->orderBy('id')->pluck('type')->all();
    }

    public function test_the_chain_settings_need_cda_settings_take_approve_only_steps_and_reset_to_a_single_review(): void
    {
        $settingsOnly = $this->userWith(['settings:view', 'settings:update', 'branches:view_all']);
        $cdaSettings = $this->userWith(['settings:view', 'settings:update', 'branches:view_all', CdaAccess::SETTINGS]);
        $steps = [['name' => 'Manager review', 'role' => 'cda_manager']];

        $this->actingAs($settingsOnly)->getJson('/api/settings/approval-workflow?type=cda')->assertForbidden()
            ->assertJsonPath('message', 'This needs the CDA view permission.');
        $this->actingAs($this->userWith(['settings:view', CdaAccess::VIEW]))->getJson('/api/settings/approval-workflow?type=cda')->assertForbidden()
            ->assertJsonPath('message', 'CDA reports cover the whole cooperative, so this needs head-office access to all branches.');
        $this->actingAs($this->userWith(['settings:view', 'branches:view_all', CdaAccess::VIEW]))->getJson('/api/settings/approval-workflow?type=cda')->assertOk()
            ->assertJsonPath('data.steps', [])
            ->assertJsonPath('data.is_default', true);
        $this->actingAs($settingsOnly)->getJson('/api/settings/approval-workflow?type=normal')->assertOk();
        $this->actingAs($settingsOnly)->getJson('/api/settings/approval-workflow?type=savings')->assertStatus(422)
            ->assertJsonPath('errors.type.0', 'Choose a loan approval chain (normal or policy exception) or the CDA review chain.');
        $this->actingAs($settingsOnly)->putJson('/api/settings/approval-workflow', ['type' => 'cda', 'steps' => $steps])->assertForbidden()
            ->assertJsonPath('message', 'This needs the CDA settings permission.');
        $this->actingAs($settingsOnly)->deleteJson('/api/settings/approval-workflow?type=cda')->assertForbidden();

        $this->actingAs($cdaSettings)->putJson('/api/settings/approval-workflow', ['type' => 'cda', 'steps' => [['name' => 'Prepare', 'role' => 'cda_manager', 'kind' => 'submit']]])
            ->assertStatus(422)->assertJsonValidationErrors(['steps.0.kind']);
        $this->actingAs($cdaSettings)->putJson('/api/settings/approval-workflow', ['type' => 'cda', 'steps' => [['name' => 'Ghost', 'role' => 'no_such_role']]])
            ->assertStatus(422)->assertJsonValidationErrors(['steps.0.role']);
        Role::query()->where('name', 'cda_board')->update(['is_active' => false]);
        $this->actingAs($cdaSettings)->putJson('/api/settings/approval-workflow', ['type' => 'cda', 'steps' => [['name' => 'Board', 'role' => 'cda_board']]])
            ->assertStatus(422)->assertJsonValidationErrors(['steps.0.role']);
        $this->actingAs($cdaSettings)->putJson('/api/settings/approval-workflow', ['type' => 'cda', 'steps' => [
            ['name' => 'Manager review', 'role' => 'cda_manager'], ['name' => ' manager  REVIEW ', 'role' => 'cda_manager'],
        ]])->assertStatus(422)->assertJsonValidationErrors(['steps.1.name']);
        $this->actingAs($cdaSettings)->putJson('/api/settings/approval-workflow', ['type' => 'cda', 'steps' => array_map(
            fn (int $n) => ['name' => "Step {$n}", 'role' => 'cda_manager'],
            range(1, 11),
        )])->assertStatus(422)->assertJsonValidationErrors(['steps']);
        $this->actingAs($cdaSettings)->putJson('/api/settings/approval-workflow', ['type' => 'cda', 'steps' => []])
            ->assertStatus(422)->assertJsonValidationErrors(['steps']);

        $this->actingAs($cdaSettings)->putJson('/api/settings/approval-workflow', ['type' => 'cda', 'steps' => [
            ['name' => 'Manager review', 'role' => 'cda_manager', 'status' => 'With the manager'],
        ]])->assertOk()
            ->assertJsonPath('data.steps.0.kind', 'approve')
            ->assertJsonPath('data.steps.0.status', 'With the manager')
            ->assertJsonPath('data.is_default', false);

        $this->actingAs($cdaSettings)->deleteJson('/api/settings/approval-workflow?type=cda')->assertOk()
            ->assertJsonPath('data.steps', [])
            ->assertJsonPath('data.is_default', true);
        $this->assertFalse(ApprovalWorkflowSetting::query()->where('type', 'cda')->exists());

        $audit = AuditLog::query()->where('action', 'cda_review_chain_updated')->orderBy('id')->get();
        $this->assertCount(2, $audit);
        $this->assertSame('CDA review chain saved: Manager review (CDA Manager).', $audit[0]->description);
        $this->assertSame('CDA review chain reset to a single review.', $audit[1]->description);
        $this->assertSame([], $audit[1]->new_values['steps']);

        $this->actingAs($cdaSettings)->getJson('/api/settings/approval-workflow?type=normal')->assertOk()->assertJsonCount(4, 'data.steps');
    }

    public function test_a_two_step_chain_runs_end_to_end_and_only_the_last_step_approves(): void
    {
        $this->saveTwoSteps();
        $manager = $this->holder(['cda_manager']);
        $board = $this->holder(['cda_board']);
        $report = $this->submitted();

        $steps = $this->actingAs($manager)->getJson("/api/cda/reports/{$report->id}")->assertOk()->json('data');
        $this->assertSame(['pending', 'waiting'], array_column($steps['approval_steps'], 'status'));
        $this->assertSame(['Manager review', 'Board sign-off'], array_column($steps['approval_steps'], 'name'));
        $this->assertSame(['CDA Manager', 'Board Chair'], array_column($steps['approval_steps'], 'role_label'));
        $this->assertSame('Board review', $steps['approval_steps'][1]['status_label']);
        $this->assertContains('approve', $steps['available_actions']);
        $this->assertNotContains('approve', $this->actingAs($board)->getJson("/api/cda/reports/{$report->id}")->json('data.available_actions'));

        $this->act($report, 'approve', $board)->assertForbidden()
            ->assertJsonPath('message', 'The "Manager review" step is for the CDA Manager role.');

        $this->act($report, 'approve', $manager, ['reason' => 'Figures match the books'])->assertOk()
            ->assertJsonPath('data.status', 'for_review')
            ->assertJsonPath('data.approval_steps.0.status', 'approved')
            ->assertJsonPath('data.approval_steps.0.acted_by.id', $manager->id)
            ->assertJsonPath('data.approval_steps.0.remarks', 'Figures match the books')
            ->assertJsonPath('data.approval_steps.1.status', 'pending');
        $this->assertNull($report->refresh()->approved_by);
        $this->assertContains('approval', $this->getJson("/api/cda/reports/{$report->id}")->json('data.readiness_breakdown.failed'), 'One step of two is not an approval yet.');

        $this->act($report, 'approve', $board)->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved_by.id', $board->id)
            ->assertJsonPath('data.approval_steps.1.acted_by_name', $board->full_name);

        $rows = AuditLog::query()->where('auditable_type', CdaReport::class)->where('auditable_id', $report->id)->where('action', 'cda_workflow')->orderBy('id')->get();
        $this->assertSame(['submit_for_review', 'approve_step', 'approve'], $rows->pluck('new_values.action')->all());
        $this->assertSame('Manager review', $rows[1]->new_values['step']);
        $this->assertSame(['for_review', 'for_review', 'approved'], $rows->pluck('new_values.status')->all());
        $this->assertNotContains('approval', $this->getJson("/api/cda/reports/{$report->id}")->json('data.readiness_breakdown.failed'), 'Readiness counts the approval once the last step approves.');
    }

    public function test_the_submitter_is_refused_at_every_step(): void
    {
        $this->saveTwoSteps();
        $this->preparer->assignRole(['cda_manager', 'cda_board']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $manager = $this->holder(['cda_manager']);
        $report = $this->submitted();

        $this->act($report, 'approve', $this->preparer)->assertForbidden()
            ->assertJsonPath('message', 'You submitted this report for review, so someone else has to approve it.');
        $this->act($report, 'approve', $manager)->assertOk();
        $this->act($report, 'approve', $this->preparer)->assertForbidden();
        $this->assertNotContains('approve', $this->actingAs($this->preparer)->getJson("/api/cda/reports/{$report->id}")->json('data.available_actions'));
        $this->assertSame(['approved', 'pending'], $this->stepStatuses($report));
    }

    public function test_one_user_cant_approve_two_steps_of_a_round(): void
    {
        $this->saveTwoSteps();
        $both = $this->holder(['cda_manager', 'cda_board']);
        $report = $this->submitted();

        $this->act($report, 'approve', $both)->assertOk();
        $this->assertNotContains('approve', $this->actingAs($both)->getJson("/api/cda/reports/{$report->id}")->json('data.available_actions'));
        $this->act($report, 'approve', $both)->assertForbidden()
            ->assertJsonPath('message', 'You approved an earlier step of this review, so someone else has to approve this one.');
        $this->assertSame('for_review', $report->refresh()->status);
    }

    public function test_a_bypass_role_acts_on_any_step_but_never_as_the_submitter_or_twice(): void
    {
        $this->saveTwoSteps();
        $adminOne = $this->holder(['admin']);
        $adminTwo = $this->holder(['admin']);
        $report = $this->submitted();

        $this->act($report, 'approve', $adminOne)->assertOk()->assertJsonPath('data.approval_steps.0.status', 'approved');
        $this->act($report, 'approve', $adminOne)->assertForbidden();
        $this->act($report, 'approve', $adminTwo)->assertOk()->assertJsonPath('data.status', 'approved');

        $this->act($report, 'return', $this->preparer, ['reason' => 'Recount the members'])->assertOk();
        $this->act($report, 'submit_for_review', $adminOne)->assertOk();
        $this->act($report, 'approve', $adminOne)->assertForbidden()
            ->assertJsonPath('message', 'You submitted this report for review, so someone else has to approve it.');
    }

    public function test_the_pending_steps_holder_can_return_without_cda_review(): void
    {
        $this->saveTwoSteps();
        $manager = $this->holder(['cda_manager']);
        $board = $this->holder(['cda_board']);
        $report = $this->submitted();

        $this->assertContains('return', $this->actingAs($manager)->getJson("/api/cda/reports/{$report->id}")->json('data.available_actions'));
        $this->assertNotContains('return', $this->actingAs($board)->getJson("/api/cda/reports/{$report->id}")->json('data.available_actions'));
        $this->act($report, 'return', $board, ['reason' => 'Not my step'])->assertForbidden()
            ->assertJsonPath('message', 'Returning this report needs the CDA review permission or the CDA Manager role of its "Manager review" review step.');

        $this->act($report, 'return', $manager, ['reason' => 'The payroll is missing'])->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.sub_status', 'returned')
            ->assertJsonPath('data.approval_steps.0.status', 'returned')
            ->assertJsonPath('data.approval_steps.0.remarks', 'The payroll is missing')
            ->assertJsonPath('data.approval_steps.0.closed', true)
            ->assertJsonPath('data.approval_steps.1.status', 'cancelled');

        $this->act($report, 'submit_for_review', $this->preparer)->assertOk()
            ->assertJsonPath('data.approval_steps.0.round', 2)
            ->assertJsonPath('data.approval_steps.0.status', 'pending');
        $this->assertSame(4, CdaReportApprovalStep::query()->where('report_id', $report->id)->count());
    }

    public function test_a_report_already_in_review_keeps_the_single_review(): void
    {
        $approver = $this->cdaStaff();
        $report = $this->submitted();
        $this->saveTwoSteps();

        $this->assertSame([], $this->actingAs($approver)->getJson("/api/cda/reports/{$report->id}")->json('data.approval_steps'));
        $this->act($report, 'approve', $approver)->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertFalse(CdaReportApprovalStep::query()->where('report_id', $report->id)->exists());
    }

    public function test_a_return_from_approved_and_a_reopen_close_the_round(): void
    {
        $this->saveTwoSteps();
        $manager = $this->holder(['cda_manager']);
        $board = $this->holder(['cda_board']);
        $report = $this->submitted();
        $this->act($report, 'approve', $manager)->assertOk();
        $this->act($report, 'approve', $board)->assertOk();

        $this->act($report, 'return', $this->preparer, ['reason' => 'A late adjustment'])->assertOk()
            ->assertJsonPath('data.approval_steps.0.status', 'approved')
            ->assertJsonPath('data.approval_steps.0.closed', true)
            ->assertJsonPath('data.approval_steps.1.closed', true);

        $this->act($report, 'submit_for_review', $this->preparer)->assertOk();
        $this->act($report, 'approve', $manager)->assertOk();
        $this->act($report, 'approve', $board)->assertOk();
        $this->act($report, 'finalize', $this->preparer, ['confirm_warnings' => true])->assertOk();
        $this->assertSame(2, CdaReportApprovalStep::query()->where('report_id', $report->id)->where('round', 2)->whereNull('closed_at')->count());

        $this->act($report, 'reopen', $this->preparer, ['reason' => 'CDA asked for a correction'])->assertOk()
            ->assertJsonPath('data.status', 'draft');
        $this->assertSame(0, CdaReportApprovalStep::query()->where('report_id', $report->id)->whereNull('closed_at')->count());
        $this->assertSame([true, true], $this->getJson("/api/cda/reports/{$report->id}")->json('data.approval_steps.*.closed'));
    }

    public function test_the_approval_notice_goes_only_to_the_pending_steps_role(): void
    {
        $this->saveTwoSteps();
        $manager = $this->holder(['cda_manager']);
        $otherManager = $this->holder(['cda_manager']);
        $board = $this->holder(['cda_board']);
        $plainApprover = $this->userWith(['branches:view_all', CdaAccess::VIEW, CdaAccess::APPROVE]);
        $this->preparer->assignRole('cda_manager');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $report = $this->submitted();

        $this->assertSame(['cda_approval_needed'], $this->noticesFor($manager));
        $this->assertSame(['cda_approval_needed'], $this->noticesFor($otherManager));
        $this->assertSame([], $this->noticesFor($board));
        $this->assertSame([], $this->noticesFor($plainApprover));
        $this->assertSame([], $this->noticesFor($this->preparer), 'Never the submitter, even holding the role.');

        $notice = json_decode(DB::table('notifications')->where('notifiable_id', $manager->id)->sole()->data, true);
        $this->assertSame('Manager review', $notice['data']['step']);
        $this->assertStringContainsString('Review step: Manager review.', $notice['body']);

        $this->act($report, 'approve', $manager)->assertOk();

        $this->assertSame(['cda_approval_needed'], $this->noticesFor($board));
        $this->assertSame(['cda_approval_needed'], $this->noticesFor($manager), 'The approver of the step is not told again.');
        $this->assertSame([], $this->noticesFor($plainApprover));
    }

    public function test_an_approve_names_its_step_so_a_late_click_never_approves_the_next_one(): void
    {
        $this->saveTwoSteps();
        $admin = $this->holder(['admin']);
        $manager = $this->holder(['cda_manager']);
        $report = $this->submitted();
        $first = $this->pendingStep($report);
        $post = fn (User $as, array $body) => $this->actingAs($as)->postJson("/api/cda/reports/{$report->id}/workflow", ['action' => 'approve', ...$body]);

        $post($manager, [])->assertStatus(422)
            ->assertJsonValidationErrors(['step_id'])
            ->assertJsonPath('message', 'Say which review step you are approving: the report is at the "Manager review" step.');
        $post($manager, ['step_id' => 'first'])->assertStatus(422)->assertJsonValidationErrors(['step_id']);
        $post($manager, ['step_id' => $first->id])->assertOk();

        $post($admin, ['step_id' => $first->id])->assertStatus(409)
            ->assertJsonPath('message', 'That review step isn\'t waiting for approval any more: the report is now at the "Board sign-off" step. Reload it to see where it stands.');
        $this->assertSame(['approved', 'pending'], $this->stepStatuses($report));
        $this->assertSame('for_review', $report->refresh()->status);
    }

    public function test_a_saved_chain_that_cant_be_read_refuses_the_submit(): void
    {
        ApprovalWorkflowSetting::query()->create(['type' => 'cda', 'steps' => [['name' => 'Manager review']]]);
        Log::spy();
        $report = $this->caprWithTestTemplate();
        $this->actingAs($this->preparer)->putJson("/api/cda/reports/{$report->id}", ['fields' => [['field_key' => 'employment.employees_regular', 'value' => 5]]])->assertOk();

        $this->act($report, 'submit_for_review', $this->preparer)->assertStatus(422)
            ->assertJsonPath('message', 'The CDA review chain in Settings can\'t be read. Fix it or reset it.');

        $this->assertSame('draft', $report->refresh()->status);
        $this->assertFalse(CdaReportApprovalStep::query()->where('report_id', $report->id)->exists());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'CDA review chain'))->once();
    }

    public function test_a_deadlock_while_closing_a_round_answers_409_and_changes_nothing(): void
    {
        $this->saveTwoSteps();
        $manager = $this->holder(['cda_manager']);
        $report = $this->submitted();
        $fired = false;

        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$fired): void {
            if ($fired || ! str_starts_with($query, 'update `cda_report_approval_steps`')) {
                return;
            }

            $fired = true;
            $pdo = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
            $pdo->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];
            (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, '40001');

            throw new QueryException(DB::connection()->getName(), $query, $bindings, $pdo);
        });

        $this->act($report, 'return', $manager, ['reason' => 'The payroll is missing'])->assertStatus(409)
            ->assertExactJson(['message' => 'Another change to this report was saved at the same time. Reload it and try again.']);

        $this->assertSame('for_review', $report->refresh()->status);
        $this->assertSame(['pending', 'waiting'], $this->stepStatuses($report));
    }

    public function test_an_approver_without_review_is_refused_a_return_before_any_other_answer(): void
    {
        $approverOnly = $this->userWith(['branches:view_all', CdaAccess::VIEW, CdaAccess::APPROVE]);
        $report = $this->submitted();
        $refusal = 'Returning this report needs the CDA review permission.';

        $this->act($report, 'return', $approverOnly, ['reason' => 'Recount'])->assertForbidden()->assertJsonPath('message', $refusal);
        $this->act($report, 'return', $approverOnly)->assertForbidden()->assertJsonPath('message', $refusal);
        $this->assertNotContains('return', $this->actingAs($approverOnly)->getJson("/api/cda/reports/{$report->id}")->json('data.available_actions'));

        $this->act($report, 'approve', $approverOnly)->assertOk()->assertJsonPath('data.status', 'approved');
        $this->act($report, 'return', $approverOnly, ['reason' => 'Recount'])->assertForbidden()->assertJsonPath('message', $refusal);
        $this->act($report, 'return', $approverOnly)->assertForbidden()->assertJsonPath('message', $refusal);
        $this->assertSame('approved', $report->refresh()->status);

        $this->act($report, 'return', $this->preparer, ['reason' => 'Recount'])->assertOk();
        $this->act($report, 'return', $approverOnly, ['reason' => 'Recount'])->assertForbidden()->assertJsonPath('message', $refusal);
    }
}
