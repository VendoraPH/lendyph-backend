<?php

namespace App\Services\Amla\Screening;

use App\Models\AmlaScreeningCursor;
use App\Models\AmlaScreeningMatch;
use App\Models\AmlaWatchlistEntry;
use App\Models\AmlaWatchlistVersion;
use App\Models\Borrower;
use App\Models\CoMaker;
use App\Models\GCashNonMember;
use App\Services\Amla\Foundation\AmlaLog;
use App\Services\Amla\Foundation\AmlaMonitoring;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Screens people against the watchlists (AMLA design §4.8, §4.11), run every
 * minute by `amla:screen` while AMLA monitoring is on:
 * 1. each version waiting for its rescreen is screened against everyone,
 *    table by table in id order. A run stops after TIME_LIMIT_SECONDS (or a
 *    given number of subjects) and saves where it stopped on the version, so
 *    the next run resumes there; the version is done once every table is;
 * 2. members, walk-ins and co-makers added or changed since the last run are
 *    screened against every list in force, read like the harvester reads its
 *    sources: up to a few seconds ago, from an overlap before the watermark.
 *    A minute in which nothing changed reads no watchlist entries at all: the
 *    name index is built only when a subject needs it.
 *
 * Subjects are existing records only: current and former members (never
 * pending or rejected registrations), walk-ins, and co-makers who aren't
 * members themselves (a member co-maker is screened as the member). Matches
 * at or above the threshold are stored as pending, once per subject, listing
 * and the subject's own compared details (names and birth year), so a match
 * someone cleared is looked at again when those details change. Nothing is
 * confirmed, blocked or frozen by this.
 */
final class Screener
{
    public const LAG_SECONDS = 5;

    public const OVERLAP_SECONDS = 120;

    /** How long one run may screen before it saves its place; well under the schedule's 10-minute lock. */
    public const TIME_LIMIT_SECONDS = 45;

    private const CHUNK = 500;

    /** @var list<string> the subject tables, in the order a rescreen walks them */
    private const TABLES = ['borrowers', 'gcash_non_members', 'co_makers'];

    private float $deadline = 0.0;

    private ?int $budget = null;

    private int $spent = 0;

    public function __construct(
        private readonly Matcher $matcher,
        private readonly AmlaMonitoring $monitoring,
        private readonly AmlaLog $log,
    ) {}

    /** @return Collection<int, AmlaWatchlistVersion> versions waiting for their rescreen, oldest first */
    public function pendingRescreens(): Collection
    {
        return AmlaWatchlistVersion::query()->where('rescreen_status', AmlaWatchlistVersion::RESCREEN_PENDING)->orderBy('id')->get();
    }

    /**
     * One run. Null while monitoring is off. `$budget` caps the subjects one
     * run screens (the time limit always applies).
     *
     * @return array{rescreened_versions: int, screened: int, matches: int, stopped: bool}|null
     */
    public function run(?Carbon $now = null, ?int $budget = null): ?array
    {
        if (! $this->monitoring->enabled()) {
            return null;
        }

        $now ??= Carbon::now();
        $this->deadline = microtime(true) + self::TIME_LIMIT_SECONDS;
        $this->budget = $budget;
        $this->spent = 0;
        $result = ['rescreened_versions' => 0, 'screened' => 0, 'matches' => 0, 'stopped' => false];

        foreach ($this->pendingRescreens() as $version) {
            if ($version->status !== AmlaWatchlistVersion::ACTIVE) {
                $this->finish($version);

                continue;
            }

            if (! $this->rescreen($version, $result)) {
                $result['stopped'] = true;

                break;
            }

            $this->finish($version);
            $result['rescreened_versions']++;
        }

        if (! $result['stopped']) {
            $this->changedSubjects($now, $result);
        }

        if ($result['rescreened_versions'] > 0 || $result['matches'] > 0) {
            $this->log->record('screening_run', null, details: array_diff_key($result, ['stopped' => true]));
        }

        return $result;
    }

