<?php

namespace App\Services\CsvImport;

use App\Models\Borrower;

/**
 * What BorrowerMatcher concluded about one customer row.
 */
final class BorrowerMatch
{
    /** The account number is not on anybody: create a new borrower. */
    public const NEW = 'new';

    /** A borrower already carries this account number — this row was imported before. */
    public const ALREADY_IMPORTED = 'already_imported';

    /** One identity match with no account number: the number was written onto them. */
    public const BACKFILLED = 'backfilled';

    /** One identity match already carrying a DIFFERENT account number. Needs a human. */
    public const ACCOUNT_NO_CONFLICT = 'account_no_conflict';

    /** More than one borrower matches this identity, or the identity is too thin to be sure. */
    public const AMBIGUOUS_MATCH = 'ambiguous_match';

    /**
     * @param  list<int>  $candidateIds  Populated for ambiguous matches so an operator can be shown who was hit.
     */
    private function __construct(
        public readonly string $outcome,
        public readonly ?Borrower $borrower = null,
        public readonly ?string $reason = null,
        public readonly array $candidateIds = [],
    ) {}

    public static function new(): self
    {
        return new self(self::NEW);
    }

    public static function alreadyImported(Borrower $borrower): self
    {
        return new self(self::ALREADY_IMPORTED, $borrower, "This account number is already on borrower {$borrower->borrower_code}, so the row was skipped.");
    }

    public static function backfilled(Borrower $borrower): self
    {
        return new self(self::BACKFILLED, $borrower, "Matched existing borrower {$borrower->borrower_code} on name and birthdate; the account number was written onto their record and nothing else was changed.");
    }

    public static function accountNoConflict(Borrower $borrower, string $incoming): self
    {
        return new self(self::ACCOUNT_NO_CONFLICT, $borrower, "Borrower {$borrower->borrower_code} is the same person by name and birthdate but already carries account number \"{$borrower->external_account_no}\", not \"{$incoming}\".");
    }

    /**
     * @param  list<int>  $candidateIds
     */
    public static function ambiguous(array $candidateIds, string $reason): self
    {
        return new self(self::AMBIGUOUS_MATCH, null, $reason, $candidateIds);
    }

    public function needsReview(): bool
    {
        return in_array($this->outcome, [self::ACCOUNT_NO_CONFLICT, self::AMBIGUOUS_MATCH], true);
    }
}
