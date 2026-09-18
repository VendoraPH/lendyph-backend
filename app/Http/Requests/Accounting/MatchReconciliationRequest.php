<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirming — or undoing — the pairing of statement lines to ledger lines.
 *
 * Two modes, and a request must pick exactly one:
 *
 * - `{"matches": [{"line_id": 12, "journal_line_id": 88}, …]}` — explicit.
 *   A `journal_line_id` of `null` UNMATCHES that statement line, which is the
 *   only way back from a confirmation someone made in error.
 * - `{"accept_suggestions": true}` — confirms every row the engine currently
 *   marks `possible`, exactly as shown. The suggestions are re-derived
 *   server-side rather than taken from the client, so what is written is what
 *   the books actually support and not what a stale tab remembered.
 *
 * Sending both is refused rather than resolved by precedence. "Confirm these
 * two, and also everything you suggested" is not a coherent instruction, and
 * guessing which half the user meant would write pairings nobody chose.
 */
class MatchReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('accounting:reconcile');
    }

    public function rules(): array
    {
        return [
            'matches' => ['nullable', 'array', 'max:1000'],
            'matches.*.line_id' => ['required', 'integer', 'exists:accounting_reconciliation_lines,id'],
            // Nullable on purpose — null is "unmatch this line".
            'matches.*.journal_line_id' => ['nullable', 'integer', 'exists:accounting_journal_lines,id'],
            'accept_suggestions' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $explicit = $this->filled('matches');
            $accept = $this->boolean('accept_suggestions');

            if ($explicit && $accept) {
                $v->errors()->add(
                    'accept_suggestions',
                    'Send either an explicit list of matches or `accept_suggestions`, not both — there is no '
                    .'sensible way to combine them, and guessing would write pairings nobody chose.',
                );

                return;
            }

            if (! $explicit && ! $accept) {
                $v->errors()->add(
                    'matches',
                    'Nothing to do: send `matches` to pair lines explicitly, or `accept_suggestions` to confirm '
                    .'every possible match on screen.',
                );

                return;
            }

            if (! $explicit) {
                return;
            }

            // The same statement line named twice with different partners is a
            // client bug, and whichever one happened to be applied last would
            // win silently.
            $seen = [];

            foreach ((array) $this->input('matches', []) as $i => $match) {
                $lineId = (int) ($match['line_id'] ?? 0);

                if (isset($seen[$lineId])) {
                    $v->errors()->add(
                        "matches.{$i}.line_id",
                        'This statement line appears twice in the same request.',
                    );
                }

                $seen[$lineId] = true;
            }
        });
    }
}
