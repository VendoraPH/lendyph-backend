<?php

namespace App\Http\Requests\Amla;

use App\Models\AmlaOrder;
use App\Services\Amla\Foundation\AmlaAccess;
use Illuminate\Validation\Rule;

/**
 * POST /amla/orders: record an official order and the savings holds it
 * requires. Needs `amla:sanctions` and head-office access. Each hold is on
 * one of the order's member's own accounts, up to the balance not already
 * held (checked under the account lock).
 */
class StoreAmlaOrderRequest extends AmlaFormRequest
{
    public const MAX_HOLDS = 20;

    protected function permissions(): array
    {
        return [AmlaAccess::SANCTIONS];
    }

    protected function headOffice(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:150'],
            'authority' => ['required', 'string', Rule::in(array_keys(AmlaOrder::AUTHORITIES))],
            'received_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:today'],
            'borrower_id' => ['required', 'integer', Rule::exists('borrowers', 'id')],
            'required_action' => ['required', 'string', 'max:2000'],
            'details' => ['nullable', 'string', 'max:5000'],
            'responsible_officer_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'return_due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:received_on'],
            'holds' => ['required', 'array', 'min:1', 'max:'.self::MAX_HOLDS],
            'holds.*.account_id' => ['required', 'integer', 'distinct'],
            'holds.*.amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'authority.in' => 'Pick the AMLC, a court, or another authority.',
            'holds.required' => 'Place at least one hold for the order.',
            'holds.*.account_id.distinct' => 'Each account is held once per order.',
            'holds.*.amount.decimal' => 'Give each amount in pesos with at most two decimals.',
            'received_on.before_or_equal' => 'The order can\'t have been received in the future.',
        ];
    }
}
