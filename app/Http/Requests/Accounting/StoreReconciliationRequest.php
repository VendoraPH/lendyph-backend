<?php

namespace App\Http\Requests\Accounting;

use App\Services\Accounting\Money;
use App\Services\Accounting\ReconciliationMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Starting a reconciliation for one money account and one period.
 *
 * `lines` are the statement's own rows, typed in or imported. They are optional
 * — a reconciliation with a statement balance and no lines still answers the
 * only question that matters first ("do the two totals agree?"), and the lines
 * are what turn a "no" into a list of things to look at.
 */
class StoreReconciliationRequest extends FormRequest
{
    /**
     * A statement with more lines than this is a file to import, not a form to
     * submit. The bound exists so one request cannot become an unbounded insert
     * — and so the matching engine, which is O(statement x ledger), cannot be
     * handed a pair of lists that turns a page load into a minute.
     */
    public const MAX_LINES = 1000;

    public function authorize(): bool
    {
        return $this->user()->can('accounting:reconcile');
    }

    public function rules(): array
    {
        return [
            // Checked as a MONEY account in the controller — an account with no
            // `cash_kind` has no statement to be proved against.
            'account_id' => ['required', 'integer', 'exists:accounting_accounts,id'],
            // Optional: derived from the range when absent, so the common case
            // ("September 2026") needs no typing and cannot be typed two
            // different ways for the same month.
            'period' => ['nullable', 'string', 'max:48'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            /*
             * CENTAVOS, as an integer, and SIGNED — an overdrawn account has a
             * negative statement balance and has to be recordable. `integer`
             * refuses a client that sent pesos rather than flooring it, which
             * here would understate the statement a hundredfold and produce a
             * difference nobody could explain.
             */
            'statement_balance' => [
                'required', 'integer',
                'min:-'.Money::maxCentavos(), 'max:'.Money::maxCentavos(),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],

            'lines' => ['nullable', 'array', 'max:'.self::MAX_LINES],
            'lines.*.date' => ['required', 'date_format:Y-m-d'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            // Signed, and NOT zero: a zero-amount line is not a movement, and
            // it would match every other zero line on amount alone — turning
            // the engine's one hard signal into noise. The column has a CHECK
            // saying the same thing.
            'lines.*.amount' => [
                'required', 'integer', 'not_in:0',
                'min:-'.Money::maxCentavos(), 'max:'.Money::maxCentavos(),
            ],
            'lines.*.external_reference' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            foreach ((array) $this->input('lines', []) as $i => $line) {
                $date = $line['date'] ?? null;

                if ($date === null) {
                    continue;
                }

                // A statement line outside the period it belongs to is almost
                // always a typo in the year, and left alone it would sit
                // permanently unmatched against a ledger the engine only reads
                // inside the window — an unexplainable row rather than an
                // obvious mistake. The window is widened by the matching
                // tolerance, because value dating legitimately pushes a line a
                // few days past the statement's own end.
                $start = CarbonImmutable::parse((string) $this->input('start_date'))
                    ->subDays(ReconciliationMatcher::DATE_WINDOW_DAYS);
                $end = CarbonImmutable::parse((string) $this->input('end_date'))
                    ->addDays(ReconciliationMatcher::DATE_WINDOW_DAYS);

                $parsed = CarbonImmutable::parse((string) $date);

                if ($parsed->lt($start) || $parsed->gt($end)) {
                    $v->errors()->add(
                        "lines.{$i}.date",
                        "{$date} is outside the period being reconciled. A line dated outside the window can "
                        .'never be matched, because the ledger is only read inside it.',
                    );
                }
            }
        });
    }
}
