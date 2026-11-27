<?php

namespace App\Services\Linda;

use App\Models\Borrower;
use App\Models\CdaOfficer;
use App\Models\CoMaker;
use App\Models\GCashNonMember;
use App\Models\User;
use Closure;
use Normalizer;
use UnexpectedValueException;

/**
 * The full-name check (Arvin, 2026-11-16, D2), run after the matcher, the
 * lookup parser and the pending-lookup rule, and before any model call:
 * whether a question names one of this deployment's people by full name, so
 * the name never reaches the model. People are members and staff users, and
 * co-makers, GCash walk-ins and CDA officers not linked to a member (the
 * officers register's free-text name), who Linda can't look up yet.
 *
 * - First and last name together, in either order ("Juan Dela Cruz",
 *   "Dela Cruz, Juan"), any case, extra spaces ignored. Between them, up to
 *   three words, each an initial ("A." or "A"), a word of the stored middle
 *   name, or a name-like word. First and last names of several words work
 *   ("Maria Clara Santos"), first and last names may be common words ("May",
 *   "Will", "Salamat", "Bulan"), and a dotted surname matches with or without its dot ("Sta.
 *   Maria"). A walk-in's or an unlinked CDA officer's free-text full name is
 *   compared without dots, commas, initials or accents, as stored or with its
 *   last part first ("Soriano, Carmen").
 * - When several people of one kind fit, the one covering the most typed
 *   words wins ("Juan Pedro Dela Cruz" over "Juan Dela Cruz"); a tie is
 *   answered as several matches, never a guess.
 * - Accents are folded on both sides ("Pena" finds "Peña" and the other way
 *   round); invisible format characters (zero-width spaces, soft hyphens)
 *   are ignored, and every dash reads as "-".
 * - One word that happens to be a first or last name never counts.
 * - Everyone in the deployment is checked, not only the asking user's
 *   branches, so another branch's member stays local too; the answer then
 *   applies its own permission and branch scope.
 *
 * Cost: one indexed read per table (borrowers, users and co_makers by last
 * name and first name, gcash_non_members by full-name prefixes built from
 * pairs of question words), and a read of the unlinked CDA officers by the
 * same prefixes (a small register), from the question's spans, and the names
 * are compared here. A read that reaches its cap may
 * have left the person out, so it keeps the question local too (a hit with
 * no name). Nothing is stored or logged.
 *
 * It fails closed: invalid UTF-8, a Normalizer failure or a regular-expression
 * failure is a member hit with no name, so the question stays local.
 */
