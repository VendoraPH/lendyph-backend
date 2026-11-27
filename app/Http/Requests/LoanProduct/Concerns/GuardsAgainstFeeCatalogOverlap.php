<?php

namespace App\Http\Requests\LoanProduct\Concerns;

use App\Http\Requests\Fee\Concerns\GuardsAgainstProductFeeOverlap;
use App\Models\Fee;
use App\Models\LoanProduct;
use App\Services\FeeOverlapDetector;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * The companion check to
 * {@see GuardsAgainstProductFeeOverlap}, run
 * from the OTHER direction: raising a `LoanProduct`'s own
 * processing/service/notarial fee column from `0` to nonzero is refused when
 * an existing `Fee` catalog row already carries the matching (normalized)
 * name and would apply to this product.
 *
 * Without this half, a catalog row that is harmless today only because the
 * product's own column is `0` (e.g. the seeded "Notarial Fee", which exists
 * precisely to demonstrate the catalog used as the SOLE source for a charge)
 * would silently start colliding the moment someone edits that product's fee
 * upward — with nothing re-validating the already-saved `Fee` row. Shared by
 * `StoreLoanProductRequest` and `UpdateLoanProductRequest` the same way
 * {@see GuardsAgainstProductFeeOverlap} is
 * shared by the `Fee` requests, and for the same reasons: a `withValidator()`
 * `after()` hook (not a model hook, not a field rule), computing the
 * EFFECTIVE post-write value of each of the three fields — falling back to
 * the existing row's value when a field isn't resent — before checking. No
 * bypass flag, for the same reason as the `Fee` side: this is a deterministic
 * structural fact, not a probabilistic collision.
 */
trait GuardsAgainstFeeCatalogOverlap
{
    /**
     * The three `LoanProduct` columns this checks, in the order their
     * errors would be reported.
     *
     * @var list<string>
     */
    private const CHECKED_FIELDS = ['processing_fee', 'service_fee', 'notarial_fee'];

    /**
     * @param  LoanProduct|null  $existing  The row being updated, or null on create (no id yet).
     */
    protected function guardAgainstFeeCatalogOverlap(Validator $validator, ?LoanProduct $existing = null): void
    {
        $validator->after(function (Validator $v) use ($existing): void {
            $detector = app(FeeOverlapDetector::class);

            foreach (self::CHECKED_FIELDS as $field) {
                $value = $this->has($field) ? $this->input($field) : $existing?->{$field};

                if ($value === null || $value === '' || (float) $value <= 0.0) {
                    continue;
                }

                $colliding = $detector->collidingFees($field, $existing?->id);

                if ($colliding->isEmpty()) {
                    continue;
                }

                $v->errors()->add($field, $this->feeCatalogOverlapMessage($field, (float) $value, $colliding));
            }
        });
    }

    /**
     * `Setting the processing fee to 2% would duplicate the fee catalog
     * entry 'Processing Fee' in Settings. Both would be charged separately
     * at release. Rename that fee, remove this product from its applicable
     * products, or leave this product's processing fee at 0.`
     *
     * @param  EloquentCollection<int, Fee>  $colliding
     */
    private function feeCatalogOverlapMessage(string $field, float $value, EloquentCollection $colliding): string
    {
        $label = str_replace('_', ' ', $field);
        $names = $colliding->map(fn (Fee $fee): string => "'{$fee->name}'")->implode(', ');

        $isSingle = $colliding->count() === 1;
        $entryWord = $isSingle ? 'fee catalog entry' : 'fee catalog entries';
        $renamePhrase = $isSingle ? 'Rename that fee' : 'Rename those fees';

        $formattedValue = number_format($value, 4);

        return "Setting the {$label} to {$formattedValue}% would duplicate the {$entryWord} {$names} in Settings. "
            ."Both would be charged separately at release. {$renamePhrase}, remove this product from "
            ."its applicable products, or leave this product's {$label} at 0.";
    }
}
