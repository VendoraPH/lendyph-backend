<?php

namespace App\Http\Requests\Branch;

use App\Models\Branch;
use App\Services\BranchAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchRequest extends FormRequest
{
    /**
     * A branch the caller can't see answers 404 exactly like a missing one
     * (decision B2), before the permission check and validation, so neither
     * a 403 nor a 422 reveals that it exists.
     */
    public function authorize(): bool
    {
        $branch = $this->route('branch');

        if (! app(BranchAccess::class)->canSee($this->user(), $branch->id)) {
            throw (new ModelNotFoundException)->setModel(Branch::class, [$branch->id]);
        }

        return $this->user()->can('settings:update');
    }

    public function rules(): array
    {
        $branchId = $this->route('branch')->id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:20', Rule::unique('branches')->ignore($branchId)],
            'address' => ['nullable', 'string', 'max:500'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
