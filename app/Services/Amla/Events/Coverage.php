<?php

namespace App\Services\Amla\Events;

use App\Models\AmlaCoverageCheck;
use App\Models\AmlaEventFailure;
use App\Models\AmlaHarvestCursor;
use Illuminate\Support\Carbon;

/**
 * The nightly AMLA coverage check (AMLA design §4.5): for one Manila day and
 * each source, the monitored rows recorded that day (from the source's
 * go-live), how many have their events, how many failed, and how many are
 * Unscreened: every row without its event, a failure or a gap the cursor
 * missed. Nothing counts as cleared until it has been screened. Pending rows
 * (a GCash Cash In not yet paid) are counted apart; they aren't money yet.
 *
 * Reads the money tables only; writes one AmlaCoverageCheck per source.
 */
final class Coverage
{
    public function __construct(private readonly EventSources $sources) {}

    /**
     * @param  string  $day  Y-m-d, a Manila day
     * @return array<string, array{rows: int, events: int, failed: int, unscreened: int, pending: int}>
     */
    public function check(string $day, bool $store = true): array
    {
        $start = Carbon::parse($day.' 00:00:00', config('app.timezone'));
        $end = $start->copy()->addDay();
        $cursors = AmlaHarvestCursor::query()->pluck('started_at', 'source');
        $results = [];

        foreach ($this->sources->all() as $source) {
            $startedAt = $cursors[$source->name()] ?? null;
            $results[$source->name()] = $startedAt === null
                ? ['rows' => 0, 'events' => 0, 'failed' => 0, 'unscreened' => 0, 'pending' => 0]
                : $this->count($source, $start->max(Carbon::parse($startedAt)), $end);
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

    /**
     * @return array{rows: int, events: int, failed: int, unscreened: int, pending: int}
     */
    private function count(EventSource $source, Carbon $from, Carbon $to): array
    {
        $rows = 0;
        $events = 0;
        $failed = 0;

        $source->rows()
            ->where($source->recordedColumn(), '>=', $from)
            ->where($source->recordedColumn(), '<', $to)
            ->chunkById(EventWriter::CHUNK, function ($chunk) use ($source, &$rows, &$events, &$failed) {
                $ids = $chunk->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all();
                $covered = $source->coveredIds($ids);
                $missing = array_values(array_diff($ids, $covered));

                $rows += count($ids);
                $events += count($covered);
                $failed += $missing === [] ? 0 : AmlaEventFailure::query()->where('source', $source->name())->whereIn('source_id', $missing)->count();
            }, $source->idColumn(), 'id');

        $pending = $source->pendingRows()?->where($source->recordedColumn(), '>=', $from)->where($source->recordedColumn(), '<', $to)->count() ?? 0;

        return ['rows' => $rows, 'events' => $events, 'failed' => $failed, 'unscreened' => $rows - $events, 'pending' => $pending];
    }
}
