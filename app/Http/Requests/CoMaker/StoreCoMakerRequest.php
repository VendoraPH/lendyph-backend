<?php

namespace App\Http\Requests\CoMaker;

use Illuminate\Foundation\Http\FormRequest;

class StoreCoMakerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('borrowers:create');
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:1000'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'occupation' => ['nullable', 'string', 'max:255'],
            'employer' => ['nullable', 'string', 'max:255'],
            'monthly_income' => ['nullable', 'numeric', 'min:0'],
            'relationship_to_borrower' => ['nullable', 'string', 'max:255'],
        ];
    }
}
