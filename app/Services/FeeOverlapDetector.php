<?php

namespace App\Services;

use App\Models\Fee;
use App\Models\LoanProduct;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Finds cases where a `Fee` catalog entry and a `LoanProduct`'s own fee
 * column would BOTH charge a borrower for what reads as the same fee.
 *
 * ## Why this exists
 *
 * {@see LoanReleaseFeeService} appends every applicable `Fee` catalog row on
 * top of whatever `LoanProduct::processing_fee` / `service_fee` /
 * `notarial_fee` already charged at loan creation — deliberately, so two
 * genuinely distinct fees that happen to share a label both charge (a
 * catalog "Insurance Premium" fee coexisting with a product's own service
 * fee is correct and already relied on). That design becomes a footgun the
 * moment a catalog fee is named IDENTICALLY to one of those three product
 * columns: the two mechanisms then charge the SAME conceptual fee twice.
 * This detector is the one place that condition is defined, reused by the
 * `Fee`-side guard, the `LoanProduct`-side guard, and the release-preview
 * warning.
 *
 * ## Matching rules
 *
 * - NORMALIZED-EXACT, not substring/fuzzy: lowercase, non-alphanumeric
 *   collapsed to single spaces, trimmed. `"PROCESSING-FEE."` and
 *   `"processing   fee"` both match `"processing fee"`; `"Processing Fee
 *   Waiver"` does not.
 * - VALUE-AWARE: only fires when the product's own column is currently
 *   `> 0`. A catalog fee named "Notarial Fee" is fine against a product
 *   whose `notarial_fee` is `0` — using the catalog as the sole source for
 *   that charge is a legitimate, already-seeded pattern.
 * - Status-blind on purpose: {@see LoanReleaseFeeService} does not filter by
 *   `LoanProduct` status either, so an approved-not-yet-released loan
 *   against a since-deactivated product is just as exposed.
 *
 * `custom_fees` (JSON column on `LoanProduct`) is out of scope: confirmed
 * dead code today, stored and returned by the API but read by neither
 * `LoanService::createLoan()`'s deduction auto-fill nor
 * `LoanReleaseFeeService`. Extend this detector to cover it if that ever
 * changes.
 */
final class FeeOverlapDetector
{
    /**
     * Normalized fee-catalog label => the `LoanProduct` column it collides
     * with. The only three columns `LoanService::createLoan()` turns into
     * deductions at application time.
     *
     * @var array<string, string>
     */
    private const PRODUCT_FEE_FIELDS = [
        'processing fee' => 'processing_fee',
        'service fee' => 'service_fee',
        'notarial fee' => 'notarial_fee',
    ];

    /**
     * Lowercase, collapse every run of non-alphanumeric characters to a
     * single space, trim. The normal form every comparison in this class
     * runs through.
     */
    public function normalize(string $label): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($label)) ?? '');
    }

    /**
     * The `LoanProduct` column a fee-catalog label collides with, or null
     * when it names none of the three.
     */
    public function fieldFor(string $label): ?string
    {
        return self::PRODUCT_FEE_FIELDS[$this->normalize($label)] ?? null;
    }

    /**
     * The normalized label a `LoanProduct` fee column collides with, or null
     * for a column this detector does not track.
     */
    public function labelFor(string $field): ?string
    {
        $label = array_search($field, self::PRODUCT_FEE_FIELDS, true);

        return $label === false ? null : $label;
    }

    /**
     * `LoanProduct` rows a catalog fee named `$feeName`, scoped to
     * `$applicableProductIds`, would double-charge.
     *
     * @param  array<int>|null  $applicableProductIds  null/[] = every product
     *                                                 (mirrors {@see LoanReleaseFeeService::appliesToProduct()})
     * @return EloquentCollection<int, LoanProduct>
     */
    public function collidingProducts(string $feeName, ?array $applicableProductIds): EloquentCollection
    {
        $field = $this->fieldFor($feeName);

        if ($field === null) {
            return new EloquentCollection;
        }

        return LoanProduct::query()
            ->when(filled($applicableProductIds), fn ($q) => $q->whereIn('id', $applicableProductIds))
            ->where($field, '>', 0)
            ->get(['id', 'name', $field]);
    }

    /**
     * The reverse direction of {@see self::collidingProducts()}: `Fee`
     * catalog rows that would double-charge `$productId`'s `$field` column
     * if it were raised above zero.
     *
     * ## `$productId === null`
     *
     * A `LoanProduct` being created has no id yet, and cannot already appear
     * in any `Fee::applicable_product_ids`. Only an UNSCOPED fee (null/[] —
     * "every product") can collide with a product that does not exist yet;
     * a fee scoped to specific existing product ids cannot.
     *
     * @return EloquentCollection<int, Fee>
     */
    public function collidingFees(string $field, ?int $productId): EloquentCollection
    {
        $label = $this->labelFor($field);

        if ($label === null) {
            return new EloquentCollection;
        }

        // `fees` is a settings table — read whole, filter in PHP. Same
        // justification LoanReleaseFeeService::applicableFees() already
        // uses: a cooperative has a handful of rows, and this runs on a
        // product write, not a batch path.
        return Fee::query()
            ->get(['id', 'name', 'value', 'type', 'applicable_product_ids'])
            ->filter(fn (Fee $fee): bool => $this->normalize($fee->name) === $label
                && $this->appliesToProduct($fee->applicable_product_ids, $productId))
            ->values();
    }

    /**
     * NULL and `[]` both mean "every product" — mirrors
     * {@see LoanReleaseFeeService::appliesToProduct()}.
     *
     * @param  array<int>|null  $applicableProductIds
     */
    private function appliesToProduct(?array $applicableProductIds, ?int $productId): bool
    {
        if ($applicableProductIds === null || $applicableProductIds === []) {
            return true;
        }

        if ($productId === null) {
            return false;
        }

        return in_array($productId, array_map('intval', $applicableProductIds), true);
    }
}
