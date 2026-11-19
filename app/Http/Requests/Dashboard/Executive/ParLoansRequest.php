<?php

namespace App\Http\Requests\Dashboard\Executive;

use Illuminate\Validation\Rule;

/**
 * GET /dashboard/executive/par-loans: the loans behind a PAR figure (`par`
 * 1, 30, 60 or 90; 30 by default), a page at a time. Needs the executive
 * dashboard, the reports the PAR figure comes from, and the loans.
 */
class ParLoansRequest extends ExecutiveDashboardRequest
{
    public const REFUSAL = 'The loans behind Portfolio at Risk need access to the executive dashboard, to reports and to loans.';

    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    public function authorize(): bool
    {
        return parent::authorize() && $this->user()->can('reports:view') && $this->user()->can('loans:view');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'par' => ['nullable', Rule::in(['1', '30', '60', '90'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [...parent::messages(), 'par.in' => 'Pick Portfolio at Risk 1, 30, 60 or 90.'];
    }

    /** `par_1`, `par_30`, `par_60` or `par_90`. */
    public function par(): string
    {
        return 'par_'.($this->validated('par') ?? '30');
    }
}
