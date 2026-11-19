<?php

namespace App\Http\Requests\Branch;

use App\Models\Branch;
use App\Services\BranchAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchRequest extends FormRequest
{
    public const COMPANY_WIDE_REFUSAL = 'Branches are shared by the whole cooperative, so changing one needs the settings permission and access to all branches.';

    /** The words a refused edit answers with. */
    private string $refusal = self::COMPANY_WIDE_REFUSAL;

    /**
     * A branch the caller can't see answers 404 exactly like a missing one
     * (decision B2), before the permission check and validation, so neither
     * a 403 nor a 422 reveals that it exists.
     *
     * Editing a branch (name, code, details) also needs `branches:view_all`
     * (multi-branch audit D3): a branch-only user can't deactivate or recode
     * their own branch. Switching `is_active` is a status change (Phase 6,
     * decision 1), so a changed value also needs `branches:manage`; the same
     * value is accepted, so the edit form can send it back.
     */
    public function authorize(): bool
    {
        $branch = $this->route('branch');

        if (! app(BranchAccess::class)->canSee($this->user(), $branch->id)) {
            throw (new ModelNotFoundException)->setModel(Branch::class, [$branch->id]);
        }

        if (! ($this->user()->can('settings:update') && $this->user()->can(BranchAccess::VIEW_ALL))) {
            return false;
        }

        if ($this->switchesIsActive($branch) && ! BranchStatusRequest::mayChangeStatus($this)) {
            $this->refusal = BranchStatusRequest::REFUSAL;

            return false;
        }

        return true;
    }

    /** @throws AuthorizationException */
    protected function failedAuthorization(): void
    {
        throw new AuthorizationException($this->refusal);
    }

    /**
     * Whether `is_active` is sent with a value other than the branch's own.
     * A value that isn't a boolean counts as no switch, and validation
     * refuses it.
     */
    private function switchesIsActive(Branch $branch): bool
    {
        if (! $this->has('is_active')) {
            return false;
        }

        $sent = filter_var($this->input('is_active'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return $sent !== null && $sent !== $branch->is_active;
    }

    public function rules(): array
    {
        $branchId = $this->route('branch')->id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:20', Rule::unique('branches')->ignore($branchId)],
            'address' => ['nullable', 'string', 'max:500'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            ...StoreBranchRequest::profileRules(),
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
