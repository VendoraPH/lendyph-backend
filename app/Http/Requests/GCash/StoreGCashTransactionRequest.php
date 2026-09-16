<?php

namespace App\Http\Requests\GCash;

use App\Http\Requests\Concerns\ExcludesRejectedBorrowers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreGCashTransactionRequest extends FormRequest
{
    use ExcludesRejectedBorrowers;

    public function authorize(): bool
    {
        return $this->user()->can('gcash:transact');
    }

    /**
     * A transaction has exactly one party: a member (`borrower_id`) or a
     * walk-in (`gcash_non_member_id`). `required_without` makes either one
     * acceptable; the `after` hook below rejects sending both, which
     * `required_without` alone permits.
     */
    public function rules(): array
    {
        return [
            'borrower_id' => [
                'required_without:gcash_non_member_id',
                'nullable',
                'integer',
                $this->nonRejectedBorrowerRule(),
            ],
            'gcash_non_member_id' => [
                'required_without:borrower_id',
                'nullable',
                'integer',
                Rule::exists('gcash_non_members', 'id')->whereNull('deleted_at'),
            ],
            'type' => ['required', Rule::in(['cash_in', 'cash_out'])],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'is_pending' => ['nullable', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->filled('borrower_id') && $this->filled('gcash_non_member_id')) {
                    $validator->errors()->add(
                        'gcash_non_member_id',
                        'A transaction belongs to either a member or a walk-in, not both.',
                    );
                }
            },
        ];
    }
}
