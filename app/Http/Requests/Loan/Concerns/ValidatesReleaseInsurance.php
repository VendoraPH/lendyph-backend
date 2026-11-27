<?php

namespace App\Http\Requests\Loan\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * The release dialog's insurance fields, validated the same way for the
 * release itself (ReleaseLoanRequest) and for the release preview that quotes
 * them (ReleasePreviewRequest), so the preview never quotes figures the
 * release would refuse.
 *
 * The premium is the server's: LoanService::insuranceTerms() computes it from
 * the percentage. `insurance_premium_amount` and `insurance_remaining_balance`
 * are still accepted from older clients; a sent premium must equal the
 * server's to the centavo, which the service checks, and the remaining
 * balance is always the server's. A partial amount is likewise checked
 * against the server's premium there, not against a premium the client sent.
 *
 * A full payment may send no partial amount (or 0); a partial payment must
 * send one, and 0 is allowed (nothing collected now, the whole premium left
 * owing). No insurance at all is no fields, a percentage of 0, or null.
 */
trait ValidatesReleaseInsurance
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function insuranceRules(): array
    {
        return [
            'insurance_premium_percentage' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'insurance_premium_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'insurance_payment_type' => ['nullable', Rule::in(['full', 'partial'])],
            'insurance_partial_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'insurance_remaining_balance' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ];
    }

    protected function validateInsuranceCombination(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $pct = $this->input('insurance_premium_percentage');
            if ($pct === null || (float) $pct === 0.0) {
                return;
            }

            $paymentType = $this->input('insurance_payment_type', 'full');
            $partial = $this->input('insurance_partial_amount');

            if ($paymentType === 'partial' && ($partial === null || $partial === '')) {
                $v->errors()->add(
                    'insurance_partial_amount',
                    'The insurance partial amount is required when payment type is partial.',
                );
            }

            if ($paymentType === 'full' && $partial !== null && $partial !== '' && (float) $partial !== 0.0) {
                $v->errors()->add(
                    'insurance_partial_amount',
                    'The insurance partial amount must be null or zero when payment type is full.',
                );
            }
        });
    }

    /**
     * The insurance fields of the validated payload, for LoanService.
     *
     * @return array<string, mixed>
     */
    public function insurancePayload(): array
    {
        return $this->only([
            'insurance_premium_percentage',
            'insurance_premium_amount',
            'insurance_payment_type',
            'insurance_partial_amount',
            'insurance_remaining_balance',
        ]);
    }
}
