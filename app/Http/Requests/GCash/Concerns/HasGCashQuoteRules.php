<?php

namespace App\Http\Requests\GCash\Concerns;

use Illuminate\Validation\Rule;

trait HasGCashQuoteRules
{
    /**
     * The `type` and `amount` rules shared by StoreGCashTransactionRequest and
     * PreviewGCashTransactionRequest, so a preview accepts and rejects exactly
     * what recording the transaction would.
     *
     * @return array{type: array<int, mixed>, amount: array<int, string>}
     */
    protected function quoteRules(): array
    {
        return [
            'type' => ['required', Rule::in(['cash_in', 'cash_out'])],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
        ];
    }
}
