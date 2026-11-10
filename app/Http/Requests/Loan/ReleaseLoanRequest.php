<?php

namespace App\Http\Requests\Loan;

use App\Http\Requests\Concerns\ReferenceNumberMessages;
use App\Http\Requests\Loan\Concerns\ValidatesReleaseInsurance;
use App\Rules\NotAFormerMember;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ReleaseLoanRequest extends FormRequest
{
    use ReferenceNumberMessages, ValidatesReleaseInsurance;

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
            // How the net proceeds went out, and its reference, in the shape
            // Record Payment takes them (StoreRepaymentRequest). The release
            // journal credits that method's settlement account.
            'method' => ['sometimes', 'in:cash,gcash,maya,bank_transfer,online'],
            'reference_number' => ['nullable', 'string', 'max:100', 'required_unless:method,cash'],
        ];
    }

    /** The release method, `cash` when none was sent. */
    public function releaseMethod(): string
    {
        return (string) $this->validated('method', 'cash');
    }

    /** The release's reference, or null when none was sent. */
    public function releaseReference(): ?string
    {
        $reference = $this->validated('reference_number');

        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->referenceNumberMessages();
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('method')) {
            $this->merge(['method' => 'cash']);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateInsuranceCombination($validator);

        // No release to a member whose membership ended after approval (C1
        // review, H1). Withdrawing is refused while a loan is approved, so
        // only a death recorded since can reach this.
        $validator->after(function (Validator $validator): void {
            $member = $this->route('loan')?->borrower;

            if ($member !== null && $member->isFormerMember()) {
                $validator->errors()->add('borrower_id', NotAFormerMember::messageFor($member));
            }
        });
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
