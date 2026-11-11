<?php

namespace Tests\Feature\Cda\Foundation;

use App\Models\AuditLog;
use App\Models\CdaDocument;
use App\Models\CdaDocumentVersion;
use App\Services\BranchAccess;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A mediation document stays out of the Audit Trail (C1 re-review, M1 and
 * L4): the row's target reads "Cda document #N", never the live title, for
 * the document and its versions, to a reader of the trail who can't see
 * mediation; and a document moved into or out of mediation keeps its titles
 * out of the rows of that move. An ordinary document keeps its title.
 */
class CdaMediationAuditLabelTest extends TestCase
{
    use CdaTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /** @return array<int, array<string, mixed>> the trail's rows for the document and its versions, by id */
    private function trailRows(CdaDocument $document): array
    {
        $versionIds = CdaDocumentVersion::query()->where('cda_document_id', $document->id)->pluck('id')->all();
        $ids = AuditLog::query()
            ->where(fn ($q) => $q->where(fn ($d) => $d->where('auditable_type', CdaDocument::class)->where('auditable_id', $document->id))
                ->orWhere(fn ($v) => $v->where('auditable_type', CdaDocumentVersion::class)->whereIn('auditable_id', $versionIds)))
            ->pluck('id')->all();

        $rows = collect($this->getJson('/api/audit-logs?per_page=100')->assertOk()->json('data'))->keyBy('id');

        return collect($ids)->map(fn (int $id) => $rows[$id] ?? null)->filter()->values()->all();
    }

    public function test_the_trail_names_a_mediation_document_and_its_versions_by_type_and_id_only(): void
    {
        $mediation = CdaDocument::factory()->create(['category' => 'mediation', 'title' => 'Juana Secret v Pedro Hidden']);
        CdaDocumentVersion::factory()->create(['cda_document_id' => $mediation->id]);
        $case = CdaDocument::factory()->create(['category' => 'other', 'subject_type' => 'case', 'subject_id' => 4, 'title' => 'Juana Secret agreement']);
        $normal = CdaDocument::factory()->create(['category' => 'capr', 'title' => 'Signed CAPR 2026']);

        $this->actingAs($this->userWith(['audit_logs:view', BranchAccess::VIEW_ALL]));

        foreach ([$mediation, $case] as $document) {
            $rows = $this->trailRows($document);
            $this->assertNotEmpty($rows);
            $text = json_encode($rows, JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('Juana', $text);
            $this->assertStringNotContainsString('Pedro', $text);
        }

        $labels = collect($this->trailRows($mediation))->pluck('target.label')->unique()->sort()->values()->all();
        $this->assertContains("Cda document #{$mediation->id}", $labels);
        $this->assertCount(2, $labels, 'The document and its version each read by type and id.');

        $this->assertSame(['Signed CAPR 2026'], collect($this->trailRows($normal))->pluck('target.label')->unique()->values()->all());
    }

    public function test_a_document_moved_into_and_out_of_mediation_keeps_its_titles_out_of_those_rows(): void
    {
        $document = CdaDocument::factory()->create(['category' => 'capr', 'title' => 'Board minutes']);

        $document->update(['category' => 'mediation', 'title' => 'Juana Secret hearing']);
        $document->update(['category' => 'other', 'title' => 'Pedro Hidden notes']);
        $document->update(['title' => 'Public notice']);

        $rows = AuditLog::query()->where('auditable_type', CdaDocument::class)->where('auditable_id', $document->id)->orderBy('id')->get();
        $this->assertCount(4, $rows);

        foreach ([1, 2] as $move) {
            $text = json_encode([$rows[$move]->old_values, $rows[$move]->new_values]);
            $this->assertStringNotContainsString('Juana', $text);
            $this->assertStringNotContainsString('Pedro', $text);
            $this->assertStringNotContainsString('Board minutes', $text);
        }

        $this->assertSame('Public notice', $rows[3]->new_values['title'], 'Out of mediation, the title is audited again.');
    }
}
