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
 * every run; the nightly coverage check lists anything missed as Unscreened.
 */
final class Harvester
{
    public const LAG_SECONDS = 5;

    public const OVERLAP_SECONDS = 120;

    /** Failures tried again per source per run. */
    public const RETRIES_PER_RUN = 200;

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

        $retry = $this->openFailures($source);

        if ($retry !== []) {
            $again = $this->writer->write($source, $source->rows()->whereIn($source->idColumn(), $retry), 'monitor');
            $counts = [
                'rows' => $counts['rows'],
                'added' => $counts['added'] + $again['added'],
                'existing' => $counts['existing'],
                'reversed' => $counts['reversed'] + $again['reversed'],
                'failed' => $counts['failed'],
            ];
        }

        $cursor->watermark = $upper;
        $cursor->save();

        return ['started' => false, ...$counts];
    }

    /**
     * Rows that failed before and still have no event, oldest first, at most
     * RETRIES_PER_RUN.
     *
     * @return list<int>
     */
    private function openFailures(EventSource $source): array
    {
        $open = [];

        AmlaEventFailure::query()->where('source', $source->name())->orderBy('id')->select(['id', 'source_id'])
            ->chunkById(EventWriter::CHUNK, function ($failures) use ($source, &$open) {
                $ids = $failures->pluck('source_id')->map(fn ($id) => (int) $id)->all();
                $open = [...$open, ...array_values(array_diff($ids, $source->coveredIds($ids)))];

                return count($open) < self::RETRIES_PER_RUN;
            });

        return array_slice($open, 0, self::RETRIES_PER_RUN);
    }
}
