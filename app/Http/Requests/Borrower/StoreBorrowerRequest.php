<?php

namespace App\Http\Requests\Borrower;

use Illuminate\Foundation\Http\FormRequest;

class StoreBorrowerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('customers.create');
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'birthdate' => ['nullable', 'date', 'before:today'],
            'civil_status' => ['nullable', 'string', 'in:single,married,widowed,separated,divorced'],
            'gender' => ['nullable', 'string', 'in:male,female'],
            'address' => ['nullable', 'string', 'max:1000'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'employer_or_business' => ['nullable', 'string', 'max:255'],
            'monthly_income' => ['nullable', 'numeric', 'min:0'],
            'branch_id' => ['required', 'exists:branches,id'],
        ];
    }
}
