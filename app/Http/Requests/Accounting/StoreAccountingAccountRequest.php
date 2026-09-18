<?php

namespace App\Http\Requests\Accounting;

use App\Services\Accounting\AccountRules;
use App\Services\Accounting\CashFlowCategories;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountingAccountRequest extends FormRequest
{
    use ValidatesAccountShape;

    public function authorize(): bool
    {
        return $this->user()->can('chart_of_accounts:create');
    }

    /**
     * `normal_balance` is deliberately absent from these rules.
     *
     * It is derived from `type` + `is_contra` on save, so a client that sends
     * it is ignored rather than refused — the frontend's `updateAccount` takes
     * a `Partial<Account>` and may well spread a whole account object, and a
     * `prohibited` rule would turn that ordinary call into a 422. Ignoring is
     * the safe half of "never accepted from the client"; the model's saving
     * hook is the other half.
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:16', Rule::unique('accounting_accounts', 'code')],
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::in(AccountRules::TYPES)],
            // `sometimes`, NOT `nullable`. These three back NOT NULL columns
            // with defaults, so an explicit `null` would be merged over the
            // default and reach the database as a null insert — a 500 on a
            // payload the validator had just approved. Absent means "use the
            // default"; null means nothing at all and is refused.
            'is_contra' => ['sometimes', 'boolean'],
            'is_group' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'parent_id' => ['nullable', 'integer', Rule::exists('accounting_accounts', 'id')],
            'cash_kind' => ['nullable', Rule::in(AccountRules::CASH_KINDS)],
            // Optional. Left out, the saving hook derives it from `type` and
            // `cash_kind` — see CashFlowCategories. Unlike `normal_balance`
            // above this IS accepted from a client, because it is a judgement
            // about what the money was for rather than arithmetic, and the
            // whole reason it is stored is that an accountant may disagree with
            // the default.
            'cash_flow_category' => ['nullable', Rule::in(CashFlowCategories::ALL)],
            // Prose, and the only free-text field on an account. Capped here
            // rather than by the column (which is TEXT) so someone who pastes
            // an essay is told so instead of having it silently truncated.
            'description' => ['nullable', 'string', 'max:500'],
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
            $this->validateAccountShape($v);
        });
    }
}
