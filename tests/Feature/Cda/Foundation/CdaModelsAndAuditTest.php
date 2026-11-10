<?php

namespace Tests\Feature\Cda\Foundation;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\CdaDocument;
use App\Models\CdaDocumentVersion;
use App\Models\CdaMediationCase;
use App\Models\CdaReport;
use App\Models\CdaReportTemplate;
use App\Models\CdaSocialProgram;
use App\Services\AuditLogModules;
use App\Services\GeneratedNumberWrite;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The CDA models (CDA design §1): every one has a factory and files its audit
 * rows under `cda`; a mediation case's number is `MC-YYYY-NNNN` by its filing
 * year, retried through GeneratedNumberWrite, and none of its text reaches
 * the audit rows; the Audit Trail reads the new member and CDA fields in
 * words.
 */
class CdaModelsAndAuditTest extends TestCase
{
    use SetupLendyPH;

    private const MODELS = [
        'CdaRegulatoryProfile', 'CdaSetting', 'CdaReportTemplate', 'CdaReportingPeriod', 'CdaReport', 'CdaReportValue',
        'CdaReportSnapshot', 'CdaSubmission', 'CdaDocument', 'CdaDocumentVersion', 'CdaOfficer', 'CdaOfficerTraining',
        'CdaSocialProgram', 'CdaMediationCase', 'CdaNoticeSent',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_every_cda_model_has_a_factory_and_files_under_cda(): void
    {
        foreach (self::MODELS as $name) {
            $class = 'App\\Models\\'.$name;
            $model = $class::factory()->create();
            $this->assertTrue($model->exists, $name);
            $this->assertSame('cda', AuditLogModules::for('updated', $class), $name);
        }
    }

    public function test_a_mediation_case_number_follows_its_filing_year_and_its_text_never_reaches_the_audit(): void
    {
        $first = CdaMediationCase::factory()->create(['filed_on' => '2026-02-03']);
        $second = CdaMediationCase::factory()->create(['filed_on' => '2026-05-06']);
        $next = CdaMediationCase::factory()->create(['filed_on' => '2027-01-10']);

        $this->assertSame('MC-2026-0001', $first->case_number);
        $this->assertSame('MC-2026-0002', $second->case_number);
        $this->assertSame('MC-2027-0001', $next->case_number);

        $case = CdaMediationCase::factory()->create([
            'filed_on' => '2026-08-01', 'complainant' => 'Juana Secret', 'respondent' => 'Pedro Hidden',
            'nature' => 'Loan dispute over collateral', 'description' => 'Private details', 'mediator' => 'Atty. Mediator',
            'resolution' => 'Settled privately', 'remarks' => 'Sensitive remark',
        ]);
        $case->update(['complainant' => 'Juana Secret II', 'status' => 'resolved', 'resolved_on' => '2026-09-01']);

        $rows = AuditLog::query()->where('auditable_type', CdaMediationCase::class)->where('auditable_id', $case->id)->get();
        $this->assertCount(2, $rows);
        $text = $rows->map(fn (AuditLog $row) => json_encode([$row->old_values, $row->new_values, $row->description]))->implode(' ');

        foreach (['Juana', 'Pedro', 'collateral', 'Private', 'Mediator', 'privately', 'Sensitive'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
        $this->assertStringContainsString('resolved', $text);
    }

    public function test_a_mediation_documents_texts_never_reach_the_audit_and_a_normal_documents_title_does(): void
    {
        $byCategory = CdaDocument::factory()->create(['category' => 'mediation', 'title' => 'Settlement Juana Secret v Pedro Hidden', 'description' => 'Minutes naming the parties']);
        $bySubject = CdaDocument::factory()->create(['category' => 'other', 'subject_type' => 'case', 'subject_id' => 7, 'title' => 'Juana Secret agreement', 'description' => 'Minutes of Pedro Hidden']);
        $normal = CdaDocument::factory()->create(['category' => 'capr', 'title' => 'Signed CAPR 2026', 'description' => 'Board copy']);

        foreach ([$byCategory, $bySubject, $normal] as $document) {
            $document->update(['title' => $document->title.' (signed)', 'status' => 'withdrawn', 'withdraw_reason' => 'Replaced: Juana Secret asked']);
            CdaDocumentVersion::factory()->create(['cda_document_id' => $document->id, 'version_label' => 'Juana Secret copy', 'notes' => 'Pedro Hidden signed']);
        }

        foreach ([$byCategory, $bySubject] as $document) {
            $text = $this->auditTextOf($document);
            $this->assertStringContainsString('withdrawn', $text);

            foreach (['Juana', 'Pedro', 'Minutes'] as $secret) {
                $this->assertStringNotContainsString($secret, $text, "Document #{$document->id}");
            }
        }

        $normalText = $this->auditTextOf($normal);
        $this->assertStringContainsString('Signed CAPR 2026', $normalText);
        $this->assertStringContainsString('Pedro Hidden signed', $normalText, "A normal document's version notes stay.");
    }

    private function auditTextOf(CdaDocument $document): string
    {
        $versionIds = CdaDocumentVersion::query()->where('cda_document_id', $document->id)->pluck('id');

        return AuditLog::query()
            ->where(fn ($q) => $q->where(fn ($d) => $d->where('auditable_type', CdaDocument::class)->where('auditable_id', $document->id))
                ->orWhere(fn ($v) => $v->where('auditable_type', CdaDocumentVersion::class)->whereIn('auditable_id', $versionIds)))
            ->get()
            ->map(fn (AuditLog $row) => json_encode([$row->old_values, $row->new_values, $row->description], JSON_UNESCAPED_UNICODE))
            ->implode(' ');
    }

    public function test_a_deleted_cases_number_is_never_reused(): void
    {
        CdaMediationCase::factory()->create(['filed_on' => '2026-02-03']);
        $newest = CdaMediationCase::factory()->create(['filed_on' => '2026-03-04']);
        $this->assertSame('MC-2026-0002', $newest->case_number);

        $newest->delete();

        $this->assertSoftDeleted($newest);
        $this->assertSame('MC-2026-0003', CdaMediationCase::factory()->create(['filed_on' => '2026-04-05'])->case_number);
    }

    public function test_a_taken_case_number_is_a_generated_number_collision(): void
    {
        CdaMediationCase::factory()->create(['filed_on' => '2026-02-03']);

        try {
            CdaMediationCase::query()->insert(['case_number' => 'MC-2026-0001', 'filed_on' => '2026-02-04', 'status' => 'pending', 'meetings_count' => 0]);
            $this->fail('The duplicate number was accepted.');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertTrue(GeneratedNumberWrite::isCollision($e));
        }

        $this->assertSame(GeneratedNumberWrite::MEDIATION_CASE_NUMBER_INDEX, 'cda_mediation_cases_case_number_unique');
        $this->assertTrue(Str::contains(GeneratedNumberWrite::MEDIATION_CONFLICT, 'mediation case'));
    }

    public function test_the_audit_trail_reads_the_new_fields_in_words(): void
    {
        $member = $this->changes(Borrower::class, ['member_type' => 'regular'], ['member_type' => 'associate']);
        $report = $this->changes(CdaReport::class, ['report_type' => 'capr', 'part' => 'annual'], ['report_type' => 'atir', 'part' => 'h1']);
        $program = $this->changes(CdaSocialProgram::class, ['budget' => '1000.00', 'actual_expense' => '0.00', 'funding_source' => 'general_fund'], ['budget' => '2500.5', 'actual_expense' => '1200.00', 'funding_source' => 'cdf']);
        $template = $this->changes(CdaReportTemplate::class, ['status' => 'draft'], ['status' => 'active']);

        $this->assertSame(['Regular', 'Associate'], [$member['member_type']['old'], $member['member_type']['new']]);
        $this->assertSame('ATIR', $report['report_type']['new']);
        $this->assertSame('CAPR', $report['report_type']['old']);
        $this->assertSame('First half (January–June)', $report['part']['new']);
        $this->assertSame('₱2,500.50', $program['budget']['new']);
        $this->assertSame('₱1,200.00', $program['actual_expense']['new']);
        $this->assertSame('CDF', $program['funding_source']['new']);
        $this->assertSame('active', $template['status']['new'], 'Statuses stay raw for the screen.');
    }

    /** @return array<string, array<string, mixed>> */
    private function changes(string $type, array $old, array $new): array
    {
        $row = AuditLog::create(['action' => 'updated', 'auditable_type' => $type, 'auditable_id' => 1, 'old_values' => $old, 'new_values' => $new]);

        return collect($this->getJson("/api/audit-logs/{$row->id}")->assertOk()->json('data.changes'))->keyBy('field')->all();
    }
}
