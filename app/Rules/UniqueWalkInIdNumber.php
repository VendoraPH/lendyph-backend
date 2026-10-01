<?php

namespace App\Rules;

use App\Models\GCashNonMember;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * One live walk-in per ID document.
 *
 * A validation rule, not a unique index: a removed walk-in is soft-deleted and
 * keeps its ID, and it must not stop the same person being registered again.
 *
 * ID numbers are compared normalised: spaces, dashes and case are ignored, so
 * `1234-5678` matches `12345678`. The stored value stays exactly as entered;
 * only the comparison is normalised. The ID type is compared as-is (the column
 * collation already ignores its case), so the same number under a different
 * type is a different document.
 */
class UniqueWalkInIdNumber implements ValidationRule
{
    /** SQL twin of normalise(); the two must strip the same characters. */
    private const NORMALISED_COLUMN = "UPPER(REPLACE(REPLACE(id_number, ' ', ''), '-', ''))";

    public function __construct(
        private readonly ?string $idType,
        private readonly ?int $ignoreId = null,
    ) {}

    public static function normalise(string $idNumber): string
    {
        return strtoupper(str_replace([' ', '-'], '', $idNumber));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $taken = GCashNonMember::query()
            ->where('id_type', $this->idType)
            ->whereRaw(self::NORMALISED_COLUMN.' = ?', [self::normalise($value)])
            ->when($this->ignoreId, fn ($query, int $id) => $query->whereKeyNot($id))
            ->exists();

        if ($taken) {
            $fail('A walk-in with this ID type and ID number is already registered. Search for them instead of adding them again.');
        }
    }
}
