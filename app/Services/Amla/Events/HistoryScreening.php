<?php

namespace App\Services\Amla\Events;

use App\Models\AmlaHarvestCursor;
use App\Services\Amla\Foundation\AmlaLog;
use App\Services\Amla\Foundation\AmlaMonitoring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * `amla:screen-history`, run only on counsel's advice (AMLA design §7 Q5):
 * turns the money rows recorded from a date up to each source's go-live into
 * events, as the harvester would have, marked `history`. A back-run may
 * surface past covered transactions that were never reported, so it is a
 * deliberate step, never automatic.
 *
 * Writes only AMLA tables, and is idempotent: a row already harvested is
 * left alone. `--dry-run` writes nothing at all and counts what it would add.
 * A real run logs one system row in the AMLA log with its counts.
 */
final class HistoryScreening
{
    public function __construct(
        private readonly AmlaMonitoring $monitoring,
        private readonly EventSources $sources,
        private readonly EventWriter $writer,
        private readonly AmlaLog $log,
    ) {}

    /**
     * @return array<string, array{words: string, until: string, rows: int, added: int, existing: int, reversed: int, failed: int}>
     *
     * @throws ValidationException in words, when it can't run
     */
    public function run(string $from, bool $dryRun): array
    {
        if (! $this->monitoring->enabled()) {
            throw ValidationException::withMessages(['monitoring' => ['AMLA monitoring is off, so no history is screened.']]);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || ! checkdate((int) substr($from, 5, 2), (int) substr($from, 8, 2), (int) substr($from, 0, 4))) {
            throw ValidationException::withMessages(['from' => ['Give the first day to screen as YYYY-MM-DD.']]);
        }

        $date = Carbon::parse($from.' 00:00:00', config('app.timezone'));

        $cursors = AmlaHarvestCursor::query()->pluck('started_at', 'source');
        $sources = $this->sources->all();

        foreach ($sources as $source) {
            if (($cursors[$source->name()] ?? null) === null) {
                throw ValidationException::withMessages(['monitoring' => [
                    "AMLA monitoring has not started for {$source->words()} yet. Let the harvester run once first, so history and live monitoring meet with no gap.",
                ]]);
            }
        }

        $results = [];

        foreach ($sources as $source) {
            $until = Carbon::parse($cursors[$source->name()]);
            $rows = $source->rows()
                ->where($source->recordedColumn(), '>=', $date)
                ->where($source->recordedColumn(), '<', $until);
            $counts = $dryRun
                ? $this->writer->write($source, $rows, 'history', dryRun: true)
                : DB::transaction(fn () => $this->writer->write($source, $rows, 'history'));
            $results[$source->name()] = ['words' => $source->words(), 'until' => $until->format('Y-m-d H:i:s'), ...$counts];
        }

        if (! $dryRun) {
            $this->log->record('history_screened', null, details: [
                'from' => $from,
                'sources' => array_map(fn (array $line) => array_diff_key($line, ['words' => true]), $results),
            ]);
        }

        return $results;
    }
}
