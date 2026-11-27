<?php

namespace Tests\Feature\Phase6\Amla\Reports;

use App\Enums\PaymentMethod;
use App\Models\AmlaAlert;
use App\Models\AmlaCase;
use App\Models\AmlaCtrCandidate;
use App\Models\AmlaEvent;
use App\Models\AmlaEventScreening;
use App\Models\AmlaLogEntry;
use App\Models\AmlaProfile;
use App\Models\AmlaReport;
use App\Models\Branch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The ten AMLA reports (task §16; AMLA-B7): each equals its source tables,
 * follows branch scope, needs its own key (the confidential ones head-office
 * access too), and every view is in the AMLA log. STR narratives never
 * appear.
 */
class AmlaComplianceReportsTest extends TestCase
{
    use ReportTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startCases();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_catalog_lists_the_ten_reports_each_open_only_to_its_keys(): void
    {
        $all = $this->headOffice(['amla:view', 'amla:alerts', 'amla:ctr', 'amla:str', 'amla:cdd', 'amla:screening', 'amla:cases', 'amla:audit']);
        $keys = array_column($this->actingAs($all)->getJson('/api/amla/compliance-reports')->assertOk()->json('data'), 'key');
        $this->assertSame([
            'covered_transactions', 'suspicious_transactions', 'transaction_monitoring', 'risk_assessment', 'customer_due_diligence',
            'watchlist_screening', 'case_register', 'submissions_register', 'audit_history', 'management_summary',
        ], $keys);

        $branchCtr = $this->amlaUser(['amla:ctr']);
        $this->actingAs($branchCtr);
        $this->assertSame(['covered_transactions', 'submissions_register'], array_column($this->getJson('/api/amla/compliance-reports')->json('data'), 'key'));
        $this->getJson('/api/amla/compliance-reports/suspicious_transactions')->assertForbidden();
        $this->getJson('/api/amla/compliance-reports/no_such_report')->assertNotFound();
        $this->actingAs($this->amlaUser(['amla:str']))->getJson('/api/amla/compliance-reports/suspicious_transactions')->assertForbidden();
        $this->actingAs($this->amlaUser(['amla:audit']))->getJson('/api/amla/compliance-reports/audit_history')->assertForbidden();
        $this->actingAs($this->amlaUser(['amla:view']))->getJson('/api/amla/compliance-reports/management_summary')->assertForbidden();
    }

    public function test_covered_transactions_equal_the_ctr_candidates_in_the_callers_branches(): void
    {
        $other = Branch::factory()->create();
        $mine = $this->ctrCandidate();
        $theirs = $this->ctrCandidate($this->member($other->id));
        $officer = $this->amlaUser(['amla:ctr']);

        $rows = $this->actingAs($officer)->getJson('/api/amla/compliance-reports/covered_transactions?from=2026-11-01&to=2026-11-30')->assertOk()->json('data.rows');
        $this->assertSame([$mine->reference], array_column($rows, 'reference'));
        $this->assertSame('₱650,000.00', $rows[0]['amount']);
        $this->assertSame('Detected', $rows[0]['filing_status']);
        $this->assertSame('Nov 27, 2026 (provisional)', $rows[0]['deadline']);

        $head = array_column($this->actingAs($this->headOffice(['amla:ctr']))->getJson('/api/amla/compliance-reports/covered_transactions?from=2026-11-01&to=2026-11-30')->json('data.rows'), 'reference');
        $this->assertEqualsCanonicalizing(AmlaCtrCandidate::query()->pluck('reference')->all(), $head);
        $this->assertContains($theirs->reference, $head);
        $this->assertSame(2, AmlaLogEntry::query()->where('action', 'amla_report_viewed')->count());
    }

    public function test_transaction_monitoring_equals_the_events_screenings_and_alerts_with_a_server_total(): void
    {
        $member = $this->member();
        $this->event($member, '300000.00');
        $this->event($member, '300000.00');
        $this->event($member, '100000.00', ['direction' => 'out', 'kind' => 'savings_withdrawal']);
        $this->detect();
        $this->event($member, '1000.00', ['channel' => 'bank_transfer']);

        $data = $this->actingAs($this->amlaUser(['amla:alerts']))->getJson('/api/amla/compliance-reports/transaction_monitoring?from=2026-11-01&to=2026-11-30')->assertOk()->json('data');
        $cash = collect($data['rows'])->firstWhere('channel', PaymentMethod::labelFor('cash'));
        $flagged = DB::table('amla_alert_events')->join('amla_events', 'amla_events.id', '=', 'amla_alert_events.event_id')
            ->where('amla_events.channel', 'cash')->distinct()->count('amla_alert_events.event_id');
        $this->assertSame(['3', '3', '0', (string) $flagged], [$cash['transactions'], $cash['screened'], $cash['unscreened'], $cash['flagged']]);
        $this->assertGreaterThanOrEqual(2, $flagged);
        $this->assertSame('1', collect($data['rows'])->firstWhere('channel', PaymentMethod::labelFor('bank_transfer'))['unscreened']);
        $this->assertSame('₱500,000.00', $cash['net']);
        $this->assertSame((string) AmlaEvent::query()->where('is_internal', false)->count(), $data['totals']['transactions']);
        $this->assertSame((string) AmlaEventScreening::query()->count(), $data['totals']['screened']);
    }

