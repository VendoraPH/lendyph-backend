<?php

namespace Tests\Feature\Cda\Reports;

use App\Models\AuditLog;
use App\Models\CdaReport;
use App\Models\CdaReportValue;
use App\Models\User;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Reports\Rational;
use App\Services\Cda\Reports\ReportEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OverflowException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The C2 re-review's Lows: a restricted report's workflow reason stays out
 * of its audit row but reaches the notice and the snapshot (L-A); a reason
 * is a notice's argument, never its format (L-B); toFixed refuses an
 * overflowing multiply (L-C); recompute leaves a report that isn't a draft
 * alone (L-D).
 */
class CdaReviewRoundTwoTest extends TestCase
{
    use CdaReportTestHelpers, SetupLendyPH;

    private User $preparer;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2027-01-15 10:00:00', 'Asia/Manila'));
        $this->brandAs('Binhi Multi-Purpose Cooperative');
        $this->member(['gender' => 'male']);
        $this->preparer = $this->cdaStaff([CdaAccess::VIEW_MEDIATION]);
        $this->reviewer = $this->cdaStaff([CdaAccess::VIEW_MEDIATION]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function act(CdaReport $report, string $action, array $body, User $as)
    {
        return $this->actingAs($as)->postJson("/api/cda/reports/{$report->id}/workflow", ['action' => $action, ...$body]);
    }

    private function notices(User $user): array
    {
        return DB::table('notifications')->where('notifiable_id', $user->id)->get()->map(fn ($row) => json_decode($row->data, true))->all();
    }

    public function test_l_a_a_restricted_reports_reason_stays_out_of_its_audit_row_but_reaches_the_notice(): void
    {
        $this->activeTemplate('mediation', [
            'schema_version' => 1, 'meta' => ['verified' => true], 'parts' => ['h1', 'h2'],
            'sections' => [['key' => 'summary', 'title' => 'Summary', 'kind' => 'fields', 'fields' => [
                ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
            ]]],
            'rules' => [],
        ]);
        $report = $this->report($this->openYear(), 'mediation', 'h1');
        $this->act($report, 'submit_for_review', [], $this->preparer)->assertOk();
        $reason = 'Recheck the complaint of Juan Dela Cruz';

        $this->act($report, 'return', ['reason' => $reason], $this->reviewer)->assertOk();

        $row = AuditLog::query()->where('auditable_type', CdaReport::class)->where('auditable_id', $report->id)->where('action', 'cda_workflow')->latest('id')->first();
        $this->assertSame('return', $row->new_values['action']);
        $this->assertArrayNotHasKey('reason', $row->new_values);
        $this->assertStringNotContainsString('Juan', (string) $row->description);
        $this->assertStringContainsString($reason, $this->notices($this->preparer)[0]['body']);
    }

    public function test_l_a_another_reports_reason_is_still_audited(): void
    {
        $report = $this->caprWithTestTemplate();
        $this->actingAs($this->preparer)->putJson("/api/cda/reports/{$report->id}", ['fields' => [['field_key' => 'employment.employees_regular', 'value' => 2]]])->assertOk();
        $this->act($report, 'submit_for_review', [], $this->preparer)->assertOk();

        $this->act($report, 'return', ['reason' => 'Count the casual staff too'], $this->reviewer)->assertOk();

        $row = AuditLog::query()->where('auditable_id', $report->id)->where('action', 'cda_workflow')->latest('id')->first();
        $this->assertSame('Count the casual staff too', $row->new_values['reason']);
    }

    public function test_l_b_a_reason_with_percent_signs_reaches_the_notice_as_written(): void
    {
        $report = $this->caprWithTestTemplate();
        $this->actingAs($this->preparer)->putJson("/api/cda/reports/{$report->id}", ['fields' => [['field_key' => 'employment.employees_regular', 'value' => 2]]])->assertOk();
        $this->act($report, 'submit_for_review', [], $this->preparer)->assertOk();

        $this->act($report, 'return', ['reason' => '100% of %s rows are off by %d'], $this->reviewer)->assertOk();

        $this->assertStringEndsWith('Reason: 100% of %s rows are off by %d', $this->notices($this->preparer)[0]['body']);
    }

    public function test_l_c_to_fixed_refuses_an_overflowing_multiply(): void
    {
        $third = Rational::of('50000000000000002')->divide(Rational::of(3));

        $this->expectException(OverflowException::class);
        $third->toFixed(2);
    }

    public function test_l_d_recompute_leaves_a_report_that_isnt_a_draft_alone(): void
    {
        $report = $this->caprWithTestTemplate();
        $stale = CdaReport::query()->findOrFail($report->id);
        CdaReport::query()->whereKey($report->id)->update(['status' => CdaReport::STATUS_FOR_REVIEW, 'readiness' => 12]);
        CdaReportValue::query()->where('report_id', $report->id)->where('field_key', 'employment.employees_regular')->delete();
        CdaReportValue::query()->create(['report_id' => $report->id, 'field_key' => 'employment.employees_regular', 'value' => 4]);
        CdaReportValue::query()->create(['report_id' => $report->id, 'field_key' => 'employment.payroll', 'value' => '1000.00']);

        app(ReportEngine::class)->recompute($stale);

        $this->assertNull(CdaReportValue::query()->where('report_id', $report->id)->where('field_key', 'employment.payroll_per_employee')->value('value'));
        $this->assertSame(12, CdaReport::query()->findOrFail($report->id)->readiness);
    }
}
