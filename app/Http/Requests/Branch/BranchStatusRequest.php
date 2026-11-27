<?php

namespace App\Http\Requests\Branch;

use App\Models\Branch;
use App\Services\BranchAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A branch's status, main office and closure (Phase 6, multi-branch decision
 * 1): `branches:manage` plus `branches:view_all`. A branch the caller can't
 * see answers 404 exactly like a missing one, before the permission check.
 * Subclasses add their fields.
 */
class BranchStatusRequest extends FormRequest
{
    public const REFUSAL = "Changing a branch's status needs the branch management permission and access to all branches.";

    public function authorize(): bool
    {
        $branch = $this->route('branch');

        if (! app(BranchAccess::class)->canSee($this->user(), $branch->id)) {
            throw (new ModelNotFoundException)->setModel(Branch::class, [$branch->id]);
        }

        return $this->user()->can('branches:manage') && $this->user()->can(BranchAccess::VIEW_ALL);
    }

    /** @throws AuthorizationException */
    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(self::REFUSAL);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
