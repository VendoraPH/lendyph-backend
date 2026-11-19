<?php

namespace App\Http\Requests\Branch;

use App\Models\InterbranchTransfer;
use App\Services\BranchAccess;
use Illuminate\Validation\Rule;

/** The transfer list's filters. A `branch_id` the caller can't see answers 403. */
class ListInterbranchTransfersRequest extends InterbranchTransferRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::in(array_keys(InterbranchTransfer::STATUS_LABELS))],
            'branch_id' => ['nullable', 'integer', 'min:1', 'max:999999999999999999'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function passedValidation(): void
    {
        app(BranchAccess::class)->authorizeBranchFilter($this->user(), $this->validated('branch_id'));
    }
}