final readonly class LindaNameCheck
{
    public const MEMBER = 'member';

    public const STAFF = 'staff';

    public const CO_MAKER = 'co_maker';

    public const WALK_IN = 'walk_in';

    /** A CDA officer not linked to a member: the register keeps their name as free text. */
    public const CDA_OFFICER = 'cda_officer';

    /** The most rows read from each table. */
    public const CANDIDATES_MAX = 200;

    /** The longest first or last name looked for, in words. */
    private const SPAN_MAX = 3;

    /** The most `full_name LIKE` patterns in one walk-in read; more go in further reads. */
    private const PATTERNS_PER_READ = 100;

    /** The most words allowed between a first and a last name (a middle name). */
    private const BETWEEN_MAX = 3;

    /** Officer and ranking words: with a staff name, the officer performance answer. */
    private const PERFORMANCE_WORDS = [
        'officer', 'officers', 'collector', 'collectors', 'kolektor', 'ao', 'aos', 'agent', 'agents',
        'top', 'best', 'worst', 'most', 'least', 'highest', 'lowest', 'rank', 'ranked', 'ranking', 'rankings',
        'performance', 'performing', 'perform', 'performed', 'doing', 'compare', 'comparison', 'versus', 'vs',
        'pinaka', 'pinakadaghan', 'pinakamaayo', 'pinakamagaling', 'pinakamataas', 'pinakamarami', 'nanguna',
        'nangunguna', 'nangungunang',
    ];

    /** The order a hit is taken in, after a staff hit on a performance question. */
    private const ORDER = [self::MEMBER, self::STAFF, self::CO_MAKER, self::WALK_IN, self::CDA_OFFICER];

    /** The kinds stored as one free-text full name, compared as typed (walkInNamed()). */
    private const FREE_TEXT = [self::WALK_IN, self::CDA_OFFICER];

    /**
     * @param  'member'|'staff'|'co_maker'|'walk_in'|'cda_officer'  $kind
     * @param  ?string  $name  first and last name as typed, first name first; null when the read was capped or failed
     * @param  bool  $performance  the question has officer or ranking words
     */
    public function __construct(
        public string $kind,
        #[\SensitiveParameter] public ?string $name,
        public bool $performance,
    ) {}

    /**
     * The person a question names by full name, or null. A member wins over
     * anyone else of the same name, unless the question has officer or
     * ranking words and names a staff user; a capped read of a kind counts
     * after every named hit.
     *
     * $people gives the candidates for the given lower-case, accent-folded
     * spans: (last names, first names, the `LIKE` patterns of a free-text
     * full name). By default, the
     * database. A row is a person (`kind`, `first_name`, `middle_name`,
     * `last_name`, or a walk-in's or unlinked officer's `full_name`) or
     * `['kind' => …, 'capped' => true]` for a read that reached its cap.
     *
     * @param  ?Closure(list<string>, list<string>, list<string>): iterable<array<string, mixed>>  $people
     */
    public static function find(#[\SensitiveParameter] string $question, ?Closure $people = null): ?self
    {
        try {
            return self::read($question, $people ?? self::fromDatabase(...));
        } catch (UnexpectedValueException) {
            return new self(self::MEMBER, null, false);
        }
    }

    /**
     * @param  Closure(list<string>, list<string>, list<string>): iterable<array<string, mixed>>  $people
     */
    private static function read(#[\SensitiveParameter] string $question, Closure $people): ?self
    {
        $question = self::normalise($question);
        $tokens = self::tokens($question);
        $words = array_column($tokens, 'word');
        $lastNames = self::withDots(self::allSpans($words), 4);

        if ($lastNames === []) {
            return null;
        }

        $hits = [];
        $capped = [];

        foreach ($people($lastNames, self::withDots(self::allSpans($words), 2), self::walkInPatterns($words)) as $person) {
            $kind = in_array($person['kind'] ?? null, self::ORDER, true) ? $person['kind'] : self::MEMBER;

            if (($person['capped'] ?? false) === true) {
                $capped[$kind] = true;

                continue;
            }

            $found = in_array($kind, self::FREE_TEXT, true)
                ? self::walkInNamed($question, $tokens, (string) ($person['full_name'] ?? ''))
                : self::personNamed($question, $tokens, $person);

            // The person covering the most typed words wins ("Juan Pedro Dela
            // Cruz" over "Juan Dela Cruz"); on a tie, the longer name, which
            // the answer then lists with every member it fits.
            if ($found !== null && (! isset($hits[$kind]) || $found[1] > $hits[$kind][1]
                || ($found[1] === $hits[$kind][1] && mb_strlen($found[0]) > mb_strlen($hits[$kind][0])))) {
                $hits[$kind] = $found;
            }
        }

        $hits = array_map(fn (array $hit): string => $hit[0], $hits);

        $performance = array_intersect($words, self::PERFORMANCE_WORDS) !== [];

        if ($performance && isset($hits[self::STAFF])) {
            return new self(self::STAFF, $hits[self::STAFF], true);
        }

        foreach (self::ORDER as $kind) {
            if (isset($hits[$kind])) {
                return new self($kind, $hits[$kind], $performance);
            }

            // A capped member read keeps the question local before any other hit.
            if ($kind === self::MEMBER && isset($capped[$kind])) {
                return new self($kind, null, $performance);
            }
        }

        foreach (self::ORDER as $kind) {
            if (isset($capped[$kind])) {
                return new self($kind, null, $performance);
            }
        }

        return null;
    }

    /**
     * A member, staff user or co-maker the question names, as typed (first
     * name first, with the middle words typed between when every one of them
     * is the stored middle name or its initial), and how many typed words
     * the person covers; or null.
     *
     * @param  list<array{word: string, start: int, end: int}>  $tokens
     * @param  array<string, mixed>  $person
     * @return ?array{string, int}
     */
    private static function personNamed(#[\SensitiveParameter] string $question, array $tokens, array $person): ?array
    {
        $first = self::words((string) ($person['first_name'] ?? ''));
        $last = self::words((string) ($person['last_name'] ?? ''));
        $middle = self::words((string) ($person['middle_name'] ?? ''));

        if ($first === [] || $last === []) {
            return null;
        }

        $at = self::named(array_column($tokens, 'word'), $first, $last, $middle);

        if ($at === null) {
            return null;
        }

        [$firstAt, $lastAt, $gap, $matched] = $at;
        $coverage = count($first) + count($last) + $matched;

        if ($gap > 0 && $matched === $gap) {
            return [self::typed($question, $tokens, $firstAt, $lastAt + count($last) - $firstAt), $coverage];
        }

        return [self::typed($question, $tokens, $firstAt, count($first)).' '.self::typed($question, $tokens, $lastAt, count($last)), $coverage];
    }

    /**
     * A walk-in's or an unlinked CDA officer's stored full name (free text) in
     * the question: compared without dots, commas, initials or accents, as
     * stored or with its last part first ("Soriano, Carmen"), as typed, and how
     * many typed words it covers; or null.
     *
     * @param  list<array{word: string, start: int, end: int}>  $tokens
     * @return ?array{string, int}
     */
    private static function walkInNamed(#[\SensitiveParameter] string $question, array $tokens, #[\SensitiveParameter] string $fullName): ?array
    {
        $full = array_values(array_filter(self::words($fullName), fn (string $word) => mb_strlen($word) > 1));
        $count = count($full);
        $kept = array_values(array_filter(array_keys($tokens), fn (int $i) => mb_strlen($tokens[$i]['word']) > 1));
        $words = array_map(fn (int $i) => $tokens[$i]['word'], $kept);

        // A name longer than the question can't be in it.
        if ($count < 2 || $count > count($words)) {
            return null;
        }

        $rotations = [];
        for ($split = 0; $split < $count; $split++) {
            $rotations[] = [...array_slice($full, $split), ...array_slice($full, 0, $split)];
        }

        foreach (array_keys($words) as $i) {
            foreach ($rotations as $rotated) {
                if (self::at($words, $i, $rotated)) {
                    $from = $kept[$i];

                    return [self::typed($question, $tokens, $from, $kept[$i + $count - 1] - $from + 1), $count];
                }
            }
        }

        return null;
    }

    /**
     * Where a full name sits in the question's words: the index of its first
     * name and of its last name, or null. First then last (with up to three
     * words between, each an initial, a word of the middle name or a
     * name-like word), or last then first ("Dela Cruz, Juan").
     *
     * @param  list<string>  $words
     * @param  list<string>  $first
     * @param  list<string>  $last
     * @param  list<string>  $middle
     * @return ?array{int, int, int, int} first name's index, last name's index, the words between, and how many of them are the stored middle name or its initial
     */
    private static function named(#[\SensitiveParameter] array $words, array $first, array $last, array $middle): ?array
    {
        foreach (array_keys($words) as $i) {
            if (self::at($words, $i, $first)) {
                for ($gap = 0; $gap <= self::BETWEEN_MAX; $gap++) {
                    $between = array_slice($words, $i + count($first), $gap);

                    if (count($between) < $gap || ! self::allMayStandBetween($between, $middle)) {
                        break;
                    }

                    if (self::at($words, $i + count($first) + $gap, $last)) {
                        $matched = count(array_filter($between, fn (string $word) => self::isOfMiddleName($word, $middle)));

                        return [$i, $i + count($first) + $gap, $gap, $matched];
                    }
                }
            }

            if (self::at($words, $i, $last) && self::at($words, $i + count($last), $first)) {
                return [$i + count($last), $i, 0, 0];
            }
        }

        return null;
    }

    /**
     * A word of the stored middle name, or its initial.
     *
     * @param  list<string>  $middle
     */
    private static function isOfMiddleName(#[\SensitiveParameter] string $word, #[\SensitiveParameter] array $middle): bool
    {
        foreach ($middle as $part) {
            if ($word === $part || (mb_strlen($word) === 1 && str_starts_with($part, $word))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $words
     * @param  list<string>  $part
     */
    private static function at(#[\SensitiveParameter] array $words, int $i, #[\SensitiveParameter] array $part): bool
    {
        return $part !== [] && array_slice($words, $i, count($part)) === $part;
    }

    /**
     * @param  list<string>  $words
     * @param  list<string>  $middle  the stored middle name's words
     */
    private static function allMayStandBetween(#[\SensitiveParameter] array $words, #[\SensitiveParameter] array $middle): bool
    {
        foreach ($words as $word) {
            if (mb_strlen($word) !== 1 && ! in_array($word, $middle, true) && ! LindaLookup::isNameLike($word)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every 1–3-word span of the question, for first names: a first name may
     * be a common word ("May", "Will", "Isa"). The last-name filter and the
     * question's length keep the read bounded.
     *
     * @param  list<string>  $words
     * @return list<string>
     */
    private static function allSpans(#[\SensitiveParameter] array $words): array
    {
        $spans = [];

        foreach (array_keys($words) as $i) {
            for ($length = 1; $length <= self::SPAN_MAX && $i + $length <= count($words); $length++) {
                $spans[implode(' ', array_slice($words, $i, $length))] = true;
            }
        }

        return array_keys($spans);
    }

    /**
     * The spans, each also with every short word (up to $letters letters)
     * with and without a trailing dot, so a stored "Sta. Maria" or "Ma.
     * Theresa" is read when typed without it (at most 8 forms a span).
     *
     * @param  list<string>  $spans
     * @return list<string>
     */
    private static function withDots(#[\SensitiveParameter] array $spans, int $letters): array
    {
        $all = [];

        foreach ($spans as $span) {
            $forms = [''];

            foreach (explode(' ', $span) as $word) {
                $choices = mb_strlen($word) <= $letters ? [$word, $word.'.'] : [$word];
                $next = [];

                foreach ($forms as $form) {
                    foreach ($choices as $choice) {
                        $next[] = $form === '' ? $choice : $form.' '.$choice;
                    }
                }

                $forms = $next;
            }

            foreach ($forms as $form) {
                $all[$form] = true;
            }
        }

        return array_keys($all);
    }

    /**
     * The `full_name LIKE` patterns a walk-in read takes: for every pair of
     * question words (2 or more letters) next to each other or with one word
     * between, common words included, the name starting with one and holding
     * the other after it, in either order: "wi%wj%" and "wj%wi%". Walk-in
     * names are saved as typed, so `%` between the two covers every layout the
     * comparison accepts: a space, a comma with or without a space, a middle
     * name or initial, a dotted first word ("Ma. Lourdes"). Each still starts
     * with a fixed word, so the index applies; the pair makes a common word
     * precise, and the question's length bounds the list.
     *
     * @param  list<string>  $words
     * @return list<string>
     */
    private static function walkInPatterns(#[\SensitiveParameter] array $words): array
    {
        $words = array_values(array_map(fn (string $word) => addcslashes($word, '%_\\'), array_filter($words, fn (string $word) => mb_strlen($word) > 1)));
        $patterns = [];

        foreach (array_keys($words) as $i) {
            foreach ([$i + 1, $i + 2] as $j) {
                if (! isset($words[$j])) {
                    continue;
                }

                [$a, $b] = [$words[$i], $words[$j]];

                foreach (["{$a}%{$b}%", "{$b}%{$a}%"] as $pattern) {
                    $patterns[$pattern] = true;
                }
            }
        }

        return array_keys($patterns);
    }

    /**
     * The name words as typed in the question, spaces squeezed.
     *
     * @param  list<array{word: string, start: int, end: int}>  $tokens
     */
    private static function typed(#[\SensitiveParameter] string $question, array $tokens, int $at, int $count): string
    {
        $start = $tokens[$at]['start'];
        $end = $tokens[$at + $count - 1]['end'];

        return self::replace('/\s+/u', ' ', substr($question, $start, $end - $start));
    }

    /**
     * A name's words: NFKC, lower case, accents folded, a trailing "'s"
     * dropped.
     *
     * @return list<string>
     */
    private static function words(#[\SensitiveParameter] string $text): array
    {
        return array_column(self::tokens(self::normalise($text)), 'word');
    }

    /**
     * NFKC, with invisible format characters dropped and every dash read as
     * "-".
     */
    private static function normalise(#[\SensitiveParameter] string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new UnexpectedValueException('utf8');
        }

        $normal = Normalizer::normalize($text, Normalizer::FORM_KC);

        if (! is_string($normal)) {
            throw new UnexpectedValueException('normalise');
        }

        return self::replace('/[\p{Pd}\x{2212}]/u', '-', self::replace('/\p{Cf}/u', '', $normal));
    }

    /** Lower case with accents folded: "Peña" → "pena". */
    private static function fold(#[\SensitiveParameter] string $word): string
    {
        $decomposed = Normalizer::normalize(mb_strtolower($word), Normalizer::FORM_D);

        if (! is_string($decomposed)) {
            throw new UnexpectedValueException('fold');
        }

        return self::replace('/\p{Mn}/u', '', $decomposed);
    }

    /**
     * The words of normalised text with their byte offsets in it.
     *
     * @return list<array{word: string, start: int, end: int}>
     */
    private static function tokens(#[\SensitiveParameter] string $text): array
    {
        if (preg_match_all("/[\\pL\\pM\\pN]++(?:['’.\\-][\\pL\\pM\\pN]++)*+/u", $text, $found, PREG_OFFSET_CAPTURE) === false) {
            throw new UnexpectedValueException('tokens');
        }

        $tokens = [];

        foreach ($found[0] as [$word, $start]) {
            $bare = self::replace("/['’][sS]$/u", '', $word);
            $tokens[] = ['word' => self::fold($bare), 'start' => $start, 'end' => $start + strlen($bare)];
        }

        return $tokens;
    }

    private static function replace(string $pattern, string $replacement, #[\SensitiveParameter] string $subject): string
    {
        $result = preg_replace($pattern, $replacement, $subject);

        if ($result === null) {
            throw new UnexpectedValueException('preg_replace');
        }

        return $result;
    }

    /**
     * The people whose names fit the spans, any branch, at most
     * CANDIDATES_MAX a read: members, staff users and co-makers by last and
     * first name (the collation ignores case and accents), walk-ins and CDA
     * officers not linked to a member whose free full name starts with a pair
     * of the question's words (index-friendly prefixes, at most 100 a read),
     * soft-deleted ones included. No read
     * leaves out common words: first and last name together, and the
     * comparison here, decide. Only a read that actually reaches its cap keeps
     * the question local. A read that reaches the cap adds a
     * `capped` row for its kind.
     *
     * @param  list<string>  $lastNames
     * @param  list<string>  $firstNames
     * @param  list<string>  $patterns  `full_name LIKE` patterns, already escaped
     * @return list<array<string, mixed>>
     */
    private static function fromDatabase(array $lastNames, array $firstNames, array $patterns): array
    {
        $rows = [];
        $capped = function (string $kind, int $count) use (&$rows): void {
            if ($count >= self::CANDIDATES_MAX) {
                $rows[] = ['kind' => $kind, 'capped' => true];
            }
        };

        if ($firstNames !== []) {
            $members = Borrower::query()->whereIn('last_name', $lastNames)->whereIn('first_name', $firstNames)
                ->limit(self::CANDIDATES_MAX)->get(['first_name', 'middle_name', 'last_name']);
            $capped(self::MEMBER, $members->count());
            foreach ($members as $member) {
                $rows[] = ['kind' => self::MEMBER, 'first_name' => $member->first_name, 'middle_name' => $member->middle_name, 'last_name' => $member->last_name];
            }

            $staff = User::query()->whereIn('last_name', $lastNames)->whereIn('first_name', $firstNames)
                ->limit(self::CANDIDATES_MAX)->get(['first_name', 'last_name']);
            $capped(self::STAFF, $staff->count());
            foreach ($staff as $user) {
                $rows[] = ['kind' => self::STAFF, 'first_name' => $user->first_name, 'middle_name' => null, 'last_name' => $user->last_name];
            }

            $coMakers = CoMaker::query()->whereIn('last_name', $lastNames)->whereIn('first_name', $firstNames)
                ->limit(self::CANDIDATES_MAX)->get(['first_name', 'middle_name', 'last_name']);
            $capped(self::CO_MAKER, $coMakers->count());
            foreach ($coMakers as $coMaker) {
                $rows[] = ['kind' => self::CO_MAKER, 'first_name' => $coMaker->first_name, 'middle_name' => $coMaker->middle_name, 'last_name' => $coMaker->last_name];
            }
        }

        foreach (array_chunk($patterns, self::PATTERNS_PER_READ) as $batch) {
            $walkIns = GCashNonMember::withTrashed()
                ->where(function ($query) use ($batch): void {
                    foreach ($batch as $pattern) {
                        $query->orWhere('full_name', 'like', $pattern);
                    }
                })
                ->limit(self::CANDIDATES_MAX)
                ->get(['full_name']);
            $capped(self::WALK_IN, $walkIns->count());
            foreach ($walkIns as $walkIn) {
                $rows[] = ['kind' => self::WALK_IN, 'full_name' => $walkIn->full_name];
            }

            // A linked officer's name is the member's, read above.
            $officers = CdaOfficer::withTrashed()
                ->whereNull('borrower_id')
                ->whereNotNull('name')
                ->where(function ($query) use ($batch): void {
                    foreach ($batch as $pattern) {
                        $query->orWhere('name', 'like', $pattern);
                    }
                })
                ->limit(self::CANDIDATES_MAX)
                ->get(['name']);
            $capped(self::CDA_OFFICER, $officers->count());
            foreach ($officers as $officer) {
                $rows[] = ['kind' => self::CDA_OFFICER, 'full_name' => $officer->name];
            }
        }

        return $rows;
    }
}
