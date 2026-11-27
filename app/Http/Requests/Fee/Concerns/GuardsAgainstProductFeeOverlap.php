<?php

namespace App\Http\Requests\Fee\Concerns;

use App\Models\LoanProduct;
use App\Rules\NoDuplicateBorrower;
use App\Services\FeeOverlapDetector;
use App\Services\LoanReleaseFeeService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Refuses to save a `Fee` catalog row whose (normalized) name matches one of
 * `LoanProduct`'s own processing/service/notarial fee columns on a product
 * where that column is currently nonzero — the configuration
 * {@see LoanReleaseFeeService} will charge twice, by its own
 * docblock. Shared by `StoreFeeRequest` and `UpdateFeeRequest` so the two
 * don't duplicate the check.
 *
 * ## Why a FormRequest concern, and not a `Fee` model `booted()` hook
 *
 * `LoanReleaseFeesTest`'s two protected specs create colliding rows directly
 * through `Fee::create()` to prove the two mechanisms deliberately both
 * charge — a model-level guard would fire on those calls and break them.
 * This only protects writes that go through the API, which is the surface
 * an administrator actually uses.
 *
 * ## Why `withValidator()->after()`, and not a field-attached `ValidationRule`
 *
 * `UpdateFeeRequest` allows a PATCH that changes only
 * `applicable_product_ids` without resending `name`. A rule attached to the
 * `name` field would never run on that request and would silently miss the
 * exact case where SCOPING a fee onto a colliding product creates the
 * overlap. This runs on every validated write regardless of which fields
 * were actually sent, and computes the EFFECTIVE post-write `name` and
 * `applicable_product_ids` — falling back to the existing row's values when
 * a field isn't resent — before checking.
 *
 * ## No bypass flag
 *
 * Unlike {@see NoDuplicateBorrower}'s `force=true` escape hatch,
 * this is not a probabilistic name collision an operator might legitimately
 * need to push through — it is a deterministic structural fact. The escape
 * hatch is renaming the fee, narrowing `applicable_product_ids`, or clearing
 * the product's own column, all fully available with no override needed.
 */
trait GuardsAgainstProductFeeOverlap
{
    /**
     * @param  string|null  $existingName  The row's current name, when updating (null on create).
     * @param  array<int>|null  $existingProductIds  The row's current `applicable_product_ids`, when updating (null on create).
     */
    protected function guardAgainstProductFeeOverlap(
        Validator $validator,
        ?string $existingName = null,
        ?array $existingProductIds = null,
    ): void {
        $validator->after(function (Validator $v) use ($existingName, $existingProductIds): void {
            $name = $this->has('name') ? (string) $this->input('name') : $existingName;

            if ($name === null || $name === '') {
                return;
            }

            $rawProductIds = $this->has('applicable_product_ids')
                ? $this->input('applicable_product_ids')
                : $existingProductIds;

            // The standalone `applicable_product_ids => array` rule may have already
            // failed for this exact input — after() still runs regardless, so a
            // malformed non-array value must not reach the detector's `?array` param.
            $productIds = is_array($rawProductIds) ? $rawProductIds : null;

            $colliding = app(FeeOverlapDetector::class)->collidingProducts($name, $productIds);

            if ($colliding->isEmpty()) {
                return;
            }

            $v->errors()->add('name', $this->productFeeOverlapMessage($name, $colliding));
        });
    }

    /**
     * `'Processing Fee' would duplicate the processing fee already
     * configured on loan product 'Salary Loan' (2.0000%). Both would be
     * charged separately at release. Rename this fee, remove 'Salary Loan'
     * from its applicable products, or clear that product's own processing
     * fee first.`
     *
     * @param  EloquentCollection<int, LoanProduct>  $colliding
     */
    private function productFeeOverlapMessage(string $name, EloquentCollection $colliding): string
    {
        $field = app(FeeOverlapDetector::class)->fieldFor($name);
        $label = str_replace('_', ' ', (string) $field);

        $products = $colliding->map(
            fn (LoanProduct $product): string => "'{$product->name}' ({$product->{$field}}%)"
        )->implode(', ');

        $isSingle = $colliding->count() === 1;
        $productWord = $isSingle ? 'product' : 'products';
        $removalPhrase = $isSingle
            ? "remove '{$colliding->first()->name}' from its applicable products"
            : 'remove them from its applicable products';
        $possessive = $isSingle ? "that product's" : 'those products\'';

        return "'{$name}' would duplicate the {$label} already configured on loan {$productWord} {$products}. "
            ."Both would be charged separately at release. Rename this fee, {$removalPhrase}, or clear "
            ."{$possessive} own {$label} first.";
    }
}
