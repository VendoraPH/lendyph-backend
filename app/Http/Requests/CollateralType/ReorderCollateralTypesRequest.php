<?php

namespace App\Http\Requests\CollateralType;

use App\Models\CollateralType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReorderCollateralTypesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('settings:update');
    }

    /**
     * The drag-and-drop settings screen sends the full list in its new order,
     * so every id must exist and none may repeat — a duplicate would give two
     * types the same position and make the resulting order arbitrary.
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'distinct', Rule::exists('collateral_types', 'id')],
        ];
    }

    /**
     * `ids` must name every collateral type, which is what the settings screen
     * already sends. A partial list would renumber only the ids given and leave
     * the rest holding stale positions, so the saved order would not be the
     * order anyone saw.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $ids = $this->input('ids');

                if (is_array($ids) && count($ids) !== CollateralType::count()) {
                    $validator->errors()->add('ids', 'ids must list every collateral type exactly once.');
                }
            },
        ];
    }
}
