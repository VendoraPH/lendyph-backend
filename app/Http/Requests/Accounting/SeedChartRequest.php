<?php

namespace App\Http\Requests\Accounting;

use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Seeding the chart of accounts from a template (Phase 2, accounting round C).
 *
 * `template` is optional: absent or null seeds the lending company chart,
 * today's default. Any value outside ChartOfAccountsSeeder::TEMPLATES answers
 * 422 on `template`.
 */
class SeedChartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('chart_of_accounts:create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'template' => ['nullable', 'string', Rule::in(ChartOfAccountsSeeder::TEMPLATES)],
        ];
    }

    /** The template to seed, the default when none was sent. */
    public function template(): string
    {
        return $this->validated('template') ?? ChartOfAccountsSeeder::TEMPLATES[0];
    }
}
