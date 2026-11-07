<?php

namespace App\Http\Requests\Document;

use App\Services\ValidIdService;
use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($this->route('loan')) {
            return $user->can('loans:update');
        }

        return $user->can('borrowers:update');
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf,doc,docx'],
            'type' => ['required', 'string', 'max:50'],
            'label' => ['nullable', 'string', 'max:255'],
            // A member's valid ID needs its number, whichever upload it comes
            // through. See ValidIdService::store().
            'id_number' => [
                $this->route('borrower') && $this->input('type') === ValidIdService::DOCUMENT_TYPE ? 'required' : 'nullable',
                'string',
                'max:100',
            ],
        ];
    }
}
