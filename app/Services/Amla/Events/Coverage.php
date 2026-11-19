<?php

namespace App\Services\Amla\Events;

use App\Models\AmlaCoverageCheck;
use App\Models\AmlaEventFailure;
use App\Models\AmlaHarvestCursor;
use App\Services\Amla\Foundation\AmlaMonitoring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The nightly AMLA coverage check (AMLA design §4.5): for one Manila day and
 * each source, the monitored rows recorded that day (from the source's
 * go-live), how many have their events, how many failed, and how many are
 * Unscreened: every row without its event (a failure or a gap the cursor
 * missed) and every row whose event the AMLA detector hasn't screened yet.
 * Nothing counts as cleared until it has been screened. Pending rows
 * (a GCash Cash In not yet paid) are counted apart; they aren't money yet.
 *
 * When asked (the nightly command does) and only while monitoring is on,
 * it first sweeps the day: every monitored row recorded that day
 * goes through EventWriter, which adds only what is missing. That screens a
 * row whose transaction committed after the harvester's overlap had passed
 * (a long auto-credit or bulk share capital run stamps its first rows long
 * before it commits), as the credit scoring nightly rescore catches what its
 * scanner missed. The sweep holds the source's cursor lock, so it never runs
 * beside the minute harvester, and is idempotent.
 *
 * Reads the money tables only; writes AMLA events and failures, and one
 * AmlaCoverageCheck per source.
 */
final class Coverage
{
    public function __construct(
        private readonly EventSources $sources,
        private readonly EventWriter $writer,
        private readonly AmlaMonitoring $monitoring,
    ) {}

    /**
     * @param  string  $day  Y-m-d, a Manila day
     * @return array<string, array{rows: int, events: int, failed: int, unscreened: int, pending: int}>
     */
    public function check(string $day, bool $store = true, bool $sweep = false): array
    {
        $start = Carbon::parse($day.' 00:00:00', config('app.timezone'));
        $end = $start->copy()->addDay();
        $cursors = AmlaHarvestCursor::query()->pluck('started_at', 'source');
        $results = [];
        $sweep = $sweep && $this->monitoring->enabled();

        foreach ($this->sources->all() as $source) {
            $startedAt = $cursors[$source->name()] ?? null;

            if ($startedAt === null) {
                $results[$source->name()] = ['rows' => 0, 'events' => 0, 'failed' => 0, 'unscreened' => 0, 'pending' => 0];

                continue;
            }

            $from = $start->max(Carbon::parse($startedAt));

            if ($sweep) {
                $this->sweep($source, $from, $end);
            }

            $results[$source->name()] = $this->count($source, $from, $end);
        }

        if ($store) {
            foreach ($results as $name => $line) {
                AmlaCoverageCheck::query()->create([
                    'day' => $start->toDateString(),
                    'source' => $name,
                    'source_rows' => $line['rows'],
                    'events' => $line['events'],
                    'failed' => $line['failed'],
                    'unscreened' => $line['unscreened'],
                    'pending' => $line['pending'],
                ]);
            }
        }

        return $results;
    }

    /** Pass the window's monitored rows through EventWriter, under the source's cursor lock. */
    private function sweep(EventSource $source, Carbon $from, Carbon $to): void
    {
        DB::transaction(function () use ($source, $from, $to) {
            AmlaHarvestCursor::query()->where('source', $source->name())->lockForUpdate()->first();

            $this->writer->write($source, $source->rows()
                ->where($source->recordedColumn(), '>=', $from)
                ->where($source->recordedColumn(), '<', $to), 'monitor');
        });
    }

    /**
     * @return array{rows: int, events: int, failed: int, unscreened: int, pending: int}
     */
    private function count(EventSource $source, Carbon $from, Carbon $to): array
    {
        $rows = 0;
        $events = 0;
        $screened = 0;
        $failed = 0;

        $source->rows()
            ->where($source->recordedColumn(), '>=', $from)
            ->where($source->recordedColumn(), '<', $to)
            ->chunkById(EventWriter::CHUNK, function ($chunk) use ($source, &$rows, &$events, &$screened, &$failed) {
                $ids = $chunk->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all();
                $covered = $source->coveredIds($ids);
                $missing = array_values(array_diff($ids, $covered));

                $rows += count($ids);
                $events += count($covered);
                $screened += count($source->screenedIds($covered));
                $failed += $missing === [] ? 0 : AmlaEventFailure::query()->where('source', $source->name())->whereIn('source_id', $missing)->count();
            }, $source->idColumn(), 'id');

        $pending = $source->pendingRows()?->where($source->recordedColumn(), '>=', $from)->where($source->recordedColumn(), '<', $to)->count() ?? 0;

        return ['rows' => $rows, 'events' => $events, 'failed' => $failed, 'unscreened' => $rows - $screened, 'pending' => $pending];
    }
}
