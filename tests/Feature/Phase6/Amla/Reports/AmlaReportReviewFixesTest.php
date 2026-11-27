<?php

namespace Tests\Feature\Phase6\Amla\Reports;

use App\Models\AmlaAlert;
use App\Models\AmlaCase;
use App\Models\AmlaCtrCandidate;
use App\Models\AmlaCtrSetAside;
use App\Models\AmlaExport;
use App\Models\AmlaLogEntry;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * PR #348 security review: monitoring counts only alerts the viewer may see,
 * exporting needs `amla:export` (re-checked on download with the report's
 * keys, head office and the filtered branch), audit history refuses rather
 * than truncates, export files are swept once their link expires, the
 * management summary gates case and STR figures, the CSV formula guard, the
 * set-aside backfill, and exports free of case and decision reasons.
 */
class AmlaReportReviewFixesTest extends TestCase
{
    use ReportTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startCases();
        Storage::fake('private');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function revoke(User $user, string $permission): User
    {
        $user->roles()->first()->revokePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    public function test_monitoring_counts_only_alerts_the_viewer_may_see(): void
    {
        $member = $this->member();
        foreach ([$this->branch->id, Branch::factory()->create()->id, Branch::factory()->create()->id] as $branchId) {
            $this->event($member, '1000.00', ['branch_id' => $branchId, 'channel' => 'bank_transfer']);
        }
        $this->detect();
        $this->assertSame(1, AmlaAlert::query()->where('rule_code', 'J')->count());

        $rows = $this->actingAs($this->amlaUser(['amla:alerts']))->getJson('/api/amla/compliance-reports/transaction_monitoring?from=2026-11-01&to=2026-11-30')->json('data.rows');
        $this->assertSame(['0'], array_column($rows, 'flagged'));
        $this->assertSame(['—'], array_column($rows, 'categories'));

        $head = $this->actingAs($this->headOffice(['amla:alerts']))->getJson('/api/amla/compliance-reports/transaction_monitoring?from=2026-11-01&to=2026-11-30')->json('data.totals');
        $this->assertSame('3', $head['flagged']);
    }

    public function test_exporting_needs_amla_export_and_losing_any_key_or_head_office_closes_the_link(): void
    {
        $this->actingAs($this->amlaUser(['amla:alerts']))->postJson('/api/amla/compliance-reports/transaction_monitoring/exports', ['format' => 'csv'])->assertForbidden();

        $loseExport = $this->amlaUser(['amla:alerts', 'amla:export']);
        $url = $this->actingAs($loseExport)->postJson('/api/amla/compliance-reports/transaction_monitoring/exports', ['format' => 'csv'])->assertCreated()->json('data.download_url');
        $this->actingAs($this->revoke($loseExport, 'amla:export'))->get($url, ['Accept' => 'application/json'])->assertForbidden();

        $loseOne = $this->amlaUser(['amla:alerts', 'amla:ctr', 'amla:export']);
        $url = $this->actingAs($loseOne)->postJson('/api/amla/compliance-reports/transaction_monitoring/exports', ['format' => 'csv'])->assertCreated()->json('data.download_url');
        $this->actingAs($this->revoke($loseOne, 'amla:alerts'))->get($url, ['Accept' => 'application/json'])->assertForbidden();

        $loseHead = $this->headOffice(['amla:cases', 'amla:export']);
        $url = $this->actingAs($loseHead)->postJson('/api/amla/compliance-reports/case_register/exports', ['format' => 'csv'])->assertCreated()->json('data.download_url');
        $this->actingAs($this->revoke($loseHead, 'branches:view_all'))->get($url, ['Accept' => 'application/json'])->assertForbidden();

        $other = Branch::factory()->create();
        $moved = $this->amlaUser(['amla:ctr', 'amla:export']);
        $moved->branches()->sync([$this->branch->id, $other->id]);
        $url = $this->actingAs($moved->fresh())->postJson('/api/amla/compliance-reports/covered_transactions/exports', ['format' => 'csv', 'branch_id' => $other->id])->assertCreated()->json('data.download_url');
        $moved->branches()->sync([$this->branch->id]);
        $this->actingAs($moved->fresh())->get($url, ['Accept' => 'application/json'])->assertForbidden();

        $this->assertSame(4, AmlaLogEntry::query()->where('action', 'access_denied')->where('details->reason', 'like', 'export%')->count());
    }

    public function test_the_audit_history_refuses_a_period_too_big_for_one_report(): void
    {
        config(['amla.audit_report_max_rows' => 3]);
        $auditor = $this->headOffice(['amla:audit', 'amla:export']);
        $this->actingAs($auditor);
        foreach (range(1, 4) as $i) {
            $this->getJson('/api/amla/compliance-reports');
            AmlaLogEntry::query()->create(['action' => 'log_viewed', 'actor_type' => 'user', 'user_id' => $auditor->id]);
        }

        $this->getJson('/api/amla/compliance-reports/audit_history?from=2026-11-01&to=2026-11-30')->assertUnprocessable()
            ->assertJsonPath('errors.from.0', fn (string $message) => str_starts_with($message, 'Narrow the dates: this period has ') && str_contains($message, 'at most 3'));
        $this->postJson('/api/amla/compliance-reports/audit_history/exports', ['format' => 'csv', 'from' => '2026-11-01', 'to' => '2026-11-30'])->assertUnprocessable();
        $this->assertSame(0, AmlaExport::query()->count());
    }

    public function test_an_export_file_is_swept_once_its_link_expires_and_its_record_stays(): void
    {
        $user = $this->amlaUser(['amla:alerts', 'amla:export']);
        $url = $this->actingAs($user)->postJson('/api/amla/compliance-reports/transaction_monitoring/exports', ['format' => 'csv'])->assertCreated()->json('data.download_url');
        $export = AmlaExport::query()->sole();

        $this->artisan('amla:prune-exports')->assertSuccessful();
        Storage::disk('private')->assertExists($export->file_path);

        $this->travelTo(Carbon::parse('2026-11-20 10:45:00', 'Asia/Manila'));
        $this->artisan('amla:prune-exports')->assertSuccessful();
        Storage::disk('private')->assertMissing($export->file_path);
        $this->assertSame($export->sha256, AmlaExport::query()->sole()->sha256);
    }

    public function test_the_management_summary_shows_case_and_str_figures_only_to_their_holders(): void
    {
        $this->openCase($this->headOffice(['amla:cases']));

        $plain = collect($this->actingAs($this->headOffice(['amla:view']))->getJson('/api/amla/compliance-reports/management_summary')->json('data.rows'))->pluck('metric')->all();
        $this->assertNotContains('Open investigations', $plain);
        $this->assertNotContains('STR filing status', $plain);
        $this->assertContains('Transactions monitored', $plain);

        $full = collect($this->actingAs($this->headOffice(['amla:view', 'amla:cases', 'amla:str']))->getJson('/api/amla/compliance-reports/management_summary')->json('data.rows'))->pluck('metric')->all();
        $this->assertContains('Open investigations', $full);
        $this->assertContains('STR filing status', $full);
    }

    public function test_csv_exports_write_formula_like_cells_as_text_and_hold_no_case_or_decision_reasons(): void
    {
        $officer = $this->headOffice(['amla:cases', 'amla:approve', 'amla:export']);
        $id = $this->actingAs($officer)->postJson('/api/amla/cases', ['title' => '=HYPERLINK("http://x.test","click")', 'borrower_id' => $this->member()->id])->assertCreated()->json('data.id');
        $this->postJson("/api/amla/cases/{$id}/notes", ['body' => 'PRIVATE NOTE TEXT'])->assertCreated();
        $this->actingAs($this->headOffice(['amla:cases', 'amla:approve']))->postJson("/api/amla/cases/{$id}/decision", ['decision' => 'no_str', 'reason' => 'PRIVATE DECISION REASON'])->assertOk();

        $url = $this->actingAs($officer)->postJson('/api/amla/compliance-reports/case_register/exports', ['format' => 'csv'])->assertCreated()->json('data.download_url');
        $body = $this->get($url)->assertOk()->streamedContent();

        $this->assertStringContainsString("\"'=HYPERLINK(\"\"http://x.test\"\",\"\"click\"\")\"", $body);
        $this->assertStringNotContainsString('PRIVATE NOTE TEXT', $body);
        $this->assertStringNotContainsString('PRIVATE DECISION REASON', $body);
        $this->assertSame(AmlaCase::CLOSED_NO_REPORT, AmlaCase::query()->findOrFail($id)->status);
    }

    public function test_the_set_aside_backfill_previews_copies_once_and_logs_a_system_change(): void
    {
        $candidate = $this->ctrCandidate();
        $requester = $this->amlaUser(['amla:ctr']);
        AmlaCtrCandidate::query()->whereKey($candidate->id)->toBase()->update([
            'status' => AmlaCtrCandidate::SET_ASIDE_PENDING, 'not_reportable_reason' => encrypt('Exempt transfer.', false),
            'set_aside_requested_by' => $requester->id, 'status_changed_by' => $requester->id,
        ]);

        $this->artisan('amla:backfill-set-asides', ['--dry-run' => true])->expectsOutputToContain('Would copy 1')->assertSuccessful();
        $this->assertSame(0, AmlaCtrSetAside::query()->count());

        $this->artisan('amla:backfill-set-asides')->expectsOutputToContain('Copied 1')->assertSuccessful();
        $this->artisan('amla:backfill-set-asides')->expectsOutputToContain('Copied 0')->assertSuccessful();

        $row = AmlaCtrSetAside::query()->sole();
        $this->assertSame(['Exempt transfer.', $requester->id, 'pending', $candidate->id], [$row->reason, $row->requested_by, $row->status, $row->pending_key]);
        $log = AmlaLogEntry::query()->where('action', 'set_aside_backfilled')->sole();
        $this->assertSame(['system', null, $candidate->id], [$log->actor_type, $log->user_id, $log->subject_id]);
    }
}
