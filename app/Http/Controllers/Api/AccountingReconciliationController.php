<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\MatchReconciliationRequest;
use App\Http\Requests\Accounting\StoreReconciliationRequest;
use App\Models\AccountingReconciliation;
use App\Models\AccountingReconciliationLine;
use App\Services\Accounting\ReconciliationActions;
use App\Services\Accounting\ReconciliationMatcher;
use App\Services\Accounting\ResolvesPostingAccounts;
use App\Services\BranchAccess;
use App\Services\LoanWriteTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use PDOException;

/**
 * Reconciling a money account against its statement.
 *
 * NOTHING here writes a journal, and that is the point. Reconciling proves the
 * books against an outside record; it does not adjust them. When the difference
 * will not close, the remedy is a journal entry someone posts deliberately on
 * the Journals screen — not a correcting line this module slips in to make a
 * number look tidy.
 *
 * Every endpoint also requires `branches:view_all` (decision B2, interim
 * least privilege): a book balance is read from every branch's journals, and
 * whether a bank account belongs to a branch is not decided yet. Every seeded
 * role holding `accounting:reconcile` already holds `branches:view_all`.
 */
class AccountingReconciliationController extends Controller
{
    use DetectsConcurrencyErrors, ResolvesPostingAccounts;

    public const ALREADY_MATCHED = 'This statement line is already matched. Unmatch it first.';

    public const CLAIMED_MEANWHILE = 'That ledger entry was just matched to another statement line. Reload and try again.';

    /** The UNIQUE index on accounting_reconciliation_lines.matched_journal_line_id. */
    private const MATCH_INDEX = 'acct_recon_lines_journal_line_unique';

    public function __construct(private ReconciliationMatcher $matcher) {}

