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
 * 1. each version waiting for its rescreen is screened against everyone, then
 *    marked done;
 * 2. members, walk-ins and co-makers added or changed since the last run are
 *    screened against every list in force, read like the harvester reads its
 *    sources: up to a few seconds ago, from an overlap before the watermark.
 *
 * Subjects are existing records only: current and former members (never
 * pending or rejected registrations), walk-ins, and co-makers who aren't
 * members themselves (a member co-maker is screened as the member). Matches
 * at or above the threshold are stored as pending, once per subject and
 * listing; nothing is confirmed, blocked or frozen by this.
 */
final class Screener
{
    public const LAG_SECONDS = 5;

    public const OVERLAP_SECONDS = 120;

    private const CHUNK = 500;

    /** @var array<string, class-string> the subject tables the every-minute pass reads */
    private const TABLES = ['borrowers' => Borrower::class, 'gcash_non_members' => GCashNonMember::class, 'co_makers' => CoMaker::class];

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
     * One run. Null while monitoring is off.
     *
     * @return array{rescreened_versions: int, screened: int, matches: int}|null
     */
    public function run(?Carbon $now = null): ?array
    {
        if (! $this->monitoring->enabled()) {
            return null;
        }

        $now ??= Carbon::now();
        $result = ['rescreened_versions' => 0, 'screened' => 0, 'matches' => 0];

        foreach ($this->pendingRescreens() as $version) {
            if ($version->status === AmlaWatchlistVersion::ACTIVE) {
                $index = $this->index(collect([$version]));

                foreach (array_keys(self::TABLES) as $table) {
                    $this->subjects($table)->chunkById(self::CHUNK, function (Collection $chunk) use ($index, $table, &$result): void {
                        foreach ($chunk as $subject) {
                            $result['screened']++;
                            $result['matches'] += $this->screen($table, $subject, $index);
                        }
                    });
                }
            }

            $version->forceFill(['rescreen_status' => AmlaWatchlistVersion::RESCREEN_DONE, 'rescreened_at' => now()])->save();
            $result['rescreened_versions']++;
        }

        $upper = $now->copy()->subSeconds(self::LAG_SECONDS);
        $active = AmlaWatchlistVersion::query()->where('status', AmlaWatchlistVersion::ACTIVE)->get();
        $index = $active->isEmpty() ? null : $this->index($active);

        foreach (array_keys(self::TABLES) as $table) {
            $cursor = AmlaScreeningCursor::query()->where('subject_table', $table)->first();

            if ($cursor === null) {
                AmlaScreeningCursor::query()->create(['subject_table' => $table, 'watermark' => $upper]);

                continue;
            }

            if ($upper->lte($cursor->watermark)) {
                continue;
            }

            if ($index !== null) {
                $this->subjects($table)
                    ->where("{$table}.updated_at", '>', $cursor->watermark->copy()->subSeconds(self::OVERLAP_SECONDS))
                    ->where("{$table}.updated_at", '<=', $upper)
                    ->chunkById(self::CHUNK, function (Collection $chunk) use ($index, $table, &$result): void {
                        foreach ($chunk as $subject) {
                            $result['screened']++;
                            $result['matches'] += $this->screen($table, $subject, $index);
                        }
                    }, "{$table}.id", 'id');
            }

            $cursor->forceFill(['watermark' => $upper])->save();
        }

        if ($result['rescreened_versions'] > 0 || $result['matches'] > 0) {
            $this->log->record('screening_run', null, details: $result);
        }

        return $result;
    }

    /** @return Builder<Model> */
    private function subjects(string $table): Builder
    {
        return match ($table) {
            'borrowers' => Borrower::query()->members()->select(['borrowers.id', 'first_name', 'middle_name', 'last_name', 'suffix', 'birthdate', 'borrowers.updated_at']),
            'gcash_non_members' => GCashNonMember::query()->select(['id', 'full_name', 'updated_at']),
            'co_makers' => CoMaker::query()->whereNull('member_borrower_id')->select(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'updated_at']),
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
        [$type, $names, $birthYear] = self::describe($table, $subject);
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

        $stored = 0;

        foreach (array_keys($candidates) as $entryId) {
            $entry = $index['entries'][$entryId];
            $scored = $this->matcher->score($names, $birthYear, $entry->names(), $entry->birth_years);

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
                    'match_key' => mb_substr("{$type}:{$subject->getKey()}:{$source}:{$entry->fingerprint}", 0, 120),
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
     * A subject's type, its names to compare, and its birth year when known.
     * A member or co-maker with a middle name is compared with and without it.
     *
     * @return array{0: string, 1: list<string>, 2: int|null}
     */
    public static function describe(string $table, mixed $subject): array
    {
        if ($table === 'gcash_non_members') {
            return ['walk_in', [(string) $subject->full_name], null];
        }

        $full = trim(implode(' ', array_filter([$subject->first_name, $subject->middle_name, $subject->last_name, $subject->suffix])));
        $short = trim(implode(' ', array_filter([$subject->first_name, $subject->last_name])));
        $birthYear = $table === 'borrowers' && $subject->birthdate !== null ? (int) Carbon::parse($subject->birthdate)->format('Y') : null;

        return [$table === 'borrowers' ? 'member' : 'co_maker', array_values(array_unique(array_filter([$full, $short]))), $birthYear];
    }
}
