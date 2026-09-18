<?php

namespace App\Http\Requests\Accounting;

use App\Models\AccountingAccount;
use App\Services\Accounting\AccountRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountingAccountRequest extends FormRequest
{
    use ValidatesAccountShape;

    public function authorize(): bool
    {
        return $this->user()->can('chart_of_accounts:update');
    }

    /**
     * Every field is `sometimes`: the frontend sends a `Partial<Account>`, and
     * a field left out means "leave it alone" rather than "set it to null".
     * The cross-field checks below resolve each value against the stored row
     * for exactly that reason.
     *
     * `normal_balance` is absent here for the same reason as on create — see
     * StoreAccountingAccountRequest.
     */
    public function rules(): array
    {
        $account = $this->account();

        return [
            'code' => [
                'sometimes', 'required', 'string', 'max:16',
                Rule::unique('accounting_accounts', 'code')->ignore($account->id),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'type' => ['sometimes', 'required', Rule::in(AccountRules::TYPES)],
            // `sometimes` and never `nullable`: these back NOT NULL columns,
            // so an explicit null would reach the database as a null update.
            'is_contra' => ['sometimes', 'boolean'],
            'is_group' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('accounting_accounts', 'id')],
            'cash_kind' => ['sometimes', 'nullable', Rule::in(AccountRules::CASH_KINDS)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => trim((string) $this->input('code'))]);
        }

        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $this->validateAccountShape($v, $this->account());
        });
    }

    private function account(): AccountingAccount
    {
        return $this->route('account');
    }
}
