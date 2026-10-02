<?php

namespace App\Http\Requests\GCash;

use App\Http\Requests\Concerns\ExcludesRejectedBorrowers;
use App\Http\Requests\GCash\Concerns\HasGCashQuoteRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreGCashTransactionRequest extends FormRequest
{
    use ExcludesRejectedBorrowers;
    use HasGCashQuoteRules;

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
            ...$this->quoteRules(),
            'is_pending' => ['nullable', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Staff know the second kind of party as a walk-in, so the generated
     * messages name the field that way instead of "gcash non member id".
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'gcash_non_member_id' => 'walk-in',
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
