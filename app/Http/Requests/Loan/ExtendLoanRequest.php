<?php

namespace App\Http\Requests\Loan;

use App\Http\Requests\Concerns\ReferenceNumberMessages;
use App\Rules\NotAFormerMember;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ExtendLoanRequest extends FormRequest
{
    use ReferenceNumberMessages;

    public function authorize(): bool
    {
        return $this->user()->can('loans:extend');
    }

    /**
     * `interest_option` is required rather than defaulted on purpose. The
     * extension now collects the interest itself, so a silent default of 'pay'
     * would double-charge the partial-payment flow, which posts the cashier's
     * own repayment immediately before extending. Every caller states intent.
     *
     * On 'pay', `method` and `reference_number` say how the member paid the
     * collection, as on Record Payment (StoreRepaymentRequest): the reference
     * is required unless the method is cash, and cash is the default. On
     * 'defer' nothing is collected, so both are left out of the validated
     * data and never refuse the request.
     */
    public function rules(): array
    {
        return [
            'remarks' => ['nullable', 'string', 'max:1000'],
            'interest_option' => ['required', 'in:pay,defer'],
            'method' => ['exclude_unless:interest_option,pay', 'sometimes', 'in:cash,gcash,maya,bank_transfer,online'],
            'reference_number' => ['exclude_unless:interest_option,pay', 'nullable', 'string', 'max:100', 'required_unless:method,cash'],
        ];
    }

    /**
     * An extension on "defer" moves unpaid interest into a new period: new
     * credit, so it's refused for a withdrawn or deceased member. "pay"
     * collects what's owed and stays allowed (C1 review, L3).
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $member = $this->route('loan')?->borrower;

                if ($this->input('interest_option') === 'defer' && $member !== null && $member->isFormerMember()) {
                    $validator->errors()->add('interest_option', NotAFormerMember::messageFor($member).' Choose "pay" to collect the interest instead.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->referenceNumberMessages(),
            'interest_option.required' => 'Choose whether to pay or defer the outstanding interest.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('method')) {
            $this->merge(['method' => 'cash']);
        }
    }
}
