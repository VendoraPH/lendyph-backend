<?php

namespace App\Http\Requests\Borrower;

use App\Http\Requests\Borrower\Concerns\HasBorrowerRules;
use App\Rules\NoDuplicateBorrower;
use Illuminate\Foundation\Http\FormRequest;

class StoreBorrowerRequest extends FormRequest
{
    use HasBorrowerRules;

    public function authorize(): bool
    {
        return $this->user()->can('borrowers:create');
    }

    public function rules(): array
    {
        $firstNameRules = ['required', 'string', 'max:255'];

        // Duplicate check is bypassed when the caller explicitly sends force=true
        // (used by the frontend's "Create Anyway" confirmation dialog in PR #105).
        if (! $this->boolean('force')) {
            $firstNameRules[] = new NoDuplicateBorrower;
        }

        return array_merge(
            $this->sharedBorrowerRules(),
            [
                'first_name' => $firstNameRules,
                'last_name' => ['required', 'string', 'max:255'],
                'branch_id' => ['required', 'exists:branches,id'],
            ],
        );
    }

    public function messages(): array
    {
        return $this->borrowerMessages();
    }
}
