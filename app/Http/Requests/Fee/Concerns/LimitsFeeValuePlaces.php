<?php

namespace App\Http\Requests\Fee\Concerns;

use App\Models\Fee;
use Illuminate\Contracts\Validation\Validator;

/**
 * How many decimal places a fee's `value` may carry, by its type.
 *
 * A `percentage` value is a rate and takes the four places `fees.value`
 * (DECIMAL(10,4)) stores. A `fixed` value is a peso amount, charged to the
 * centavo at release, so it takes two — a third place could only ever be
 * rounded away. Shared by `StoreFeeRequest` and `UpdateFeeRequest`.
 */
trait LimitsFeeValuePlaces
{
    /**
     * `mixed` because it reads the raw, not-yet-validated `type` input.
     */
    protected function feeValuePlacesRule(mixed $type): string
    {
        return $type === 'fixed' ? 'decimal:0,2' : 'decimal:0,4';
    }

    /**
     * A PATCH that switches a fee to `fixed` without resending `value` keeps
     * the stored one, which can be a four-place rate. The `value` rule never
     * runs on that request, so the EFFECTIVE value is checked here instead.
     */
    protected function guardStoredValueOnSwitchToFixed(Validator $validator, ?Fee $existing): void
    {
        $validator->after(function (Validator $v) use ($existing): void {
            if ($existing === null || $this->has('value') || $this->input('type') !== 'fixed') {
                return;
            }

            $places = strlen(rtrim(explode('.', (string) $existing->value, 2)[1] ?? '', '0'));

            if ($places > 2) {
                $v->errors()->add('value', __('validation.decimal', ['attribute' => 'value', 'decimal' => '0-2']));
            }
        });
    }
}
