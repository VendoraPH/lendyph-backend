<?php

namespace App\Services\Cda\Registers;

use App\Http\Resources\Cda\RegisterPresenter;
use App\Models\CdaDocument;
use App\Models\CdaSubmission;
use App\Models\User;
use App\Services\Cda\ReportTypes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Proof of a filing with CDA (CDA design §3): one document per submission
 * (category `submission_proof`, the submission as its subject, in the
 * report's period), each upload a new version that becomes the final one.
 *
 * `present()` gives the submission list (slice C2) its proof: the final
 * version with a signed `url`, or null.
 */
final class SubmissionProofs
{
    public function __construct(private readonly DocumentRepository $documents) {}

    public function upload(CdaSubmission $submission, UploadedFile $file, User $user, ?string $notes = null): CdaDocument
    {
        $report = $submission->report()->with('period')->firstOrFail();

        return DB::transaction(function () use ($submission, $report, $file, $user, $notes): CdaDocument {
            CdaSubmission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();
            $document = $this->document($submission);

            if ($document === null || $document->status === 'withdrawn') {
                return $this->documents->create([
                    'period_id' => $report->period_id,
                    'category' => DocumentCategories::SUBMISSION_PROOF,
                    'report_id' => $report->id,
                    'subject_type' => 'submission',
                    'subject_id' => $submission->id,
                    'title' => 'Proof of submission: '.ReportTypes::title($report->report_type).($report->part === ReportTypes::ANNUAL ? '' : ', '.ReportTypes::PART_TITLES[$report->part]),
                ], $file, $user, notes: $notes, final: true);
            }

            $this->documents->addVersion($document, $file, $user, notes: $notes, final: true);

            return $document;
        });
    }

    /** @return array<string, mixed>|null the final proof, with its signed `url` */
    public function present(CdaSubmission $submission): ?array
    {
        $document = $this->document($submission);
        $final = $document?->versions()->with('file')->whereNotNull('final_key')->first();

        return $final === null ? null : ['cda_document_id' => (int) $document->id, ...RegisterPresenter::version($final)];
    }

    /** The submission's active proof document, else its latest. */
    private function document(CdaSubmission $submission): ?CdaDocument
    {
        return CdaDocument::query()
            ->where('subject_type', 'submission')->where('subject_id', $submission->id)
            ->where('category', DocumentCategories::SUBMISSION_PROOF)
            ->orderByRaw("status = 'active' desc")->orderByDesc('id')
            ->first();
    }
}
