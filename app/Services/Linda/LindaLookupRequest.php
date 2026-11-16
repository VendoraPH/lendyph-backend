<?php

namespace App\Services\Linda;

/**
 * A lookup the server answers itself, never sending the question to the
 * model: a member, a loan or a receipt, found by its number or by a name.
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
     */
    public function __construct(
        public string $kind,
        public LindaIntent $intent,
        public ?string $code = null,
        public ?string $name = null,
    ) {}
}
