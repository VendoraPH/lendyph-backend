<?php

namespace App\Http\Requests\Savings\Note;

use App\Http\Requests\Savings\Account\AuthorizesSavingsRecord;
use App\Models\SavingsAccountNote;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /savings/accounts/{account}/notes` (savings:post): a note of up to
 * 1,000 characters on an account in the caller's branches (another branch's
 * account answers 404 before the body is checked).
 */
class StoreSavingsAccountNoteRequest extends FormRequest
{
    use AuthorizesSavingsRecord;

    public function authorize(): bool
    {
        return $this->authorizeRecord('savings:post', 'account', 'account');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('note'))) {
            $this->merge(['note' => trim($this->input('note'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:'.SavingsAccountNote::MAX_LENGTH],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'note.required' => 'Write the note.',
            'note.string' => 'The note must be text.',
            'note.max' => 'A note can be at most 1,000 characters.',
        ];
    }
}
