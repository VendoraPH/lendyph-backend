<?php

namespace App\Http\Requests\LoanOrigination\Staff;

use App\Models\OriginationDocument;
use App\Services\BranchAccess;
use Illuminate\Validation\Rule;

/**
 * `GET /loan-origination/documents`, the documents queue: filters and page.
 * A branch outside the caller's answers 403.
 */
class ListDocumentsRequest extends StaffViewRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::in(OriginationDocument::STATUSES)],
            'type' => ['nullable', 'string', 'max:50'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'application_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function passedValidation(): void
    {
        app(BranchAccess::class)->authorizeBranchFilter($this->user(), $this->validated('branch_id'));
    }
}
