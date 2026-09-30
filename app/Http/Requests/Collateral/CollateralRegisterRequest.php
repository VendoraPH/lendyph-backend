<?php

namespace App\Http\Requests\Collateral;

use App\Services\CollateralRegister;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorisation and query for `GET /api/collaterals/register`.
 *
 * Gated on `collaterals:view`, the same as the collateral index it pages.
 * What else the caller holds changes what the register shows rather than
 * whether they may read it; see CollateralRegister.
 */
class CollateralRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('collaterals:view');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            // `min:1` for the same reason as on the index: 0 is an integer no
            // row carries.
            'collateral_type_id' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', 'string', Rule::in(CollateralRegister::SORTS)],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function search(): ?string
    {
        return $this->validated('search');
    }

    public function collateralTypeId(): ?int
    {
        $typeId = $this->validated('collateral_type_id');

        return $typeId === null ? null : (int) $typeId;
    }

    public function sort(): string
    {
        return $this->validated('sort') ?? 'member';
    }

    public function direction(): string
    {
        return $this->validated('direction') ?? 'asc';
    }

    /**
     * The requested page size, clamped to 1..100 like every other list.
     */
    public function perPage(): int
    {
        return min(max((int) ($this->validated('per_page') ?? 15), 1), 100);
    }
}
