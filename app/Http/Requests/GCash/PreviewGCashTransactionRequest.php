<?php

namespace App\Http\Requests\GCash;

use App\Http\Requests\GCash\Concerns\HasGCashQuoteRules;
use Illuminate\Foundation\Http\FormRequest;

class PreviewGCashTransactionRequest extends FormRequest
{
    use HasGCashQuoteRules;

    /** Same gate as recording the transaction it quotes. */
    public function authorize(): bool
    {
        return $this->user()->can('gcash:transact');
    }

    public function rules(): array
    {
        return $this->quoteRules();
    }
}
