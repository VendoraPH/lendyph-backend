<?php

namespace App\Http\Requests\GCash;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGCashNonMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('gcash:transact');
    }

    /**
     * The frontend's edit dialog posts the whole record back, so these mirror
     * the store rules rather than being `sometimes` — a partial update would
     * silently blank the fields the dialog did not send.
     */
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
