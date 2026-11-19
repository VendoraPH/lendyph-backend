<?php

namespace App\Services\Amla\Events;

use App\Models\AmlaEvent;
use App\Models\AmlaEventFailure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Writes what source rows become, for the harvester, the nightly sweep and the
 * history run: each event once (the (source, source_id, kind) key, checked
 * first and held by the unique index), each reversal mark once, and each row
 * that can't be built once as a failure. A failure's later tries move its
 * `last_attempted_at`, and it is resolved once the row builds. Writes only
 * AMLA tables. With `$dryRun` it writes nothing and only counts.
 */
final class EventWriter
{
    public const CHUNK = 500;

    /**
     * @return array{rows: int, added: int, existing: int, reversed: int, failed: int}
     */
    public function write(EventSource $source, Builder $rows, string $harvestedBy, bool $dryRun = false): array
    {
        $counts = ['rows' => 0, 'added' => 0, 'existing' => 0, 'reversed' => 0, 'failed' => 0];
        $now = Carbon::now();

        $rows->chunkById(self::CHUNK, function ($chunk) use ($source, $harvestedBy, $dryRun, $now, &$counts) {
            $ids = $chunk->pluck('id')->map(fn ($id) => (int) $id)->all();
            $existing = $this->existingKeys($source->eventSource(), $ids);
            $failures = AmlaEventFailure::query()->where('source', $source->name())->whereIn('source_id', $ids)->get()->keyBy('source_id');

            foreach ($chunk as $row) {
                $counts['rows']++;
                $failure = $failures->get((int) $row->id);

                try {
                    $built = $source->build($row, $harvestedBy);
                } catch (EventCannotBeBuilt|InvalidArgumentException $refused) {
                    $counts['failed']++;

                    if ($dryRun) {
                        continue;
                    }

                    if ($failure === null) {
                        $failures->put((int) $row->id, AmlaEventFailure::query()->create([
                            'source' => $source->name(),
                            'source_id' => (int) $row->id,
                            'reason' => mb_substr($refused->getMessage(), 0, 500),
                            'harvested_by' => $harvestedBy,
                            'last_attempted_at' => $now,
                        ]));
                    } else {
                        $failure->tried($now, resolved: false);
                    }

                    continue;
                }

                foreach ($built->events as $event) {
                    $key = self::key($event['source'], (int) $event['source_id'], $event['kind']);

                    if (isset($existing[$key])) {
                        $counts['existing']++;

                        continue;
                    }

                    if (! $dryRun) {
                        AmlaEvent::query()->create($event);
                    }

                    $existing[$key] = true;
                    $counts['added']++;
                }

                foreach ($built->reversals as $reversal) {
                    $counts['reversed'] += $this->markReversed($reversal, $dryRun);
                }

                if (! $dryRun) {
                    $failure?->tried($now, resolved: true);
                }
            }
        }, $source->idColumn(), 'id');

        return $counts;
    }

    /**
     * @param  array{source: string, source_id: int, by: string, by_id: int, at: string}  $reversal
     */
    private function markReversed(array $reversal, bool $dryRun): int
    {
        $events = AmlaEvent::query()->where('source', $reversal['source'])->where('source_id', $reversal['source_id'])
            ->whereNull('reversed_at')->orderBy('id')->get();

        if (! $dryRun) {
            foreach ($events as $event) {
                $event->markReversed($reversal['by'], $reversal['by_id'], Carbon::parse($reversal['at']));
            }
        }

        return $events->count();
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, true>
     */
    private function existingKeys(string $source, array $ids): array
    {
        $keys = [];

        foreach (AmlaEvent::query()->where('source', $source)->whereIn('source_id', $ids)->get(['source_id', 'kind']) as $event) {
            $keys[self::key($source, $event->source_id, $event->kind)] = true;
        }

        return $keys;
    }

    private static function key(string $source, int $id, string $kind): string
    {
        return "{$source}|{$id}|{$kind}";
    }
}
