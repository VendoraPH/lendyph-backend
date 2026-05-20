<?php

namespace App\Http\Requests\Borrower;

use App\Http\Requests\Borrower\Concerns\HasBorrowerRules;
use App\Rules\NoDuplicateBorrower;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBorrowerRequest extends FormRequest
{
    use HasBorrowerRules;

    public function authorize(): bool
    {
        // Anonymous public-registration callers are allowed through here so
        // the response stays at 422 (validation) instead of 403 (auth) when
        // they submit the wrong status. The `status` rule below enforces the
        // pending-only constraint for unauthenticated requests.
        if (! $this->user()) {
            return true;
        }

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

        $rules = array_merge(
            $this->sharedBorrowerRules(),
            [
                'first_name' => $firstNameRules,
                'last_name' => ['required', 'string', 'max:255'],
                'branch_id' => ['required', 'exists:branches,id'],
            ],
        );

        // Anonymous callers must submit `pending` — they cannot create active
        // or otherwise-operational borrowers.
        if (! $this->user()) {
            $rules['status'] = ['required', Rule::in(['pending'])];
        }

        return $rules;
    }

    public function messages(): array
    {
        return $this->borrowerMessages();
    }
}
