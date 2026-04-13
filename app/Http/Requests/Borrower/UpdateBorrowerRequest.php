<?php

namespace App\Http\Requests\Borrower;

use App\Http\Requests\Borrower\Concerns\HasBorrowerRules;
use App\Rules\NoDuplicateBorrower;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBorrowerRequest extends FormRequest
{
    use HasBorrowerRules;

    public function authorize(): bool
    {
        return $this->user()->can('borrowers:update');
    }

    public function rules(): array
    {
        $borrowerId = $this->route('borrower')?->id;

        $firstNameRules = ['sometimes', 'string', 'max:255'];

        if (! $this->boolean('force')) {
            $firstNameRules[] = new NoDuplicateBorrower($borrowerId);
        }

        return array_merge(
            $this->sharedBorrowerRules($borrowerId),
            [
                'first_name' => $firstNameRules,
                'last_name' => ['sometimes', 'string', 'max:255'],
                'branch_id' => ['sometimes', 'exists:branches,id'],
            ],
        );
    }

    public function messages(): array
    {
        return $this->borrowerMessages();
    }
}