    /**
     * Screens everyone against one version from where its last run stopped.
     * False when this run ran out of time first (its place is saved).
     *
     * @param  array{rescreened_versions: int, screened: int, matches: int, stopped: bool}  $result
     */
    private function rescreen(AmlaWatchlistVersion $version, array &$result): bool
    {
        $index = null;
        $start = array_search($version->rescreen_table, self::TABLES, true);
        $tables = array_slice(self::TABLES, $start === false ? 0 : $start);

        foreach ($tables as $position => $table) {
            $after = $position === 0 && $start !== false ? (int) $version->rescreen_after_id : 0;

            while (true) {
                $chunk = $this->subjects($table)->where("{$table}.id", '>', $after)->orderBy("{$table}.id")->limit(self::CHUNK)->get();

                if ($chunk->isEmpty()) {
                    break;
                }

                foreach ($chunk as $subject) {
                    if ($this->outOfTime()) {
                        $version->forceFill(['rescreen_table' => $table, 'rescreen_after_id' => $after])->save();

                        return false;
                    }

                    $index ??= $this->index(collect([$version]));
                    $result['screened']++;
                    $result['matches'] += $this->screen($table, $subject, $index);
                    $after = (int) $subject->getKey();
                }
            }
        }

        return true;
    }

    /** @param  array{rescreened_versions: int, screened: int, matches: int, stopped: bool}  $result */
    private function changedSubjects(Carbon $now, array &$result): void
    {
        $upper = $now->copy()->subSeconds(self::LAG_SECONDS);
        $index = null;
        $active = null;

        foreach (self::TABLES as $table) {
            $cursor = AmlaScreeningCursor::query()->where('subject_table', $table)->first();

            if ($cursor === null) {
                AmlaScreeningCursor::query()->create(['subject_table' => $table, 'watermark' => $upper]);

                continue;
            }

            if ($upper->lte($cursor->watermark)) {
                continue;
            }

            $changed = $this->subjects($table)
                ->where("{$table}.updated_at", '>', $cursor->watermark->copy()->subSeconds(self::OVERLAP_SECONDS))
                ->where("{$table}.updated_at", '<=', $upper);

            if ($changed->clone()->exists()) {
                $active ??= AmlaWatchlistVersion::query()->where('status', AmlaWatchlistVersion::ACTIVE)->get();

                if ($active->isNotEmpty()) {
                    $index ??= $this->index($active);

                    $changed->chunkById(self::CHUNK, function (Collection $chunk) use ($index, $table, &$result): void {
                        foreach ($chunk as $subject) {
                            $result['screened']++;
                            $result['matches'] += $this->screen($table, $subject, $index);
                        }
                    }, "{$table}.id", 'id');
                }
            }

            $cursor->forceFill(['watermark' => $upper])->save();
        }
    }

    private function finish(AmlaWatchlistVersion $version): void
    {
        $version->forceFill([
            'rescreen_status' => AmlaWatchlistVersion::RESCREEN_DONE,
            'rescreen_table' => null,
            'rescreen_after_id' => null,
            'rescreened_at' => now(),
        ])->save();
    }

    private function outOfTime(): bool
    {
        $over = ($this->budget !== null && $this->spent >= $this->budget) || microtime(true) > $this->deadline;

        if (! $over) {
            $this->spent++;
        }

        return $over;
    }

    /** @return Builder<Model> */
    private function subjects(string $table): Builder
    {
        return match ($table) {
            'borrowers' => Borrower::query()->members()->select(['borrowers.id', 'first_name', 'middle_name', 'last_name', 'suffix', 'birthdate', 'borrowers.updated_at']),
            'gcash_non_members' => GCashNonMember::query()->select(['gcash_non_members.id', 'full_name', 'gcash_non_members.updated_at']),
            'co_makers' => CoMaker::query()->whereNull('member_borrower_id')->select(['co_makers.id', 'first_name', 'middle_name', 'last_name', 'suffix', 'co_makers.updated_at']),
        };
    }

