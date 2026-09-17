<?php

namespace App\Http\Requests\Accounting;

use App\Services\Accounting\Money;
use Illuminate\Contracts\Validation\Validator;

/**
 * The per-line shape rules, shared by create and update so the two cannot drift.
 *
 * Mirrors `validateJournalDraft` in `@/lib/accounting/journal` and the CHECK
 * constraint on `accounting_journal_lines`. Three statements of one rule is two
 * more than ideal, and each is load-bearing: the browser one is instant, this
 * one is the boundary a non-browser client also crosses, and the database one
 * is the guarantee.
 */
trait ValidatesJournalLines
{
    /**
     * Rules for the line array. `min:2` is not a formality — an entry with one
     * line is not a journal, it is half of one.
     *
     * @return array<string, array<int, string>>
     */
    protected function lineRules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer', 'exists:accounting_accounts,id'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            // INTEGER CENTAVOS, both of them, and `min:0` rather than `min:1`
            // because the unused side of a line is 0. Not `numeric`: a decimal
            // here would mean a caller sent pesos, and silently rounding pesos
            // into centavos is how an entry that balanced on screen stops
            // balancing in the books.
            //
            // `max:` is load-bearing and its absence was invisible. The columns
            // are UNSIGNED BIGINT, so a BALANCED pair of ₱92 quadrillion lines
            // satisfies both CHECK constraints, balances, records a non-zero
            // amount, and posts — landing in the trial balance and on the
            // dashboard as a real figure. Nothing downstream would refuse it.
            'lines.*.debit' => ['required', 'integer', 'min:0', 'max:'.Money::maxCentavos()],
            'lines.*.credit' => ['required', 'integer', 'min:0', 'max:'.Money::maxCentavos()],
        ];
    }

    /**
     * Exactly one side per line — never both, never neither.
     *
     * Both sides at once still sums correctly into both totals, so the entry
     * BALANCES and the trial balance reports healthy books while the account's
     * ledger shows a movement that happened in neither direction. Neither side
     * puts an account into an entry it took no part in. Both are invisible
     * downstream, which is why they are refused at the boundary.
     */
    protected function validateLineSides(Validator $validator): void
    {
        $lines = $this->input('lines');

        if (! is_array($lines)) {
            return;
        }

        foreach ($lines as $index => $line) {
            if (! is_array($line) || $validator->errors()->has("lines.{$index}.debit") || $validator->errors()->has("lines.{$index}.credit")) {
                continue;
            }

            $debit = (int) ($line['debit'] ?? 0);
            $credit = (int) ($line['credit'] ?? 0);
            $label = 'Line '.($index + 1);

            if ($debit !== 0 && $credit !== 0) {
                $validator->errors()->add(
                    "lines.{$index}.debit",
                    "{$label} has both a debit and a credit — a line can only be one side.",
                );

                continue;
            }

            if ($debit === 0 && $credit === 0) {
                $validator->errors()->add(
                    "lines.{$index}.debit",
                    "Enter a debit or a credit for {$label}.",
                );
            }
        }
    }

    /**
     * The lines, as JournalPoster wants them, with the client's own line
     * numbering discarded — position in the array IS the line number.
     *
     * Public because the CONTROLLER reads it. A protected helper here resolves
     * through Request::__call and comes back as "method does not exist" — a 500
     * on an otherwise valid payload.
     *
     * @return list<array{account_id:int, description:string|null, debit:int, credit:int}>
     */
    public function journalLines(): array
    {
        return array_values(array_map(static fn (array $line): array => [
            'account_id' => (int) $line['account_id'],
            'description' => isset($line['description']) && trim((string) $line['description']) !== ''
                ? trim((string) $line['description'])
                : null,
            'debit' => (int) ($line['debit'] ?? 0),
            'credit' => (int) ($line['credit'] ?? 0),
        ], $this->validated()['lines']));
    }
}
