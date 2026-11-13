<?php

namespace App\Services\Cda\Registers;

use App\Models\AuditLog;
use App\Models\CdaDocument;
use App\Models\CdaOfficerTraining;
use App\Models\CdaReportingPeriod;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A training's documents follow the training.
 *
 * A training's documents are filed under its own period, the one nearest its
 * year (completed, else started, else this year), and its `documents_count`
 * reads that period. When the year changes, each document of the training
 * not linked to a report moves to the new period, one by one, so each move
 * writes its own audit row. A document linked to a report is never moved: it
 * belongs to that report's period.
 *
 * The data fix (run()) applies the same rule to trainings already saved with
 * a mismatch, as a system change: one audit row per move, with no user and
 * no address. The 2026_11_14_110000 migration runs it on each deployment, and
 * `cda:refile-training-documents --dry-run` previews a database first. A
 * moved document is in its training's period, so running it again changes
 * nothing. A training with no completed or started date is left alone and
 * listed (its period would follow today's date), as is a document that
 * changes while the fix runs.
 */
final class TrainingDocumentRefiler
{
    public function __construct(private readonly DocumentRepository $documents) {}

    /** The year a training is filed under: completed, else started, else this year. */
    public static function year(CdaOfficerTraining $training): int
    {
        return ($training->completed_on ?? $training->started_on ?? now('Asia/Manila'))->year;
    }

    /** The training's own period id, or null when no period exists. */
    public function periodIdFor(CdaOfficerTraining $training): ?int
    {
        return $this->documents->nearestPeriodIds([$training->id => self::year($training)])[$training->id];
    }

    /**
     * Move the training's unlinked documents into its own period, each with
     * its Auditable row (the signed-in user). Run inside the caller's
     * transaction, with the training row locked.
     *
     * @return int how many documents moved
     */
    public function refile(CdaOfficerTraining $training): int
    {
        $periodId = $this->periodIdFor($training);

        if ($periodId === null) {
            return 0;
        }

        $moved = 0;

        foreach ($this->misfiled($training->id, $periodId)->orderBy('id')->lockForUpdate()->get() as $document) {
            $document->period_id = $periodId;
            $document->save();
            $moved++;
        }

        return $moved;
    }

    /**
     * The data fix: every training whose unlinked documents sit outside its
     * own period, moved as a system change, or with `$dryRun` only listed.
     *
     * @return array{moved: list<array{document_id: int, training_id: int, from: int, to: int}>, skipped: list<array{training_id: int, reason: string}>}
     */
    public function run(bool $dryRun): array
    {
        $result = ['moved' => [], 'skipped' => []];
        $years = CdaReportingPeriod::query()->pluck('year', 'id');

        $trainingIds = CdaDocument::query()->where('subject_type', 'training')->whereNull('report_id')
            ->distinct()->orderBy('subject_id')->pluck('subject_id');

        foreach ($trainingIds->chunk(200) as $chunk) {
            $trainings = CdaOfficerTraining::query()->whereIn('id', $chunk->all())->get()->keyBy('id');

            foreach ($chunk as $trainingId) {
                $training = $trainings->get($trainingId);

                if ($training === null) {
                    continue;
                }

                if ($training->completed_on === null && $training->started_on === null) {
                    $result['skipped'][] = ['training_id' => (int) $training->id, 'reason' => 'it has no completed or started date, so its year is not known'];

                    continue;
                }

                $periodId = $this->periodIdFor($training);

                if ($periodId === null) {
                    $result['skipped'][] = ['training_id' => (int) $training->id, 'reason' => 'no reporting period exists'];

                    continue;
                }

                foreach ($this->misfiled($training->id, $periodId)->orderBy('id')->get(['id', 'period_id']) as $document) {
                    $move = ['document_id' => (int) $document->id, 'training_id' => (int) $training->id, 'from' => (int) $years[$document->period_id], 'to' => (int) $years[$periodId]];

                    if ($dryRun || $this->moveAsSystem((int) $document->id, (int) $training->id, (int) $document->period_id, $periodId, (int) $years[$periodId])) {
                        $result['moved'][] = $move;

                        continue;
                    }

                    $result['skipped'][] = ['training_id' => (int) $training->id, 'reason' => "document #{$document->id} changed while the fix ran"];
                    Log::warning('A training document changed while the refile ran; it was left as it is.', ['document' => (int) $document->id, 'training' => (int) $training->id]);
                }
            }
        }

        return $result;
    }

    /**
     * Move one document as a system change. Re-checked under the training's
     * and the document's locks (in that order, as the training update takes
     * them): a document linked to a report since, moved, or whose training's
     * period changed is left as it now is.
     */
    private function moveAsSystem(int $documentId, int $trainingId, int $fromPeriodId, int $toPeriodId, int $toYear): bool
    {
        return DB::transaction(function () use ($documentId, $trainingId, $fromPeriodId, $toPeriodId, $toYear): bool {
            $training = CdaOfficerTraining::query()->whereKey($trainingId)->lockForUpdate()->first();
            $document = CdaDocument::query()->whereKey($documentId)->lockForUpdate()->first();

            if ($training === null || $document === null || $document->report_id !== null
                || $document->period_id !== $fromPeriodId || $this->periodIdFor($training) !== $toPeriodId) {
                return false;
            }

            AuditLogService::withoutModelAuditing(function () use ($document, $toPeriodId): void {
                $document->period_id = $toPeriodId;
                $document->save();
            });

            AuditLog::query()->create([
                'user_id' => null,
                'action' => 'updated',
                'auditable_type' => $document->getMorphClass(),
                'auditable_id' => $document->id,
                'old_values' => ['period_id' => $fromPeriodId],
                'new_values' => ['period_id' => $toPeriodId],
                'ip_address' => null,
                'user_agent' => null,
                'description' => "System change: the document was filed under the {$toYear} reporting period with its training.",
            ]);

            return true;
        });
    }

    /** @return Builder<CdaDocument> */
    private function misfiled(int $trainingId, int $periodId): Builder
    {
        return CdaDocument::query()
            ->where('subject_type', 'training')
            ->where('subject_id', $trainingId)
            ->whereNull('report_id')
            ->where('period_id', '!=', $periodId);
    }
}
