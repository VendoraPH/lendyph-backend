<?php

namespace App\Http\Requests\GCash;

use App\Models\GCashTransaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /gcash/transactions` (gcash:view): the list and its `meta.totals`.
 *
 * Every filter is validated, so a bad one answers 422 in words instead of a
 * different list. They used to be read raw and gated on truthiness:
 * `?borrower_id=0` is falsy, so the member filter was dropped and the caller
 * got every member's rows, and a date that wasn't a date reached the query as
 * text. The same fix the payment history, loan and collateral lists carry.
 */
class ListGCashTransactionsRequest extends FormRequest
{
    /** The gate comes before the filters, so a caller without it learns nothing from them. */
    public function authorize(): bool
    {
        return $this->user()->can('gcash:view');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::in(GCashTransaction::TYPES)],
            'status' => ['nullable', Rule::in(GCashTransaction::STATUSES)],
            'borrower_id' => ['nullable', 'integer', 'min:1'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'type.in' => 'Choose Cash In or Cash Out as the type.',
            'status.in' => 'Choose Pending, Paid or Completed as the status.',
            'borrower_id.integer' => 'Choose the member from the list.',
            'borrower_id.min' => 'Choose the member from the list.',
            'start_date.date_format' => 'The From date must be a real date, written as YYYY-MM-DD.',
            'end_date.date_format' => 'The To date must be a real date, written as YYYY-MM-DD.',
            'end_date.after_or_equal' => 'The To date can\'t be before the From date.',
            'per_page.integer' => 'Rows per page must be a whole number from 1.',
            'per_page.min' => 'Rows per page must be a whole number from 1.',
        ];
    }
}
