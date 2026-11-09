<?php

namespace App\Http\Requests\Accounting;

use App\Services\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Parking an unidentified statement line in the Suspense account (2390 by
 * default) until someone works out what it was.
 *
 * The entry posts on the spot, so it needs both `journals:create` and
 * `journals:post`: the bookkeeper, who drafts but never posts, cannot write
 * one (Accounting round C, 2026-11-09).
 */
class ReconcileLineToSuspenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user->can('accounting:reconcile')
            && $user->can(BranchAccess::VIEW_ALL)
            && $user->can('journals:create')
            && $user->can('journals:post');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // A note on both journal lines; 255 is the line column's width.
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
