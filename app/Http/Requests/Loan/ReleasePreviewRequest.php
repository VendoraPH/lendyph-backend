<?php

namespace App\Http\Requests\Loan;

use App\Http\Requests\Loan\Concerns\ValidatesReleaseInsurance;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /loans/{id}/release-preview: the release dialog's optional insurance
 * query parameters, validated exactly as the release validates them.
 *
 * `loans:release`, the permission the release needs: this quotes a
 * disbursement to whoever is about to count the money out.
 */
class ReleasePreviewRequest extends FormRequest
{
    use ValidatesReleaseInsurance;

    public function authorize(): bool
    {
        return $this->user()->can('loans:release');
    }

    public function rules(): array
    {
        return $this->insuranceRules();
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateInsuranceCombination($validator);
    }
}
