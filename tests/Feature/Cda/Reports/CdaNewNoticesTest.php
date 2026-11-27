<?php

namespace Tests\Feature\Cda\Reports;

use App\Models\CdaNoticeSent;
use App\Models\CdaReport;
use App\Models\User;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Reminders\DeadlineReminders;
use App\Services\Cda\Reports\WorkflowNotices;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Two CDA notices (gap round B6):
 * - `cda_submission_recorded`, after a filing is recorded, to `cda:view`
 *   holders with view_all, never the person who recorded it;
 * - `cda_missing_requirement`, from the nightly run, for an open report whose
 *   stored readiness is below 100% with required fields missing, to
 *   `cda:prepare` holders, at most once in 7 days per report.
 */
class CdaNewNoticesTest extends TestCase
{
    use CdaReportTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2027-01-15 10:00:00', 'Asia/Manila'));
        $this->brandAs('Binhi Multi-Purpose Cooperative');
        $this->member(['gender' => 'male']);
        $this->member(['gender' => 'female']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<array<string, mixed>> */
    private function notices(User $user, string $type): array
    {
        return $user->notifications()->where('type', $type)->orderBy('id')->get()->map(fn ($notice) => $notice->data)->all();
    }

    public function test_a_recorded_filing_tells_everyone_who_can_see_the_report_but_the_recorder(): void
    {
        $preparer = $this->cdaStaff();
        $approver = $this->cdaStaff();
        $viewer = $this->userWith(['branches:view_all', CdaAccess::VIEW]);
        $noViewAll = $this->userWith([CdaAccess::VIEW]);
        $report = $this->caprWithTestTemplate();
        $act = fn (string $action, User $as, array $body = []) => $this->actingAs($as)->postJson("/api/cda/reports/{$report->id}/workflow", ['action' => $action, ...$body]);

        $this->actingAs($preparer)->putJson("/api/cda/reports/{$report->id}", ['fields' => [['field_key' => 'employment.employees_regular', 'value' => 5]]])->assertOk();
        $act('submit_for_review', $preparer)->assertOk();
        $act('approve', $approver)->assertOk();
        $act('finalize', $preparer, ['confirm_warnings' => true])->assertOk();
        $this->assertSame([], $this->notices($viewer, WorkflowNotices::SUBMISSION_RECORDED));

        $act('mark_submitted', $preparer, ['submission' => ['method' => 'cais', 'submitted_on' => '2027-01-14']])->assertOk();

        $sent = $this->notices($viewer, WorkflowNotices::SUBMISSION_RECORDED);
        $this->assertCount(1, $sent);
        $this->assertSame('CDA filing recorded', $sent[0]['title']);
        $this->assertSame('Cooperative Annual Progress Report (CAPR) 2026 was recorded as filed with CDA by '.$preparer->full_name.' (filed on Jan 14, 2027).', $sent[0]['body']);
        $this->assertSame('2027-01-14', $sent[0]['data']['submitted_on']);
        $this->assertCount(1, $this->notices($approver, WorkflowNotices::SUBMISSION_RECORDED));
        $this->assertSame([], $this->notices($preparer, WorkflowNotices::SUBMISSION_RECORDED));
        $this->assertSame([], $this->notices($noViewAll, WorkflowNotices::SUBMISSION_RECORDED));
    }

    public function test_an_incomplete_report_tells_its_preparers_at_most_once_a_week(): void
    {
        $preparer = $this->userWith(['branches:view_all', CdaAccess::VIEW, CdaAccess::PREPARE]);
        $approverOnly = $this->userWith(['branches:view_all', CdaAccess::VIEW, CdaAccess::APPROVE]);
        $period = $this->openYear();
        CdaReport::query()->where('period_id', $period->id)->update(['readiness' => 100, 'readiness_breakdown' => json_encode(['passed' => 4, 'total' => 4, 'failed' => []])]);
        $capr = $this->report($period, 'capr');
        $capr->forceFill(['readiness' => 92, 'readiness_breakdown' => ['passed' => 46, 'total' => 50, 'failed' => ['required:general.a', 'required:general.b', 'required:membership.c', 'required:employment.d', 'approval']]])->save();
        $onlyApproval = $this->report($period, 'social_audit');
        $onlyApproval->forceFill(['readiness' => 90, 'readiness_breakdown' => ['passed' => 9, 'total' => 10, 'failed' => ['approval']]])->save();

        $run = function (string $date): void {
            Carbon::setTestNow(Carbon::parse($date.' 00:50:00', 'Asia/Manila'));
            app(DeadlineReminders::class)->run();
        };

        $run('2027-01-15');
        $sent = $this->notices($preparer, DeadlineReminders::MISSING_TYPE);
        $this->assertCount(1, $sent, 'Only the report with required fields missing.');
        $this->assertSame('Cooperative Annual Progress Report (CAPR) 2026 is 92% complete. 4 required fields are still missing.', $sent[0]['body']);
        $this->assertSame($capr->id, $sent[0]['data']['report_id']);
        $this->assertSame(4, $sent[0]['data']['missing_required']);
        $this->assertSame([], $this->notices($approverOnly, DeadlineReminders::MISSING_TYPE));

        $run('2027-01-15');
        $run('2027-01-21');
        $this->assertCount(1, $this->notices($preparer, DeadlineReminders::MISSING_TYPE), 'Not again within 7 days.');

        $run('2027-01-22');
        $this->assertCount(2, $this->notices($preparer, DeadlineReminders::MISSING_TYPE));
        $this->assertSame(['2027-01-15', '2027-01-22'], CdaNoticeSent::query()->where('kind', 'missing_requirement')->orderBy('id')->pluck('dedupe_key')->all());

        $capr->forceFill(['readiness' => 100, 'readiness_breakdown' => ['passed' => 50, 'total' => 50, 'failed' => []]])->save();
        $run('2027-01-30');
        $this->assertCount(2, $this->notices($preparer, DeadlineReminders::MISSING_TYPE), 'A complete report gets none.');
    }

    public function test_the_command_line_counts_the_calendar_items_and_the_incomplete_report_notices(): void
    {
        $this->userWith(['branches:view_all', CdaAccess::VIEW, CdaAccess::PREPARE]);
        $period = $this->openYear();
        CdaReport::query()->where('period_id', $period->id)->update(['readiness' => 100, 'readiness_breakdown' => json_encode(['passed' => 4, 'total' => 4, 'failed' => []])]);
        $this->report($period, 'capr')->forceFill(['readiness' => 92, 'readiness_breakdown' => ['passed' => 46, 'total' => 50, 'failed' => ['required:general.a']]])->save();

        Carbon::setTestNow(Carbon::parse('2027-01-15 00:50:00', 'Asia/Manila'));

        $this->artisan('cda:send-reminders')
            ->expectsOutputToContain('0 calendar items checked, 1 incomplete-report notice sent.')
            ->assertSuccessful();
    }
}
