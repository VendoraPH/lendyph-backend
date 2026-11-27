<?php

namespace App\Http\Requests\LoanProduct\Concerns;

use App\Models\LoanProduct;
use Illuminate\Contracts\Validation\Validator;

trait RefusesTermOutsideItsRange
{
    /**
     * A product's default `term` must sit between its `min_term` and `max_term`
     * (422 on `term`). A blank end of the range isn't checked. On an update, a
     * value not sent counts as the stored one, and an edit that touches none of
     * the three is left alone, so a product saved before this rule can still
     * change its other fields.
     */
    protected function refuseTermOutsideItsRange(Validator $validator, ?LoanProduct $product = null): void
    {
        $validator->after(function (Validator $v) use ($product): void {
            $fields = ['term', 'min_term', 'max_term'];

            if ($v->errors()->hasAny($fields) || ! $this->hasAny($fields)) {
                return;
            }

            $current = fn (string $field): mixed => $this->has($field) ? $this->input($field) : $product?->{$field};

            $term = $current('term');
            $min = $current('min_term');
            $max = $current('max_term');

            if ($term === null) {
                return;
            }

            if (($min !== null && (int) $term < (int) $min) || ($max !== null && (int) $term > (int) $max)) {
                $v->errors()->add('term', 'The default term must be within the minimum and maximum term.');
            }
        });
    }
}
