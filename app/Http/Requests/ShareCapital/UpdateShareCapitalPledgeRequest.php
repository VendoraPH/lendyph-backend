<?php

namespace App\Http\Requests\ShareCapital;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShareCapitalPledgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('share_capital:update');
    }

    public function rules(): array
    {
        return [
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'schedule' => ['sometimes', 'in:15,30,15/30'],
        ];
    }
}
