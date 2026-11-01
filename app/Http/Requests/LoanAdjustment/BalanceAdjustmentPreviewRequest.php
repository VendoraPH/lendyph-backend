<?php

namespace App\Http\Requests\LoanAdjustment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /loans/{loan}/balance-adjustment-preview: the principal balance the
 * loan should end on. Open to whoever may request an adjustment, as
 * StoreLoanAdjustmentRequest is, and validated as a target is there.
 */
class BalanceAdjustmentPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('loan_adjustments:create');
    }

    public function rules(): array
    {
        return [
            'target_principal_balance' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
        ];
    }
}
