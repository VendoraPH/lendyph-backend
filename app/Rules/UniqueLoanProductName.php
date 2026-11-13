<?php

namespace App\Rules;

use App\Models\LoanProduct;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A loan product's name may not read as any OTHER product's name.
 *
 * Staff pick products by name on the loan form, the reports and the CSV
 * import mapping, so two products that read alike are easy to confuse. Both
 * sides are normalised first (trimmed, whitespace runs collapsed to one
 * space, lowercased), so "Regular Loan", "regular loan" and
 * "  REGULAR   loan " are one name.
 *
 * On an update, pass the product's stored name: the check runs only when the
 * normalised name changes, so a product that already shares its name with
 * another (saved before names were unique) still saves. There is no database
 * index, because those existing duplicates would break it.
 */
class UniqueLoanProductName implements ValidationRule
{
    public function __construct(
        private readonly ?int $ignoreProductId = null,
        private readonly ?string $currentName = null,
    ) {}

    /** The comparison form of a product name, or null when it is not valid UTF-8. */
    public static function normalise(string $value): ?string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($value)));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $wanted = self::normalise($value);

        if ($wanted === null) {
            $fail('The :attribute must be valid UTF-8 text.');

            return;
        }

        if ($this->currentName !== null && self::normalise($this->currentName) === $wanted) {
            return;
        }

        $clash = LoanProduct::query()
            ->when($this->ignoreProductId !== null, fn ($query) => $query->whereKeyNot($this->ignoreProductId))
            ->orderBy('id')
            ->get(['id', 'name'])
            ->first(fn (LoanProduct $product) => self::normalise((string) $product->name) === $wanted);

        if ($clash !== null) {
            $fail("Another loan product is already named “{$clash->name}”.");
        }
    }
}
