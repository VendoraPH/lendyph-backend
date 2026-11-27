<?php

namespace App\Http\Requests\Accounting;

use App\Http\Requests\Concerns\RefusesAClosedBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
    use RefusesAClosedBranch;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user->can('accounting:reconcile')
            && $user->can('journals:create')
            && $user->can('journals:post');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // A note on both journal lines; 255 is the line column's width.
            'description' => ['nullable', 'string', 'max:255'],
            // Go ahead even though the line matches an entry already in the
            // books (otherwise a 422 on `line` offers that entry instead).
            'record_anyway' => ['nullable', 'boolean'],
        ];
    }

    /** Posts into the reconciliation's branch, which a closed branch refuses (Phase 6 MB-BE2). */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->refuseAClosedBranch($validator, $this->route('reconciliation')?->branch_id)];
    }
}
