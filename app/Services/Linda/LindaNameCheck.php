<?php

namespace App\Services\Linda;

use App\Models\Borrower;
use App\Models\User;
use Closure;
use Normalizer;

/**
 * The full-name check (Arvin, 2026-11-16, D2), run after the matcher, the
 * lookup parser and the pending-lookup rule, and before any model call:
 * whether a question names one of this deployment's members or staff by
 * first and last name, so the name never reaches the model.
 *
 * - First and last name together, in either order ("Juan Dela Cruz",
 *   "Dela Cruz, Juan"), any case, extra spaces ignored, with up to three
 *   name-like words between them (a middle name or an initial). First and
 *   last names of several words work ("Maria Clara Santos").
 * - One word that happens to be a first or last name never counts.
 * - Every member and staff user in the deployment is checked, not only the
 *   asking user's branches, so another branch's member stays local too; the
 *   answer then applies its own permission and branch scope.
 *
 * Cost: candidates are read by last name only, from the question's 1–3-word
 * spans (two indexed queries: borrowers.last_name and users.last_name), and
 * first names are checked here. Nothing is stored or logged.
 */
final readonly class LindaNameCheck
{
    public const MEMBER = 'member';

    public const STAFF = 'staff';

    /** The longest last name looked for, in words. */
    private const SPAN_MAX = 3;

    /** The most words allowed between a first and a last name (a middle name). */
    private const BETWEEN_MAX = 3;

    /** The most candidates read of each kind. */
    private const CANDIDATES_MAX = 200;

    /** Officer and ranking words: with a staff name, the officer performance answer. */
    private const PERFORMANCE_WORDS = [
        'officer', 'officers', 'collector', 'collectors', 'kolektor', 'ao', 'aos', 'agent', 'agents',
        'top', 'best', 'worst', 'most', 'least', 'highest', 'lowest', 'rank', 'ranked', 'ranking', 'rankings',
        'performance', 'performing', 'perform', 'performed', 'doing', 'compare', 'comparison', 'versus', 'vs',
        'pinaka', 'pinakadaghan', 'pinakamaayo', 'pinakamagaling', 'pinakamataas', 'pinakamarami', 'nanguna',
        'nangunguna', 'nangungunang',
    ];

    /**
     * @param  'member'|'staff'  $kind
     * @param  string  $name  first and last name as typed, first name first
     * @param  bool  $performance  the question has officer or ranking words
     */
    public function __construct(
        public string $kind,
        #[\SensitiveParameter] public string $name,
        public bool $performance,
    ) {}

    /**
     * The member or staff user a question names by full name, or null. A
     * member wins over a staff user of the same name, unless the question has
     * officer or ranking words.
     *
     * $people gives the candidates whose last name is one of the given
     * lower-case spans; by default, the database.
     *
     * @param  ?Closure(list<string>): iterable<array{kind: string, first_name: ?string, middle_name?: ?string, last_name: ?string}>  $people
     */
    public static function find(#[\SensitiveParameter] string $question, ?Closure $people = null): ?self
    {
        $normal = mb_check_encoding($question, 'UTF-8') ? Normalizer::normalize($question, Normalizer::FORM_KC) : false;

        if (! is_string($normal)) {
            return null;
        }

        $question = $normal;
        $tokens = self::tokens($question);
        $words = array_column($tokens, 'word');
        $spans = self::spans($words);

        if ($spans === []) {
            return null;
        }

        $hits = [];

        foreach (($people ?? self::fromDatabase(...))($spans) as $person) {
            $first = self::words((string) ($person['first_name'] ?? ''));
            $last = self::words((string) ($person['last_name'] ?? ''));
            $kind = $person['kind'] === self::STAFF ? self::STAFF : self::MEMBER;

            if ($first === [] || $last === [] || isset($hits[$kind])) {
                continue;
            }

            $at = self::named($words, $first, $last);

            if ($at !== null) {
                $hits[$kind] = self::typed($question, $tokens, $at[0], count($first)).' '.self::typed($question, $tokens, $at[1], count($last));
            }
        }

        $performance = array_intersect(LindaText::words(LindaText::clean($question)), self::PERFORMANCE_WORDS) !== [];

        return match (true) {
            $performance && isset($hits[self::STAFF]) => new self(self::STAFF, $hits[self::STAFF], true),
            isset($hits[self::MEMBER]) => new self(self::MEMBER, $hits[self::MEMBER], $performance),
            isset($hits[self::STAFF]) => new self(self::STAFF, $hits[self::STAFF], $performance),
            default => null,
        };
    }

    /**
     * Where a full name sits in the question's words: the index of its first
     * name and of its last name, or null. First then last (with up to three
     * name-like words between), or last then first ("Dela Cruz, Juan").
     *
     * @param  list<string>  $words
     * @param  list<string>  $first
     * @param  list<string>  $last
     * @return ?array{int, int}
     */
    private static function named(#[\SensitiveParameter] array $words, array $first, array $last): ?array
    {
        foreach (array_keys($words) as $i) {
            if (self::at($words, $i, $first)) {
                for ($gap = 0; $gap <= self::BETWEEN_MAX; $gap++) {
                    $j = $i + count($first) + $gap;
                    $between = array_slice($words, $i + count($first), $gap);

                    if (count($between) < $gap || ($between !== [] && ! self::allNameLike($between))) {
                        break;
                    }

                    if (self::at($words, $j, $last)) {
                        return [$i, $j];
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
    private static function at(array $words, int $i, array $part): bool
    {
        return array_slice($words, $i, count($part)) === $part;
    }

    /** @param  list<string>  $words */
    private static function allNameLike(#[\SensitiveParameter] array $words): bool
    {
        foreach ($words as $word) {
            if (! LindaLookup::isNameLike($word)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The question's 1–3-word spans that could be a last name: those with a
     * name-like word, and a short single word straight after one ("Tan"
     * after "Pedro"), lower case, each once.
     *
     * @param  list<string>  $words
     * @return list<string>
     */
    private static function spans(#[\SensitiveParameter] array $words): array
    {
        $nameLike = array_map(fn (string $word) => LindaLookup::isNameLike($word), $words);
        $spans = [];

        foreach (array_keys($words) as $i) {
            for ($length = 1; $length <= self::SPAN_MAX && $i + $length <= count($words); $length++) {
                $slice = array_slice($nameLike, $i, $length);
                $besideOne = $length === 1 && mb_strlen($words[$i]) <= 3 && ($nameLike[$i - 1] ?? false);

                if (in_array(true, $slice, true) || $besideOne) {
                    $spans[implode(' ', array_slice($words, $i, $length))] = true;
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

        return (string) preg_replace('/\s+/u', ' ', substr($question, $start, $end - $start));
    }

    /**
     * Lower-case name words of NFKC text: letters, digits and inner
     * apostrophes, dots or hyphens, a trailing "'s" dropped.
     *
     * @return list<string>
     */
    private static function words(#[\SensitiveParameter] string $text): array
    {
        return array_column(self::tokens($text), 'word');
    }

    /**
     * @return list<array{word: string, start: int, end: int}>
     */
    private static function tokens(#[\SensitiveParameter] string $text): array
    {
        if (preg_match_all("/[\\pL\\pM\\pN]++(?:['’.\\-][\\pL\\pM\\pN]++)*+/u", $text, $found, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $tokens = [];

        foreach ($found[0] as [$word, $start]) {
            $bare = (string) preg_replace("/['’][sS]$/u", '', $word);
            $tokens[] = ['word' => mb_strtolower($bare), 'start' => $start, 'end' => $start + strlen($bare)];
        }

        return $tokens;
    }

    /**
     * Members and staff users whose last name is one of the spans (the
     * columns' collation ignores case), any branch, at most 200 of each.
     *
     * @param  list<string>  $lastNames
     * @return list<array{kind: string, first_name: ?string, middle_name: ?string, last_name: ?string}>
     */
    private static function fromDatabase(array $lastNames): array
    {
        $members = Borrower::query()
            ->whereIn('last_name', $lastNames)
            ->limit(self::CANDIDATES_MAX)
            ->get(['first_name', 'middle_name', 'last_name'])
            ->map(fn (Borrower $member): array => ['kind' => self::MEMBER, 'first_name' => $member->first_name, 'middle_name' => $member->middle_name, 'last_name' => $member->last_name]);

        $staff = User::query()
            ->whereIn('last_name', $lastNames)
            ->limit(self::CANDIDATES_MAX)
            ->get(['first_name', 'last_name'])
            ->map(fn (User $user): array => ['kind' => self::STAFF, 'first_name' => $user->first_name, 'middle_name' => null, 'last_name' => $user->last_name]);

        return [...$members->all(), ...$staff->all()];
    }
}
