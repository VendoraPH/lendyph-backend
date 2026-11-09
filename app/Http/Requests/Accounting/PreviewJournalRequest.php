<?php

namespace App\Http\Requests\Accounting;

use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\Money;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The lines the entry form has so far, for POST /accounting/journals/preview.
 *
 * The same per-side rules as a saved entry (ValidatesJournalLines: integer
 * centavos, 0 to Money::maxCentavos(), at most JournalPoster::MAX_LINES lines),
 * so every set of lines a draft accepts previews to the totals it stores. Left
 * out on purpose, because the form is mid-entry: the two-line minimum, the
 * account check and the one-side-per-line rule.
 */
class PreviewJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('journals:create');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'lines' => ['nullable', 'array', 'max:'.JournalPoster::MAX_LINES],
            'lines.*' => ['array'],
            'lines.*.account_id' => ['nullable', 'integer'],
            'lines.*.debit' => ['required', 'integer', 'min:0', 'max:'.Money::maxCentavos()],
            'lines.*.credit' => ['required', 'integer', 'min:0', 'max:'.Money::maxCentavos()],
        ];
    }

    /**
     * Each line's two sides as integers, in the order sent.
     *
     * @return list<array{debit:int, credit:int}>
     */
    public function previewLines(): array
    {
        return array_values(array_map(static fn (array $line): array => [
            'debit' => (int) $line['debit'],
            'credit' => (int) $line['credit'],
        ], $this->validated()['lines'] ?? []));
    }
}
