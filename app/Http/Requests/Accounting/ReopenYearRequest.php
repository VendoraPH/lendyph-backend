<?php

namespace App\Http\Requests\Accounting;

use App\Services\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reopening a year-end close (POST /accounting/year-closes/{id}/reopen). The
 * reason is required: it goes on the close row, on each reversal's
 * description and in the audit row.
 */
class ReopenYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('accounting:close') && $this->user()->can(BranchAccess::VIEW_ALL);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
