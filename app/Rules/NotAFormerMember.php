<?php

namespace App\Rules;

use App\Models\Borrower;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;

/**
 * No new credit to a former member (C1 review, H1): a withdrawn or deceased
 * member can't be given a loan, as its member or as a co-maker. The id's
 * own existence and branch checks stay with the field's `exists` rule; this
 * only refuses, in words, a member whose membership ended.
 */
final class NotAFormerMember implements ValidationRule
{
    public const AS_MEMBER = 'member';

    public const AS_CO_MAKER = 'co_maker';

    public function __construct(private readonly string $as = self::AS_MEMBER) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return;
        }

        $member = Borrower::query()->whereKey((int) $value)->first(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'borrower_code', 'status', 'membership_ended_on']);

        if ($member !== null && $member->isFormerMember()) {
            $fail(self::messageFor($member, $this->as));
        }
    }

    /** Why a loan can't go ahead for this former member, in words. */
    public static function messageFor(Borrower $member, string $as = self::AS_MEMBER): string
    {
        $how = $member->status === 'deceased' ? 'is recorded as deceased' : 'has withdrawn';
        $when = $member->membership_ended_on === null ? '' : ' on '.Carbon::parse($member->membership_ended_on)->format('M j, Y');
        $who = trim("{$member->full_name} ({$member->borrower_code})");

        return $as === self::AS_CO_MAKER
            ? "{$who} {$how}: their membership ended{$when}, so they can't be a co-maker."
            : "{$who} {$how}: their membership ended{$when}, so no loan can be made, changed or released for them.";
    }
}
