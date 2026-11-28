<?php

namespace App\Http\Requests\Cic;

use App\Services\Cic\CicAccess;

/** DELETE /cic/loan-overrides/{id}: why the loan goes back to its product's mapping. */
class DeleteCicLoanOverrideRequest extends CicFormRequest
{
    protected function permission(): string
    {
        return CicAccess::PREPARE;
    }

    public function rules(): array
    {
        return ['reason' => self::reasonRules()];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Give a reason for removing the override.'];
    }

    public function attributes(): array
    {
        return ['reason' => 'reason'];
    }
}
