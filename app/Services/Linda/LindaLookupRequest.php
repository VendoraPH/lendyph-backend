<?php

namespace App\Services\Linda;

/**
 * A lookup the server answers itself, never sending the question to the
 * model: a member, a loan or a receipt, found by its number or by a name. A
 * member lookup that mentions reminders asks for that member's reminders
 * (`reminders`, Linda round 4). A loan lookup may name an imported loan by
 * its file's Loan No. (`loanNo`, finish run round 6).
 */
final readonly class LindaLookupRequest
{
    public const MEMBER = 'member';

    public const LOAN = 'loan';

    public const RECEIPT = 'receipt';

    /**
     * @param  'member'|'loan'|'receipt'  $kind
     * @param  ?string  $code  A record number as typed (BRW-000012, LN-000042, LA-000003, RCP-000123), upper-cased.
     * @param  ?string  $name  The member's name words as typed, trimmed, for a name lookup.
     * @param  bool  $reminders  A member lookup about the member's payment reminders.
     * @param  ?string  $loanNo  An imported loan's own Loan No. from the cooperative's file, as stored (LindaLoanNumbers).
     */
    public function __construct(
        public string $kind,
        public LindaIntent $intent,
        public ?string $code = null,
        public ?string $name = null,
        public bool $reminders = false,
        public ?string $loanNo = null,
    ) {}
}
