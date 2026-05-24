<?php

namespace App\Http\Requests\Borrower;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RejectBorrowerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('borrowers:approve') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
