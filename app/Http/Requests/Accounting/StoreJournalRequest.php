<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A manual journal entry, straight off the entry form.
 *
 * ## `total_debit` and `total_credit` are accepted and IGNORED
 *
 * `buildJournalPayload` sends them, and there is no reason to 422 a client for
 * being complete — but they are not read anywhere, by anything. The totals of a
 * journal are recomputed from the persisted lines when it posts (see
 * JournalPoster::post()), because taking a client's totals as authoritative is
 * exactly how a client bug becomes a LEDGER bug: a header that agreed with
 * itself while disagreeing with its own lines would survive every report in the
 * system, none of which join the two.
 *
 * ## `source` is forced to `manual`
 *
 * This route is the manual-entry screen and nothing else. `source` is what lets
 * any figure on any statement be traced back to the lending transaction that
 * caused it, so a client that could label its entry `loan_collection` could
 * make a hand-typed adjustment indistinguishable from an automatic posting.
 * Automatic sources are set by the posting engine, in the same transaction as
 * the event they describe.
 */
class StoreJournalRequest extends FormRequest
{
    use ValidatesJournalLines;

    public function authorize(): bool
    {
        return $this->user()->can('journals:create');
    }

    public function rules(): array
    {
        return array_merge([
            'date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:64'],
            'description' => ['required', 'string', 'max:500'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            // Accepted so a complete payload is not refused; never read.
            'total_debit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'total_credit' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ], $this->lineRules());
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->validateLineSides($v));
    }

    /**
     * The header, with `source` fixed and the client's totals dropped.
     *
     * @return array<string, mixed>
     */
    public function journalAttributes(): array
    {
        $validated = $this->validated();

        return [
            'date' => $validated['date'],
            'source' => 'manual',
            'reference' => $this->nullIfBlank($validated['reference'] ?? null),
            'description' => trim((string) $validated['description']),
            'branch_id' => $validated['branch_id'] ?? null,
        ];
    }

    private function nullIfBlank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