    /**
     * The entries of the given versions, by every word of their names and the
     * long words with one letter dropped, so a candidate one letter off is
     * found without comparing everyone with everything.
     *
     * @param  Collection<int, AmlaWatchlistVersion>  $versions
     * @return array{entries: array<int, AmlaWatchlistEntry>, keys: array<string, list<int>>, sources: array<int, string>}
     */
    private function index(Collection $versions): array
    {
        $entries = [];
        $keys = [];
        $sources = $versions->pluck('source', 'id')->all();

        AmlaWatchlistEntry::query()->whereIn('version_id', $versions->pluck('id'))->orderBy('id')
            ->chunk(self::CHUNK, function (Collection $chunk) use (&$entries, &$keys): void {
                foreach ($chunk as $entry) {
                    $entries[$entry->id] = $entry;

                    foreach ($entry->names() as $name) {
                        foreach (NameNormaliser::tokens($name) as $word) {
                            foreach (Matcher::lookupKeys($word) as $key) {
                                $keys[$key][$entry->id] = $entry->id;
                            }
                        }
                    }
                }
            });

        return ['entries' => $entries, 'keys' => array_map('array_values', $keys), 'sources' => $sources];
    }

    /**
     * Screens one subject; stores each new match. The number stored.
     *
     * @param  array{entries: array<int, AmlaWatchlistEntry>, keys: array<string, list<int>>, sources: array<int, string>}  $index
     */
    private function screen(string $table, mixed $subject, array $index): int
    {
        ['type' => $type, 'names' => $names, 'birth_year' => $birthYear, 'surname' => $surname] = self::describe($table, $subject);
        $candidates = [];

        foreach ($names as $name) {
            foreach (NameNormaliser::tokens($name) as $word) {
                foreach (Matcher::lookupKeys($word) as $key) {
                    foreach ($index['keys'][$key] ?? [] as $entryId) {
                        $candidates[$entryId] = true;
                    }
                }
            }
        }

        $details = self::detailsHash($names, $birthYear);
        $stored = 0;

        foreach (array_keys($candidates) as $entryId) {
            $entry = $index['entries'][$entryId];
            $scored = $this->matcher->score($names, $birthYear, $entry->names(), $entry->birth_years, $surname);

            if ($scored['score'] < Matcher::THRESHOLD) {
                continue;
            }

            $source = $index['sources'][$entry->version_id];

            try {
                AmlaScreeningMatch::query()->create([
                    'subject_type' => $type,
                    'subject_id' => $subject->getKey(),
                    'entry_id' => $entry->id,
                    'version_id' => $entry->version_id,
                    'source' => $source,
                    'match_key' => mb_substr("{$type}:{$subject->getKey()}:{$source}:{$entry->fingerprint}:{$details}", 0, 120),
                    'score' => $scored['score'],
                    'threshold' => Matcher::THRESHOLD,
                    'reasons' => $scored['reasons'],
                    'status' => AmlaScreeningMatch::PENDING,
                ]);
                $stored++;
            } catch (QueryException $exception) {
                if (($exception->errorInfo[1] ?? null) !== 1062) {
                    throw $exception;
                }
            }
        }

        return $stored;
    }

    /**
     * The subject's compared details, hashed: a change of name or birth year
     * gives the same listing a new match key.
     *
     * @param  list<string>  $names
     */
    public static function detailsHash(array $names, ?int $birthYear): string
    {
        $normalised = array_map(fn (string $name) => implode(' ', NameNormaliser::tokens($name)), $names);
        sort($normalised);

        return substr(hash('sha256', json_encode([array_values(array_unique($normalised)), $birthYear])), 0, 16);
    }

    /**
     * A subject's type, its names to compare, its birth year when known, and
     * its surname. A member or co-maker with a middle name is compared with
     * and without it.
     *
     * @return array{type: string, names: list<string>, birth_year: int|null, surname: string|null}
     */
    public static function describe(string $table, mixed $subject): array
    {
        if ($table === 'gcash_non_members') {
            return ['type' => 'walk_in', 'names' => [(string) $subject->full_name], 'birth_year' => null, 'surname' => null];
        }

        $full = trim(implode(' ', array_filter([$subject->first_name, $subject->middle_name, $subject->last_name, $subject->suffix])));
        $short = trim(implode(' ', array_filter([$subject->first_name, $subject->last_name])));
        $birthYear = $table === 'borrowers' && $subject->birthdate !== null ? (int) Carbon::parse($subject->birthdate)->format('Y') : null;

        return [
            'type' => $table === 'borrowers' ? 'member' : 'co_maker',
            'names' => array_values(array_unique(array_filter([$full, $short]))),
            'birth_year' => $birthYear,
            'surname' => filled($subject->last_name) ? (string) $subject->last_name : null,
        ];
    }
}