    public function test_the_confidential_reports_equal_their_sources_and_never_show_a_narrative(): void
    {
        $officer = $this->headOffice(['amla:cases', 'amla:str', 'amla:approve', 'amla:screening', 'amla:audit', 'amla:view', 'amla:ctr', 'amla:cdd', 'amla:alerts']);
        $reviewer = $this->headOffice(['amla:cases', 'amla:approve']);
        $strCase = $this->openCase($officer);
        $this->actingAs($reviewer)->postJson("/api/amla/cases/{$strCase->id}/decision", ['decision' => 'str', 'reason' => 'No business reason.', 'determination_date' => '2026-11-20'])->assertOk();
        $this->actingAs($officer)->postJson("/api/amla/cases/{$strCase->id}/str", ['narrative' => 'SECRET NARRATIVE TEXT'])->assertCreated();
        $this->openCase($officer);
        $member = $this->member();
        $this->pendingMatch($member);
        $this->actingAs($officer);

        $suspicious = $this->rows('suspicious_transactions', ['from' => '2026-11-01', 'to' => '2026-11-30']);
        $this->assertSame([$strCase->reference], array_column($suspicious, 'case'));
        $this->assertSame('Nov 23, 2026 (provisional)', $suspicious[0]['deadline']);
        $this->assertSame('Waiting for approval', $suspicious[0]['approval_status']);

        $register = $this->rows('case_register', ['from' => '2026-11-01', 'to' => '2026-11-30']);
        $this->assertEqualsCanonicalizing(AmlaCase::query()->pluck('reference')->all(), array_column($register, 'case'));

        $submissions = $this->rows('submissions_register', ['from' => '2026-11-01', 'to' => '2026-11-30']);
        $this->assertEqualsCanonicalizing(AmlaReport::query()->pluck('reference')->all(), array_column($submissions, 'reference'));

        $screening = $this->rows('watchlist_screening', ['from' => '2026-11-01', 'to' => '2026-11-30']);
        $this->assertSame(['Pending review'], array_column($screening, 'status'));
        $this->assertSame('Internal list 2026-11', $screening[0]['list_version']);

        $expected = AmlaLogEntry::query()->whereBetween('created_at', ['2026-11-01 00:00:00', '2026-11-30 23:59:59'])->count();
        $audit = $this->report('audit_history', ['from' => '2026-11-01', 'to' => '2026-11-30']);
        $this->assertSame($expected, count($audit['rows']));

        $summary = collect($this->rows('management_summary', ['from' => '2026-11-01', 'to' => '2026-11-30']))->pluck('value', 'metric');
        $this->assertSame((string) AmlaCase::query()->whereIn('status', AmlaCase::ACTIVE)->count(), $summary['Open investigations']);
        $this->assertSame('1', $summary['Outstanding watchlist reviews']);
        $this->assertSame((string) AmlaAlert::query()->whereIn('status', AmlaAlert::WORKABLE)->count(), $summary['Open alerts']);

        $everything = json_encode([$suspicious, $register, $submissions, $audit, $summary]);
        $this->assertStringNotContainsString('SECRET NARRATIVE', $everything);
    }

    public function test_risk_and_cdd_reports_cover_the_subjects_in_the_callers_branches(): void
    {
        $other = Branch::factory()->create();
        $mine = $this->member();
        $theirs = $this->member($other->id);
        $officer = $this->amlaUser(['amla:cdd']);
        $this->actingAs($this->headOffice(['amla:cdd']));
        foreach ([$mine, $theirs] as $member) {
            $this->putJson("/api/amla/cdd/members/{$member->id}", ['occupation' => 'Trader'])->assertOk();
        }

        $this->actingAs($officer);
        $risk = $this->rows('risk_assessment');
        $this->assertSame([(string) $mine->full_name], array_column($risk, 'customer'));
        $level = AmlaProfile::query()->where('borrower_id', $mine->id)->sole()->riskAssessments()->latest('id')->firstOrFail()->level;
        $this->assertSame(ucfirst($level), $risk[0]['risk_level']);

        $cdd = $this->rows('customer_due_diligence');
        $this->assertContains((string) $mine->full_name, array_column($cdd, 'customer'));
        $this->assertNotContains((string) $theirs->full_name, array_column($cdd, 'customer'));
    }
}
