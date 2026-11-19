<?php

namespace App\Services\Amla\Events;

use App\Models\AmlaEventFailure;
use App\Models\AmlaHarvestCursor;
use App\Services\Amla\Foundation\AmlaMonitoring;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The AMLA harvester (AMLA design §4.2–4.4), run every minute while
 * `AMLA_MONITORING_ENABLED` is on. Read only on the money tables: it writes
 * events, failures and its own cursors, nothing else.
 *
 * Like ChangeScanner, each run reads each source's rows recorded up to a few
 * seconds ago (LAG_SECONDS), from an overlap window before its watermark
 * (OVERLAP_SECONDS), so a transaction that committed late, stamped before the
 * last run, is still read. Reading a row twice adds nothing: events are keyed.
 * A source's events and its cursor move in one transaction, under a lock on
 * the cursor, so a failed run moves nothing and two runs never both write.
 *
 * The first run of a source only starts it (go-live): its watermark and
 * `started_at` are set and nothing recorded before is taken, except by
 * `amla:screen-history`. Rows it can't build are failures, tried again on
 * later runs, least recently tried first; the nightly coverage check lists anything missed as Unscreened.
 */
final class Harvester
{
    public const LAG_SECONDS = 5;

    public const OVERLAP_SECONDS = 120;

    public function __construct(
        private readonly AmlaMonitoring $monitoring,
        private readonly EventSources $sources,
        private readonly EventWriter $writer,
    ) {}

    /**
     * @return array<string, array{words: string, started: bool, rows: int, added: int, existing: int, reversed: int, failed: int}>|null
     *                                                                                                                                   null while monitoring is off
     */
    public function harvest(?CarbonInterface $now = null): ?array
    {
        if (! $this->monitoring->enabled()) {
            return null;
        }

        $upper = Carbon::instance($now ?? Carbon::now())->subSeconds(self::LAG_SECONDS);
        $results = [];

        foreach ($this->sources->all() as $source) {
            $results[$source->name()] = ['words' => $source->words(), ...DB::transaction(fn () => $this->harvestSource($source, $upper))];
        }

        return $results;
    }

    /**
     * @return array{started: bool, rows: int, added: int, existing: int, reversed: int, failed: int}
     */
    private function harvestSource(EventSource $source, Carbon $upper): array
    {
        $none = ['started' => false, 'rows' => 0, 'added' => 0, 'existing' => 0, 'reversed' => 0, 'failed' => 0];
        $cursor = AmlaHarvestCursor::query()->where('source', $source->name())->lockForUpdate()->first();

        if ($cursor === null) {
            AmlaHarvestCursor::query()->create(['source' => $source->name(), 'watermark' => $upper, 'started_at' => $upper]);

            return [...$none, 'started' => true];
        }

        if ($cursor->watermark === null || $upper->lte($cursor->watermark)) {
            return $none;
        }

        $rows = $source->rows()
            ->where($source->recordedColumn(), '>', $cursor->watermark->copy()->subSeconds(self::OVERLAP_SECONDS))
            ->where($source->recordedColumn(), '<=', $upper);
        $counts = $this->writer->write($source, $rows, 'monitor');

        $again = $this->retryFailures($source);
        $counts['added'] += $again['added'];
        $counts['reversed'] += $again['reversed'];

        $cursor->watermark = $upper;
        $cursor->save();

        return ['started' => false, ...$counts];
    }

    /**
     * Try failed rows again: the least recently tried first, at most
     * `amla.harvest_retries_per_run`, so no failure starves. Each keeps its
     * own `harvested_by` (a history failure stays history). A tried row that
     * no longer reads as a source row still has its try recorded, so it moves
     * to the back of the queue.
     *
     * @return array{added: int, reversed: int}
     */
    private function retryFailures(EventSource $source): array
    {
        $now = Carbon::now();
        $failures = AmlaEventFailure::query()
            ->where('source', $source->name())
            ->whereNull('resolved_at')
            ->orderByRaw('last_attempted_at is not null, last_attempted_at, id')
            ->limit((int) config('amla.harvest_retries_per_run', 200))
            ->get();
        $done = ['added' => 0, 'reversed' => 0];

        foreach ($failures->groupBy('harvested_by') as $harvestedBy => $group) {
            $again = $this->writer->write($source, $source->rows()->whereIn($source->idColumn(), $group->pluck('source_id')->all()), (string) $harvestedBy);
            $done['added'] += $again['added'];
            $done['reversed'] += $again['reversed'];
        }

        foreach ($failures as $failure) {
            $failure->refresh()->tried($now, resolved: false);
        }

        return $done;
    }
}
