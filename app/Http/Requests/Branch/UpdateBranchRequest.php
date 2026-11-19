<?php

namespace App\Http\Requests\Branch;

use App\Models\Branch;
use App\Services\BranchAccess;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchRequest extends FormRequest
{
    public const COMPANY_WIDE_REFUSAL = 'Branches are shared by the whole cooperative, so changing one needs the settings permission and access to all branches.';

    public const STATUS_ELSEWHERE = "Change a branch's status from its Status action.";

    /**
     * A branch the caller can't see answers 404 exactly like a missing one
     * (decision B2), before the permission check and validation, so neither
     * a 403 nor a 422 reveals that it exists.
     *
     * Editing a branch (name, code, details) also needs `branches:view_all`
     * (multi-branch audit D3): a branch-only user can't deactivate or recode
     * their own branch.
     */
    public function authorize(): bool
    {
        $branch = $this->route('branch');

        if (! app(BranchAccess::class)->canSee($this->user(), $branch->id)) {
            throw (new ModelNotFoundException)->setModel(Branch::class, [$branch->id]);
        }

        return $this->user()->can('settings:update') && $this->user()->can(BranchAccess::VIEW_ALL);
    }

    /** @throws AuthorizationException */
    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(self::COMPANY_WIDE_REFUSAL);
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
            'is_active' => ['sometimes', 'boolean', $this->unchangedStatus(...)],
        ];
    }

    /**
     * `is_active` may only be sent back unchanged. Suspending, reactivating
     * and closing go through the status and closure actions (Phase 6
     * MB-BE1), which take a reason, the row lock and their own permission;
     * a switch here could undo a closure or suspend the main office between
     * their checks and this save.
     */
    private function unchangedStatus(string $attribute, mixed $value, Closure $fail): void
    {
        $sent = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($sent !== null && $sent !== $this->route('branch')->is_active) {
            $fail(self::STATUS_ELSEWHERE);
        }
    }
}
