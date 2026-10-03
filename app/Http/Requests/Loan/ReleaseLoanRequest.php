<?php

namespace App\Http\Requests\Loan;

use App\Http\Requests\Loan\Concerns\ValidatesReleaseInsurance;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ReleaseLoanRequest extends FormRequest
{
    use ValidatesReleaseInsurance;

    public function authorize(): bool
    {
        return $this->user()->can('loans:release');
    }

    public function rules(): array
    {
        return [
            ...$this->insuranceRules(),
            // The fee-configuration fingerprint a release preview handed out.
            //
            // OPTIONAL on purpose. The frontend change this unblocks releases
            // without previewing, and making it required would 422 every
            // release that screen makes. Sent, it is checked and a release
            // quoted from a fee schedule that has since changed is refused with
            // a 409; absent, the release is simply computed from the schedule
            // as it stands right now.
            //
            // Bounded rather than pinned to the current 64-hex-char shape:
            // a well-formed-but-stale value is a 409 and a garbled one is a
            // mismatch, so both already land somewhere sensible, and a
            // `size:64` rule would only make the format impossible to version.
            'fee_fingerprint' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateInsuranceCombination($validator);
    }

    /**
     * The fee-configuration fingerprint to check this release against, if the
     * client previewed first.
     */
    public function feeFingerprint(): ?string
    {
        $fingerprint = $this->input('fee_fingerprint');

        return is_string($fingerprint) && $fingerprint !== '' ? $fingerprint : null;
    }
}
