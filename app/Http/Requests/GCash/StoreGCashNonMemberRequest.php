<?php

namespace App\Http\Requests\GCash;

use Illuminate\Foundation\Http\FormRequest;

class StoreGCashNonMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('gcash:transact');
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'mobile_number' => ['required', 'string', 'max:32'],
            'id_type' => ['required', 'string', 'max:64'],
            'id_number' => ['required', 'string', 'max:64'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
