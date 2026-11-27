<?php

namespace Tests\Feature\Cda\Reports;

use App\Models\User;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Reports\WorkflowNotices;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Workflow notices (design §5, §10): approval needed, returned, finalized and
 * for correction send in-app staff notifications (database only) after the
 * transition commits, to active users holding view_all and the action's
 * audience permission (and the restricted view where the report needs one),
 * never to the person who acted.
 */
class CdaWorkflowNoticesTest extends TestCase
{
    use CdaReportTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2027-01-15 10:00:00', 'Asia/Manila'));
        $this->brandAs('Binhi Multi-Purpose Cooperative');
        $this->member(['gender' => 'male']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<string> */
    private function noticesFor(User $user): array
    {
        return DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $user->id)->orderBy('id')->pluck('type')->all();
    }

    public function test_the_right_people_hear_about_each_step(): void
    {
        $report = $this->caprWithTestTemplate();
        $preparer = $this->cdaStaff();
        $approver = $this->userWith(['branches:view_all', CdaAccess::VIEW, CdaAccess::APPROVE, CdaAccess::REVIEW]);
        $approverWithoutViewAll = $this->userWith([CdaAccess::VIEW, CdaAccess::APPROVE]);
        $inactive = $this->userWith(['branches:view_all', CdaAccess::VIEW, CdaAccess::APPROVE]);
        $inactive->update(['status' => 'inactive']);

        $this->actingAs($preparer)->putJson("/api/cda/reports/{$report->id}", ['fields' => [['field_key' => 'employment.employees_regular', 'value' => 2]]])->assertOk();
        $this->actingAs($preparer)->postJson("/api/cda/reports/{$report->id}/workflow", ['action' => 'submit_for_review'])->assertOk();

        $this->assertSame(['cda_approval_needed'], $this->noticesFor($approver));
        $this->assertSame([], $this->noticesFor($preparer), 'Never the person who acted.');
        $this->assertSame([], $this->noticesFor($approverWithoutViewAll));
        $this->assertSame([], $this->noticesFor($inactive));

        $this->actingAs($approver)->postJson("/api/cda/reports/{$report->id}/workflow", ['action' => 'return', 'reason' => 'Recount the employees'])->assertOk();
        $this->assertSame(['cda_report_returned'], $this->noticesFor($preparer));

        $notice = DB::table('notifications')->where('notifiable_id', $preparer->id)->sole();
        $data = json_decode($notice->data, true);
        $this->assertSame('/cda/capr?year=2026', $data['url']);
        $this->assertStringContainsString('Recount the employees', $data['body']);
        $this->assertSame($report->id, $data['data']['report_id']);
    }

    public function test_a_failed_action_sends_nothing(): void
    {
        $report = $this->caprWithTestTemplate();
        $approver = $this->userWith(['branches:view_all', CdaAccess::VIEW, CdaAccess::APPROVE]);

        $this->actingAs($this->cdaStaff())->postJson("/api/cda/reports/{$report->id}/workflow", ['action' => 'submit_for_review'])->assertStatus(422);

        $this->assertSame([], $this->noticesFor($approver));
    }

    public function test_a_restricted_report_tells_only_holders_of_its_view(): void
    {
        $period = $this->openYear();
        $fs = $this->report($period, 'financial_statements');
        $preparer = $this->cdaStaff([CdaAccess::VIEW_AUDIT]);
        $approverWithView = $this->userWith(['branches:view_all', CdaAccess::VIEW, CdaAccess::APPROVE, CdaAccess::VIEW_AUDIT]);
        $approverWithoutView = $this->userWith(['branches:view_all', CdaAccess::VIEW, CdaAccess::APPROVE]);

        app(WorkflowNotices::class)->approvalNeeded($fs, $preparer);

        $this->assertSame(['cda_approval_needed'], $this->noticesFor($approverWithView));
        $this->assertSame([], $this->noticesFor($approverWithoutView));
    }
}
