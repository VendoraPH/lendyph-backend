<?php

namespace App\Services\Linda;

use App\Models\Borrower;
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
 * co-makers and GCash walk-ins, who Linda can't look up yet.
 *
 * - First and last name together, in either order ("Juan Dela Cruz",
 *   "Dela Cruz, Juan"), any case, extra spaces ignored. Between them, up to
 *   three words, each an initial ("A." or "A"), a word of the stored middle
 *   name, or a name-like word. First and last names of several words work
 *   ("Maria Clara Santos"). A walk-in's stored full name is found as typed,
 *   or with its last part first ("Soriano, Carmen").
 * - Accents are folded on both sides ("Pena" finds "Peña" and the other way
 *   round); invisible format characters (zero-width spaces, soft hyphens)
 *   are ignored, and every dash reads as "-".
 * - One word that happens to be a first or last name never counts.
 * - Everyone in the deployment is checked, not only the asking user's
 *   branches, so another branch's member stays local too; the answer then
 *   applies its own permission and branch scope.
 *
 * Cost: one indexed read per table (borrowers, users and co_makers by last
 * name and first name, gcash_non_members by full name), from the question's
 * spans, and the names are compared here. A read that reaches its cap may
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

    /** The most rows read from each table. */
    public const CANDIDATES_MAX = 200;

    /** The longest first or last name looked for, in words. */
    private const SPAN_MAX = 3;

    /** The longest walk-in full name looked for, in words. */
    private const FULL_NAME_MAX = 6;

    /** The longest walk-in full name also looked for with its last part first. */
    private const ROTATED_MAX = 4;

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
    private const ORDER = [self::MEMBER, self::STAFF, self::CO_MAKER, self::WALK_IN];

    /**
     * @param  'member'|'staff'|'co_maker'|'walk_in'  $kind
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
     * spans: (last names, first names, walk-in full names). By default, the
     * database. A row is a person (`kind`, `first_name`, `middle_name`,
     * `last_name`, or a walk-in's `full_name`) or `['kind' => …, 'capped' =>
     * true]` for a read that reached its cap.
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
        $lastNames = self::spans($words, true);

        if ($lastNames === []) {
            return null;
        }

        $hits = [];
        $capped = [];

        foreach ($people($lastNames, self::withInitialDots(self::spans($words, false)), self::fullNameSpans($words)) as $person) {
            $kind = in_array($person['kind'] ?? null, self::ORDER, true) ? $person['kind'] : self::MEMBER;

            if (($person['capped'] ?? false) === true) {
                $capped[$kind] = true;

                continue;
            }

            if (! isset($hits[$kind])) {
                $name = $kind === self::WALK_IN
                    ? self::walkInNamed($question, $tokens, (string) ($person['full_name'] ?? ''))
                    : self::personNamed($question, $tokens, $person);

                if ($name !== null) {
                    $hits[$kind] = $name;
                }
            }
        }

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
     * name first), or null.
     *
     * @param  list<array{word: string, start: int, end: int}>  $tokens
     * @param  array<string, mixed>  $person
     */
    private static function personNamed(#[\SensitiveParameter] string $question, array $tokens, array $person): ?string
    {
        $first = self::words((string) ($person['first_name'] ?? ''));
        $last = self::words((string) ($person['last_name'] ?? ''));
        $middle = self::words((string) ($person['middle_name'] ?? ''));

        if ($first === [] || $last === []) {
            return null;
        }

        $at = self::named(array_column($tokens, 'word'), $first, $last, $middle);

        return $at === null ? null : self::typed($question, $tokens, $at[0], count($first)).' '.self::typed($question, $tokens, $at[1], count($last));
    }

    /**
     * A walk-in's stored full name in the question, as stored or with its
     * last part first, as typed in stored order, or null.
     *
     * @param  list<array{word: string, start: int, end: int}>  $tokens
     */
    private static function walkInNamed(#[\SensitiveParameter] string $question, array $tokens, #[\SensitiveParameter] string $fullName): ?string
    {
        $full = self::words($fullName);
        $words = array_column($tokens, 'word');
        $count = count($full);

        if ($count < 2) {
            return null;
        }

        foreach (array_keys($words) as $i) {
            if (self::at($words, $i, $full)) {
                return self::typed($question, $tokens, $i, $count);
            }

            for ($split = 1; $split < $count; $split++) {
                $head = array_slice($full, 0, $split);
                $tail = array_slice($full, $split);

                if (self::at($words, $i, $tail) && self::at($words, $i + count($tail), $head)) {
                    return self::typed($question, $tokens, $i + count($tail), $split).' '.self::typed($question, $tokens, $i, count($tail));
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
     * @return ?array{int, int}
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
                        return [$i, $i + count($first) + $gap];
                    }
                }
            }

            if (self::at($words, $i, $last) && self::at($words, $i + count($last), $first)) {
                return [$i + count($last), $i];
            }
        }

        return null;
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
     * The question's 1–3-word spans that could be a name: those with a
     * name-like word, and, for last names, a short single word straight after
     * one ("Tan" after "Pedro"), each once.
     *
     * @param  list<string>  $words
     * @return list<string>
     */
    private static function spans(#[\SensitiveParameter] array $words, bool $lastNames): array
    {
        $nameLike = array_map(fn (string $word) => LindaLookup::isNameLike($word), $words);
        $spans = [];

        foreach (array_keys($words) as $i) {
            for ($length = 1; $length <= self::SPAN_MAX && $i + $length <= count($words); $length++) {
                $slice = array_slice($nameLike, $i, $length);
                $besideOne = $lastNames && $length === 1 && mb_strlen($words[$i]) <= 3 && ($nameLike[$i - 1] ?? false);

                if (in_array(true, $slice, true) || $besideOne) {
                    $spans[implode(' ', array_slice($words, $i, $length))] = true;
                }
            }
        }

        return array_keys($spans);
    }

    /**
     * The spans, plus each with a dot after its one- and two-letter words, so a
     * stored "Ma. Theresa" is read too.
     *
     * @param  list<string>  $spans
     * @return list<string>
     */
    private static function withInitialDots(#[\SensitiveParameter] array $spans): array
    {
        $all = array_fill_keys($spans, true);

        foreach ($spans as $span) {
            $dotted = implode(' ', array_map(fn (string $word) => mb_strlen($word) <= 2 ? $word.'.' : $word, explode(' ', $span)));
            $all[$dotted] = true;
        }

        return array_keys($all);
    }

    /**
     * The question's 2–6-word spans with a name-like word, for walk-ins' full
     * names, and the shorter ones with their last part first.
     *
     * @param  list<string>  $words
     * @return list<string>
     */
    private static function fullNameSpans(#[\SensitiveParameter] array $words): array
    {
        $spans = [];

        foreach (array_keys($words) as $i) {
            for ($length = 2; $length <= self::FULL_NAME_MAX && $i + $length <= count($words); $length++) {
                $span = array_slice($words, $i, $length);

                if (! in_array(true, array_map(fn (string $word) => LindaLookup::isNameLike($word), $span), true)) {
                    continue;
                }

                $spans[implode(' ', $span)] = true;

                for ($split = 1; $length <= self::ROTATED_MAX && $split < $length; $split++) {
                    $spans[implode(' ', [...array_slice($span, $split), ...array_slice($span, 0, $split)])] = true;
                }
            }
        }

        return array_keys($spans);
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
     * CANDIDATES_MAX a table: members, staff users and co-makers by last and
     * first name (the collation ignores case and accents), walk-ins by full
     * name, soft-deleted ones included. A read that reaches the cap adds a
     * `capped` row for its kind.
     *
     * @param  list<string>  $lastNames
     * @param  list<string>  $firstNames
     * @param  list<string>  $fullNames
     * @return list<array<string, mixed>>
     */
    private static function fromDatabase(array $lastNames, array $firstNames, array $fullNames): array
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

        if ($fullNames !== []) {
            $walkIns = GCashNonMember::withTrashed()->whereIn('full_name', $fullNames)->limit(self::CANDIDATES_MAX)->get(['full_name']);
            $capped(self::WALK_IN, $walkIns->count());
            foreach ($walkIns as $walkIn) {
                $rows[] = ['kind' => self::WALK_IN, 'full_name' => $walkIn->full_name];
            }
        }

        return $rows;
    }
}
