<?php

namespace App\Services\Linda;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\Repayment;
use App\Models\User;
use App\Services\SequenceCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Linda's exact finders: a member by code or by name, a loan by its LN- or
 * LA- number, a receipt by its RCP- number. Each reads only what the user's
 * branches hold (Borrower::visibleTo(), Loan::visibleTo(), a receipt through
 * its loan), so another branch's record is never found, by name or by
 * number, and reads exactly like one that doesn't exist.
 *
 * Numbers match whole, case ignored, with or without their leading zeros,
 * never as a "contains" search. A name
 * matches when every typed word is a whole word of the member's first,
 * middle or last name (or suffix), case and extra spaces ignored; a single
 * letter, with or without a dot, matches a name word that starts with it
 * ("J. Dela Cruz"). Contact details are never searched.
 */
final class LindaFinder
{
    /** The most name words a lookup reads. */
    private const NAME_WORDS_MAX = 8;

    public function memberByCode(User $user, string $code): ?Borrower
    {
        return Borrower::query()
            ->visibleTo($user)
            ->whereIn('borrowers.borrower_code', self::numberForms($code))
            ->first();
    }

    /**
     * The members whose names hold every typed word, at most $limit of them
     * by last name, first name and id, with how many match in all.
     *
     * @return array{total: int, members: Collection<int, Borrower>}
     */
    public function membersNamed(User $user, string $name, int $limit): array
    {
        $words = self::nameWords($name);

        if ($words === []) {
            return ['total' => 0, 'members' => new Collection];
        }

        $query = Borrower::query()->visibleTo($user)->tap(fn (Builder $q) => self::whereNamed($q, $words));

        return [
            'total' => (clone $query)->count(),
            'members' => $query
                ->with('branch:id,name')
                ->orderBy('borrowers.last_name')
                ->orderBy('borrowers.first_name')
                ->orderBy('borrowers.id')
                ->limit($limit)
                ->get(),
        ];
    }

    /** A loan by its loan account number (LN-) or application number (LA-). */
    public function loanByNumber(User $user, string $number): ?Loan
    {
        $number = mb_strtoupper(trim($number));
        $column = str_starts_with($number, Loan::CODE_PREFIX.'-') ? 'loans.application_number' : 'loans.loan_account_number';

        return Loan::query()->visibleTo($user)->whereIn($column, self::numberForms($number))->first();
    }

    public function receiptByNumber(User $user, string $number): ?Repayment
    {
        return Repayment::query()
            ->whereIn('receipt_number', self::numberForms($number))
            ->whereIn('loan_id', Loan::query()->visibleTo($user)->select('loans.id'))
            ->first();
    }

    /**
     * A record number as typed and as stored: upper case, and its digits
     * padded to the stored width (SequenceCode::PAD_LENGTH), so "LN-42" and
     * "LN-0042" find LN-000042. A longer number is matched as typed.
     *
     * @return list<string>
     */
    public static function numberForms(string $number): array
    {
        $typed = mb_strtoupper(trim($number));

        if (preg_match('/^(\pL+)-0*(\d+)$/u', $typed, $parts) !== 1) {
            return [$typed];
        }

        return array_values(array_unique([
            $typed,
            $parts[1].'-'.str_pad($parts[2], SequenceCode::PAD_LENGTH, '0', STR_PAD_LEFT),
        ]));
    }

    /**
     * The typed name as words: spaces squeezed, a trailing possessive ("'s")
     * and the punctuation around each word dropped, the inner hyphen,
     * apostrophe or dot kept ("Santos-Cruz", "O'Neil", "Ma.").
     *
     * @return list<string>
     */
    public static function nameWords(#[\SensitiveParameter] string $name): array
    {
        $words = [];

        foreach (preg_split('/\s+/u', trim($name)) ?: [] as $word) {
            $word = (string) preg_replace("/['’]s$/iu", '', $word);
            $word = (string) preg_replace('/^[^\pL\pN]+|[^\pL\pN.]+$/u', '', $word);

            if ($word !== '' && preg_match('/[\pL\pN]/u', $word) === 1) {
                $words[] = mb_strtolower($word);
            }
        }

        return array_slice($words, 0, self::NAME_WORDS_MAX);
    }

    /**
     * Every word a whole word of the name (a trailing dot optional), or an
     * initial the start of one, each found with LIKE on the name padded with
     * spaces, its wildcards escaped.
     *
     * @param  Builder<Borrower>  $query
     * @param  list<string>  $words
     */
    private static function whereNamed(Builder $query, array $words): void
    {
        $haystack = "LOWER(CONCAT(' ', COALESCE(borrowers.first_name, ''), ' ', COALESCE(borrowers.middle_name, ''), ' ', COALESCE(borrowers.last_name, ''), ' ', COALESCE(borrowers.suffix, ''), ' '))";

        foreach ($words as $word) {
            $core = addcslashes(rtrim($word, '.'), '%_\\');

            if (preg_match('/^\pL\.?$/u', $word) === 1) {
                $query->whereRaw("{$haystack} LIKE ?", ['% '.$core.'%']);
            } else {
                // "Cruz." at the end of a sentence, or "Ma." stored with its dot.
                $query->where(fn (Builder $q) => $q
                    ->whereRaw("{$haystack} LIKE ?", ['% '.$core.' %'])
                    ->orWhereRaw("{$haystack} LIKE ?", ['% '.$core.'. %']));
            }
        }
    }
}
