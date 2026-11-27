<?php

namespace App\Services\Amla\Cases;

use App\Models\AmlaCtrCandidate;
use App\Models\AmlaCtrSetAside;
use App\Services\Amla\Foundation\AmlaLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves set-aside asks still waiting from their candidate into their own
 * AmlaCtrSetAside row (AMLA-B7, from the AMLA-B6 re-review). A data fix:
 * idempotent (a candidate whose waiting ask already has a row is left),
 * writing only what the candidate itself holds (its reason and requester),
 * skipping and listing a candidate missing either, and logging each copy in
 * the AMLA log as a system change. Writes AMLA tables only. Run once by the
 * AMLA report migration; `amla:backfill-set-asides --dry-run` previews it.
 */
final class SetAsideBackfill
{
    /** @return array{copied: list<string>, skipped: list<array{reference: string, reason: string}>} */
    public function run(bool $dryRun): array
    {
        $result = ['copied' => [], 'skipped' => []];

        if (! Schema::hasTable('amla_ctr_set_asides')) {
            return $result;
        }

        $waiting = AmlaCtrCandidate::query()->where('status', AmlaCtrCandidate::SET_ASIDE_PENDING)
            ->whereNotExists(fn ($rows) => $rows->selectRaw('1')->from('amla_ctr_set_asides')
                ->whereColumn('amla_ctr_set_asides.ctr_candidate_id', 'amla_ctr_candidates.id')
                ->where('amla_ctr_set_asides.status', AmlaCtrSetAside::PENDING))
            ->orderBy('id')->get();

        foreach ($waiting as $candidate) {
            if (blank($candidate->not_reportable_reason) || $candidate->set_aside_requested_by === null) {
                $result['skipped'][] = ['reference' => $candidate->reference, 'reason' => 'The waiting ask has no recorded reason or requester.'];

                continue;
            }

            $result['copied'][] = $candidate->reference;

            if (! $dryRun) {
                DB::transaction(function () use ($candidate) {
                    $row = AmlaCtrSetAside::query()->create([
                        'ctr_candidate_id' => $candidate->id,
                        'reason' => $candidate->not_reportable_reason,
                        'status' => AmlaCtrSetAside::PENDING,
                        'pending_key' => $candidate->id,
                        'requested_by' => $candidate->set_aside_requested_by,
                    ]);
                    app(AmlaLog::class)->record('set_aside_backfilled', null, 'ctr_candidate', $candidate->id, ['set_aside_id' => $row->id]);
                });
            }
        }

        return $result;
    }
}