    #[OA\Get(
        path: '/api/accounting/reconciliations',
        summary: 'List reconciliations',
        description: 'One page of reconciliations, newest period first, each with its full worksheet of lines. Returns the raw Laravel paginator envelope ({data, links, meta}); the screen drains it. `book_balance` and `difference` are computed from the ledger on every read, never stored — so posting the entry that was missing CLOSES the difference instead of leaving a stale gap. `per_page` is clamped at 100. `is_reconciled` is true only when `difference` is 0 and every statement line is matched (a `possible` row is not). The worksheet `lines` also hold outstanding items: entries dated up to 60 days before the period or 5 days after it that no statement line has claimed; such a row carries `outside_period: true` and its own `date`. Reversals are judged as of `end_date`: a pair whose legs are both dated on or before it, neither matched, is never suggested or listed as outstanding. Every row carries `matchable` (exactly whether match accepts its ledger line by hand: false for a draft, a line another reconciliation matched, a leg outside the period that was not in the books at the period end, or no ledger line). `unmatched_count` counts in-period rows only; `outstanding_count` counts unmatched entries dated before the period.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15, maximum: 100)),
            new OA\Parameter(name: 'account_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated reconciliations'),
            new OA\Response(response: 403, description: 'Missing accounting:reconcile or branches:view_all'),
        ],
    )]
    public function index(): JsonResponse
    {
        $this->authorize('accounting:reconcile');
        $this->authorize(BranchAccess::VIEW_ALL);

        $filters = request()->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            // `min:1`: `?per_page=0` reaches Builder::paginate() as
            // `$perPage ?: $model->getPerPage()` and silently becomes 15.
            'per_page' => ['nullable', 'integer', 'min:1'],
            'account_id' => ['nullable', 'integer', 'exists:accounting_accounts,id'],
        ]);

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        $page = AccountingReconciliation::query()
            ->when(
                isset($filters['account_id']),
                fn ($q) => $q->where('accounting_account_id', $filters['account_id']),
            )
            // A TOTAL order. The screen drains every page and a partial order
            // lets one row be served twice while another is served not at all.
            ->newestFirst()
            ->paginate($perPage)
            ->withQueryString();

        // ONE pass over the whole page rather than the engine per row. See
        // ReconciliationMatcher::presentMany() — running it per row is an N+1
        // that scales with the page size and only appears once a co-op has a
        // year of history.
        $presented = $this->matcher->presentMany($page->getCollection());

        // Hand-built rather than through a JsonResource, because the payload is
        // the matcher's output and not a model's columns. The envelope is the
        // RAW Laravel paginator shape — `data`, `links`, `meta` at the top
        // level — which is what `api.getRaw` returns unwrapped and what
        // `fetchAllPages` reads `meta.last_page` and `meta.total` out of.
        return response()->json([
            'data' => array_values(array_map(
                static fn (AccountingReconciliation $r): array => $presented[$r->id],
                $page->items(),
            )),
            'links' => [
                'first' => $page->url(1),
                'last' => $page->url($page->lastPage()),
                'prev' => $page->previousPageUrl(),
                'next' => $page->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $page->currentPage(),
                'from' => $page->firstItem(),
                'last_page' => $page->lastPage(),
                'path' => $page->path(),
                'per_page' => $page->perPage(),
                'to' => $page->lastItem(),
                'total' => $page->total(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/accounting/reconciliations',
        summary: 'Start a reconciliation',
        description: 'Records what the statement says and, optionally, its lines. Writes NO journal — reconciling proves the books, it does not adjust them. One reconciliation per account per period; a second is a 422 rather than a silent second card. Amounts are INTEGER CENTAVOS, signed, positive for money in.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['account_id', 'start_date', 'end_date', 'statement_balance'],
                properties: [
                    new OA\Property(property: 'account_id', type: 'integer', description: 'A money account — one carrying a cash_kind.'),
                    new OA\Property(property: 'period', type: 'string', nullable: true, description: 'Derived from the range when omitted — "September 2026".'),
                    new OA\Property(property: 'start_date', type: 'string', format: 'date'),
                    new OA\Property(property: 'end_date', type: 'string', format: 'date'),
                    new OA\Property(property: 'statement_balance', type: 'integer', description: 'Centavos, signed.'),
                    new OA\Property(property: 'notes', type: 'string', nullable: true),
                    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object')),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Reconciliation created, with its worksheet'),
            new OA\Response(response: 403, description: 'Missing accounting:reconcile or branches:view_all'),
            new OA\Response(response: 422, description: 'Not a money account, or this account already has this period'),
        ],
    )]
    public function store(StoreReconciliationRequest $request): JsonResponse
    {
        $this->authorize(BranchAccess::VIEW_ALL);

        $data = $request->validated();

        // An account with no `cash_kind` has no statement to be proved against.
        // Reconciling "Loans Receivable" against a bank statement is a question
        // with no answer, and the difference it produced would be the whole
        // balance.
        $account = $this->requireMoneyAccount((int) $data['account_id'], 'account_id');

        $period = $data['period'] ?? null;

        if ($period === null || trim($period) === '') {
            $period = $this->periodLabel((string) $data['start_date'], (string) $data['end_date']);
        }

        $exists = AccountingReconciliation::query()
            ->where('accounting_account_id', $account->id)
            ->where('period', $period)
            ->exists();

        if ($exists) {
            // The unique index refuses this anyway; catching it here turns what
            // would surface as a 500 into something the screen can render, and
            // says which of the two fields to change.
            throw ValidationException::withMessages([
                'period' => [
                    "{$account->code} {$account->name} already has a reconciliation for {$period}. "
                    .'Open that one rather than starting a second — the screen keys its cards on the account '
                    .'and the period, so two would render as one and quietly hide the other.',
                ],
            ]);
        }

        $reconciliation = DB::transaction(function () use ($data, $account, $period, $request): AccountingReconciliation {
            $reconciliation = AccountingReconciliation::create([
                'accounting_account_id' => $account->id,
                'period' => $period,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'statement_balance' => (int) $data['statement_balance'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            $lines = $data['lines'] ?? [];

            if ($lines !== []) {
                $now = now();

                // One insert rather than a model per line. A thousand-line
                // statement is a thousand round trips otherwise, and none of
                // these rows has a model event worth firing.
                AccountingReconciliationLine::query()->insert(array_map(
                    static fn (array $line): array => [
                        'accounting_reconciliation_id' => $reconciliation->id,
                        'date' => $line['date'],
                        'description' => $line['description'],
                        'amount' => (int) $line['amount'],
                        'external_reference' => $line['external_reference'] ?? null,
                        'matched_journal_line_id' => null,
                        'created_by' => $request->user()?->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    $lines,
                ));
            }

            return $reconciliation;
        });

        return response()->json(
            ['data' => $this->matcher->present($reconciliation->refresh())],
            201,
        );
    }

    #[OA\Get(
        path: '/api/accounting/reconciliations/{id}',
        summary: 'Show a reconciliation',
        description: 'The full worksheet: confirmed pairs, the engine\'s suggestions, and every line left over on either side, plus outstanding items from up to 60 days before the period to 5 days after it (`outside_period: true`). Same shape as one row of the list.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Reconciliation with its lines'),
            new OA\Response(response: 403, description: 'Missing accounting:reconcile or branches:view_all'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(AccountingReconciliation $reconciliation): JsonResponse
    {
        $this->authorize('accounting:reconcile');
        $this->authorize(BranchAccess::VIEW_ALL);

        return response()->json(['data' => $this->matcher->present($reconciliation)]);
    }

    #[OA\Post(
        path: '/api/accounting/reconciliations/{id}/match',
        summary: 'Confirm or undo pairings',
        description: 'Either `{"matches":[{"line_id":1,"journal_line_id":2}]}` (a null `journal_line_id` UNMATCHES) or `{"accept_suggestions":true}`, which confirms every row currently marked `possible`. Suggestions are re-derived server-side, so a stale tab cannot write pairings the books no longer support. A ledger line may be claimed by only one statement line, and a draft never. Besides the period\'s own lines, an outstanding item can be matched: an entry dated up to 60 days before the period or 5 days after it that was in the books at the period end (a reversed entry is, when its reversal is dated after the period end or has been matched). Rows carry `matchable` for the picker. Returns the whole reconciliation.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent),
        responses: [
            new OA\Response(response: 200, description: 'Updated reconciliation'),
            new OA\Response(response: 403, description: 'Missing accounting:reconcile or branches:view_all'),
            new OA\Response(response: 409, description: 'The statement line is already matched to another ledger line (unmatch it first; accept-suggestions skips such lines instead), the ledger line was claimed at the same moment, or a lock collided'),
            new OA\Response(response: 422, description: 'A line belongs elsewhere, the journal is a draft, the ledger line is outside the period and not an outstanding item (60 days before to 5 days after, in the books at the period end), or it is already claimed'),
        ],
    )]
    public function match(
        MatchReconciliationRequest $request,
        AccountingReconciliation $reconciliation,
    ): JsonResponse {
        $this->authorize(BranchAccess::VIEW_ALL);

        $acceptingSuggestions = $request->boolean('accept_suggestions');

        $pairings = $acceptingSuggestions
            // Re-derived here rather than taken from the request. What gets
            // written is then exactly what the books support at this instant,
            // not what a tab left open since this morning still remembers.
            ? $this->matcher->suggestions($reconciliation)
            : collect($request->validated()['matches'] ?? [])
                ->mapWithKeys(static fn (array $m): array => [
                    (int) $m['line_id'] => isset($m['journal_line_id']) ? (int) $m['journal_line_id'] : null,
                ])
                ->all();

        try {
            DB::transaction(function () use ($pairings, $reconciliation, $acceptingSuggestions): void {
                // Every named statement line, locked in ONE read in id order
                // before anything else, so two requests naming the same lines
                // in different orders queue instead of deadlocking.
                $lines = AccountingReconciliationLine::query()
                    ->whereKey(array_keys($pairings))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                // Unmatches first, so one request can move a ledger line from
                // one statement line to another. Then in id order.
                ksort($pairings);
                $ordered = array_filter($pairings, static fn (?int $journalLineId): bool => $journalLineId === null)
                    + array_filter($pairings, static fn (?int $journalLineId): bool => $journalLineId !== null);

                foreach ($ordered as $lineId => $journalLineId) {
                    $line = $lines->get($lineId);

                    if ($line === null || $line->accounting_reconciliation_id !== $reconciliation->id) {
                        throw ValidationException::withMessages([
                            'matches' => ["Statement line {$lineId} does not belong to this reconciliation."],
                        ]);
                    }

                    if ($journalLineId === null) {
                        $line->matched_journal_line_id = null;
                        $line->save();

                        continue;
                    }

                    // Read under the line's lock, so of two requests pairing the
                    // same line the second sees the first's match.
                    if ($line->matched_journal_line_id !== null) {
                        // Suggestions were worked out before this transaction; a
                        // line matched since then is skipped, never re-paired.
                        if ($acceptingSuggestions) {
                            continue;
                        }

                        // The same pairing sent again changes nothing.
                        if ($line->matched_journal_line_id === $journalLineId) {
                            continue;
                        }

                        throw LoanWriteTransaction::conflict(self::ALREADY_MATCHED);
                    }

                    $this->assertLedgerLineIsAvailable($reconciliation, $lineId, $journalLineId);

                    $line->matched_journal_line_id = $journalLineId;
                    $line->save();
                }
            });
        } catch (UniqueConstraintViolationException $e) {
            // The backstop: another request claimed the ledger line between
            // this one's locked read and its write.
            if ($e->index === self::MATCH_INDEX) {
                throw LoanWriteTransaction::conflict(self::CLAIMED_MEANWHILE);
            }

            throw $e;
        } catch (PDOException $e) {
            // Deadlocks and lock waits, mapped as ReconciliationActions does.
            if (! $this->causedByConcurrencyError($e)) {
                throw $e;
            }

            throw LoanWriteTransaction::conflict(ReconciliationActions::COLLISION);
        }

        return response()->json(['data' => $this->matcher->present($reconciliation->refresh())]);
    }

    /**
     * The ledger line has to be in the books (not a draft), on THIS account, inside THIS period or
     * an outstanding item of it, and not already claimed by another statement
     * line.
     *
     * An outstanding item (the standard bank reconciliation rule) is a deposit
     * in transit or an uncleared cheque, dated up to
     * {@see ReconciliationMatcher::OUTSTANDING_DAYS_BEFORE} days before the
     * period or {@see ReconciliationMatcher::DATE_WINDOW_DAYS} days after it,
     * that was in the books at the period end
     * ({@see ReconciliationMatcher::standingAsOf()}). Inside the period only a
     * draft is refused: the bank may show a deposit and the bounce that
     * reversed it.
     *
     * The last check is the one that matters most. A ₱5,000 deposit that
     * appears twice on a statement could otherwise be matched twice to the
     * single ledger entry behind it, and the reconciliation would report itself
     * clean while one of the two deposits was genuinely missing from the books
     * — a green screen standing for the exact condition it exists to detect.
     * It is read under a lock, and the unique index on
     * `matched_journal_line_id` is the backstop; this is what makes the
     * refusal a message rather than a 500.
     */
    private function assertLedgerLineIsAvailable(
        AccountingReconciliation $reconciliation,
        int $statementLineId,
        int $journalLineId,
    ): void {
        $line = ReconciliationMatcher::ledgerLine($journalLineId);

        if ($line === null) {
            throw ValidationException::withMessages([
                'matches' => ["Journal line {$journalLineId} does not exist."],
            ]);
        }

        if ($line['status'] === 'draft') {
            throw ValidationException::withMessages([
                'matches' => [
                    "Journal line {$journalLineId} is a draft. Only posted entries are in the books, so post it "
                    .'before matching it.',
                ],
            ]);
        }

        if ($line['account_id'] !== $reconciliation->accounting_account_id) {
            throw ValidationException::withMessages([
                'matches' => [
                    "Journal line {$journalLineId} is against a different account. A reconciliation proves one "
                    .'account, so a line on another one cannot be part of it.',
                ],
            ]);
        }

        $date = $line['date'];
        $end = $reconciliation->end_date->toDateString();
        $inPeriod = $date >= $reconciliation->start_date->toDateString() && $date <= $end;

        if (! $inPeriod) {
            [$from, $to] = ReconciliationMatcher::candidateDates($reconciliation);

            if ($date < $from || $date > $to) {
                throw ValidationException::withMessages([
                    'matches' => [
                        "Journal line {$journalLineId} is dated {$date}, outside the period being reconciled. An "
                        .'entry from up to '.ReconciliationMatcher::OUTSTANDING_DAYS_BEFORE.' days before the period '
                        .'or '.ReconciliationMatcher::DATE_WINDOW_DAYS.' days after it can be matched as an '
                        .'outstanding item; this one is further away.',
                    ],
                ]);
            }

            if (! ReconciliationMatcher::standingAsOf($line, $end)['live']) {
                throw ValidationException::withMessages([
                    'matches' => [
                        "Journal line {$journalLineId} belongs to an entry reversed on {$line['partner_date']}, on or "
                        ."before this period's end ({$end}), so it was no longer in the books. Match its reversal "
                        .'first if the bank shows both.',
                    ],
                ]);
            }
        }

        $claimedBy = AccountingReconciliationLine::query()
            ->where('matched_journal_line_id', $journalLineId)
            ->where('id', '!=', $statementLineId)
            ->lockForUpdate()
            ->value('id');

        if ($claimedBy !== null) {
            throw ValidationException::withMessages([
                'matches' => [
                    "Journal line {$journalLineId} is already matched to statement line {$claimedBy}. One ledger "
                    .'entry cannot stand for two statement lines — if the statement really shows the same amount '
                    .'twice, one of them is missing from the books.',
                ],
            ]);
        }
    }

    /**
     * "September 2026" when the range is exactly a calendar month, and the two
     * dates otherwise.
     *
     * Derived rather than typed so the common case cannot be spelled two
     * different ways for the same month — which, given the unique index on
     * (account, period), is the difference between "you already reconciled
     * this" and two cards for September sitting side by side.
     */
    private function periodLabel(string $start, string $end): string
    {
        $from = CarbonImmutable::parse($start);
        $to = CarbonImmutable::parse($end);

        if ($from->isSameDay($from->startOfMonth()) && $to->isSameDay($from->endOfMonth())) {
            return $from->format('F Y');
        }

        return $from->toDateString().' – '.$to->toDateString();
    }
}
