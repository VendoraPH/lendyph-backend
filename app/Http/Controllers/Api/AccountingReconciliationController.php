<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\MatchReconciliationRequest;
use App\Http\Requests\Accounting\StoreReconciliationRequest;
use App\Models\AccountingAccount;
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
 * Per account per branch (owner answer 9 and the lead's decision,
 * 2026-11-11). Money accounts stay organisation-wide; each reconciliation
 * carries the branch whose journal lines it proves, picked when it starts. A
 * caller with `accounting:reconcile` picks one of their own branches and sees
 * and acts only on their branches' reconciliations; `branches:view_all` may
 * also pick none ("All branches", every line on the account, as every
 * reconciliation counted before) and sees them all. Another branch's
 * reconciliation, or an "all branches" one to a branch user, answers 404 like
 * a missing one (the route binding is scoped).
 */
class AccountingReconciliationController extends Controller
{
    use DetectsConcurrencyErrors, ResolvesPostingAccounts;

    public const ALREADY_MATCHED = 'This statement line is already matched. Unmatch it first.';

    public const CLAIMED_MEANWHILE = 'That ledger entry was just matched to another statement line. Reload and try again.';

    public const START_COLLIDED = 'Someone else was starting a reconciliation at the same moment. Nothing was saved; try again.';

    /** A money account's kind in words, as the accounting screens read it. */
    private const CASH_KIND_WORDS = ['cash' => 'Cash', 'bank' => 'Bank', 'gcash' => 'GCash', 'maya' => 'Maya', 'wallet' => 'Wallet'];

    /** The UNIQUE index on (account, `branch_scope`, period): one per account, branch and period. */
    private const PERIOD_INDEX = 'acct_recon_account_branch_period_unique';

    /** The UNIQUE index on accounting_reconciliation_lines.matched_journal_line_id. */
    private const MATCH_INDEX = 'acct_recon_lines_journal_line_unique';

    public function __construct(private ReconciliationMatcher $matcher) {}

    #[OA\Get(
        path: '/api/accounting/reconciliations',
        summary: 'List reconciliations',
        description: 'One page of reconciliations, newest period first, each with its full worksheet of lines. Returns the raw Laravel paginator envelope ({data, links, meta}); the screen drains it. `book_balance` and `difference` are computed from the ledger on every read, never stored — so posting the entry that was missing CLOSES the difference instead of leaving a stale gap. `per_page` is clamped at 100. `is_reconciled` is true only when `difference` is 0 and every statement line is matched (a `possible` row is not). The worksheet `lines` also hold outstanding items: entries dated up to 180 days before the period or 5 days after it that no statement line has claimed; such a row carries `outside_period: true` and its own `date`. Every leg of a reversal is an ordinary ledger line, and no row is ever left off the worksheet. Every row carries `matchable`: exactly whether match accepts its ledger line for that row\'s statement line, amount and side included, or on a books-only row for a statement line of its own amount and side (false for a draft, a line another statement line matched, or no ledger line). A paired row\'s `date` and `amount` are the ledger entry\'s; `statement_date` and `statement_amount` are the statement line\'s own (a statement-only row carries its own, a books-only row null). `unmatched_count` counts in-period rows only; `outstanding_count` counts unmatched entries dated before the period, except a reversal pair that nets to nothing as of `end_date` (both legs dated on or before it, and neither journal matched or suggested on this account; reversal links dated after it are ignored). Each row left out that way carries `netted: true`; every other row `netted: false`. `outstanding_count` is as of now: it reads the matches stored today, so a later match can change an earlier month\'s count.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15, maximum: 100)),
            new OA\Parameter(name: 'account_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, description: 'Only the reconciliations of one of the caller\'s branches (another answers 403), or `all` for the "All branches" ones (branches:view_all only, else 403). Anything else answers 422.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated reconciliations of the caller\'s branches (every one, "all branches" included, for branches:view_all)'),
            new OA\Response(response: 403, description: 'Missing accounting:reconcile, a branch_id outside the caller\'s branches, or `all` without branches:view_all'),
            new OA\Response(response: 422, description: 'A branch_id that is neither a branch id nor `all`'),
        ],
    )]
    public function index(): JsonResponse
    {
        $this->authorize('accounting:reconcile');

        $user = request()->user();

        $filters = request()->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            // `min:1`: `?per_page=0` reaches Builder::paginate() as
            // `$perPage ?: $model->getPerPage()` and silently becomes 15.
            'per_page' => ['nullable', 'integer', 'min:1'],
            'account_id' => ['nullable', 'integer', 'exists:accounting_accounts,id'],
            // A branch id, or `all` for the "All branches" reconciliations.
            'branch_id' => ['nullable', 'regex:/^(all|[1-9][0-9]*)$/'],
        ], [
            'branch_id.regex' => 'Filter by a branch, or by "all" for the reconciliations of all branches.',
        ]);

        $allBranches = ($filters['branch_id'] ?? null) === 'all';

        // 403 rather than an empty page, as on every branch filter: only
        // branches:view_all sees "All branches" reconciliations.
        if ($allBranches) {
            abort_unless($user->can(BranchAccess::VIEW_ALL), 403, 'You do not have access to the reconciliations of all branches.');
        } else {
            app(BranchAccess::class)->authorizeBranchFilter($user, $filters['branch_id'] ?? null);
        }

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        $page = AccountingReconciliation::query()
            ->visibleTo($user)
            ->when(
                isset($filters['account_id']),
                fn ($q) => $q->where('accounting_account_id', $filters['account_id']),
            )
            ->when($allBranches, fn ($q) => $q->whereNull('branch_id'))
            ->when(
                isset($filters['branch_id']) && ! $allBranches,
                fn ($q) => $q->where('branch_id', (int) $filters['branch_id']),
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

    #[OA\Get(
        path: '/api/accounting/reconciliations/accounts',
        summary: 'The accounts a reconciliation can be started on',
        description: 'Needs `accounting:reconcile` only, so a role that reconciles without the chart of accounts can start one. Exactly the accounts `POST /accounting/reconciliations` accepts: active money accounts (cash, bank, GCash, Maya, e-wallet) that aren\'t headings, in code order. Each: `id`, `code`, `name`, `cash_kind` and `type` (the kind in words: Cash, Bank, GCash, Maya, Wallet). No balances. Money accounts are organisation-wide, so the list is the same for every branch; the branch is picked when the reconciliation starts. Writes nothing.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, description: 'Optional. Doesn\'t narrow the list; a branch outside the caller\'s answers 403.', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: '{data: [{id, code, name, cash_kind, type}]}'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing accounting:reconcile, or a branch_id outside the caller\'s branches'),
            new OA\Response(response: 422, description: 'A branch_id that is not a positive integer'),
        ],
    )]
    public function accounts(): JsonResponse
    {
        $this->authorize('accounting:reconcile');

        $filters = request()->validate(['branch_id' => ['nullable', 'integer', 'min:1']]);

        // The accounts are organisation-wide, so a branch doesn't narrow the
        // list; one outside the caller's branches still answers 403, as on
        // every accounting read.
        app(BranchAccess::class)->authorizeBranchFilter(request()->user(), $filters['branch_id'] ?? null);

        // The same rule as requireMoneyAccount() on store(): postable (active,
        // not a heading) and carrying a cash_kind.
        $accounts = AccountingAccount::query()
            ->whereNotNull('cash_kind')
            ->where('is_group', false)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'cash_kind']);

        return response()->json([
            'data' => $accounts->map(fn (AccountingAccount $account): array => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'cash_kind' => $account->cash_kind,
                'type' => self::CASH_KIND_WORDS[$account->cash_kind] ?? ucfirst(str_replace('_', ' ', (string) $account->cash_kind)),
            ])->values(),
        ]);
    }

    #[OA\Post(
        path: '/api/accounting/reconciliations',
        summary: 'Start a reconciliation',
        description: 'Records what the statement says and, optionally, its lines. A period covers at most 92 days. Writes NO journal — reconciling proves the books, it does not adjust them. `branch_id` is the branch whose journal lines it proves: one of the caller\'s branches, required for a branch user; branches:view_all may leave it out or null for "All branches" (every line on the account). One reconciliation per account, branch (or all branches) and period; a second is a 422 rather than a silent second card. Amounts are INTEGER CENTAVOS, signed, positive for money in.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['account_id', 'start_date', 'end_date', 'statement_balance'],
                properties: [
                    new OA\Property(property: 'account_id', type: 'integer', description: 'A money account — one carrying a cash_kind.'),
                    new OA\Property(property: 'branch_id', type: 'integer', nullable: true, description: 'One of the caller\'s branches. Null or absent: all branches, branches:view_all only.'),
                    new OA\Property(property: 'period', type: 'string', nullable: true, description: 'Derived from the range when omitted: "September 2026" for a whole calendar month, otherwise the dates in words, "Sep 3 – Sep 20, 2026" ("Dec 15, 2026 – Jan 10, 2027" across a year end).'),
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
            new OA\Response(response: 403, description: 'Missing accounting:reconcile'),
            new OA\Response(response: 409, description: 'A deadlock or lock wait with another save; nothing was saved'),
            new OA\Response(response: 422, description: 'Not a money account, a branch that is not the caller\'s (or none, from a branch user), this account and branch already have this period, or the period is longer than 92 days'),
        ],
    )]
    public function store(StoreReconciliationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $branchId = filled($data['branch_id'] ?? null) ? (int) $data['branch_id'] : null;

        // An account with no `cash_kind` has no statement to be proved against.
        // Reconciling "Loans Receivable" against a bank statement is a question
        // with no answer, and the difference it produced would be the whole
        // balance.
        $account = $this->requireMoneyAccount((int) $data['account_id'], 'account_id');

        $period = $data['period'] ?? null;
        $labels = [$period];

        if ($period === null || trim($period) === '') {
            $period = $this->periodLabel((string) $data['start_date'], (string) $data['end_date']);
            // A part-month range was labelled with raw dates before they read
            // in words; the same range stored under that label is the same
            // period.
            $labels = array_values(array_unique([$period, $this->legacyPeriodLabel((string) $data['start_date'], (string) $data['end_date'])]));
        }

        $existing = AccountingReconciliation::query()
            ->where('accounting_account_id', $account->id)
            ->when(
                $branchId === null,
                fn ($q) => $q->whereNull('branch_id'),
                fn ($q) => $q->where('branch_id', $branchId),
            )
            ->whereIn('period', $labels)
            ->value('period');

        if ($existing !== null) {
            // The unique index refuses this anyway; catching it here turns what
            // would surface as a 500 into something the screen can render, and
            // says which of the two fields to change.
            throw $this->alreadyReconciled($account, $existing, $branchId);
        }

        try {
            $reconciliation = $this->createWithLines($data, $account, $branchId, $period, $request);
        } catch (UniqueConstraintViolationException $e) {
            // Two starts at once: both passed the check above, and the index
            // refused the second. The same answer as a second start.
            if ($e->index !== self::PERIOD_INDEX) {
                throw $e;
            }

            throw $this->alreadyReconciled($account, $period, $branchId);
        } catch (PDOException $e) {
            // A deadlock or lock wait timeout: 409 with nothing saved, as
            // LoanWriteTransaction answers, never 500.
            if (! $this->causedByConcurrencyError($e)) {
                throw $e;
            }

            throw LoanWriteTransaction::conflict(self::START_COLLIDED);
        }

        return response()->json(
            ['data' => $this->matcher->present($reconciliation->refresh())],
            201,
        );
    }

    /** The 422 on `period` for a second reconciliation of one account, branch and period. */
    private function alreadyReconciled(AccountingAccount $account, string $period, ?int $branchId): ValidationException
    {
        return ValidationException::withMessages([
            'period' => [
                "{$account->code} {$account->name} already has a reconciliation for {$period}. "
                .($branchId === null ? 'It covers all branches. ' : 'It covers this branch. ')
                .'Open that one rather than starting a second, so its matches stay in one place.',
            ],
        ]);
    }

    /**
     * The reconciliation and its statement lines, in one transaction.
     *
     * @param  array<string, mixed>  $data
     */
    private function createWithLines(
        array $data,
        AccountingAccount $account,
        ?int $branchId,
        string $period,
        StoreReconciliationRequest $request,
    ): AccountingReconciliation {
        return DB::transaction(function () use ($data, $account, $branchId, $period, $request): AccountingReconciliation {
            $reconciliation = AccountingReconciliation::create([
                'accounting_account_id' => $account->id,
                'branch_id' => $branchId,
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
    }

    #[OA\Get(
        path: '/api/accounting/reconciliations/{id}',
        summary: 'Show a reconciliation',
        description: 'The full worksheet: confirmed pairs, the engine\'s suggestions, and every line left over on either side, plus outstanding items from up to 180 days before the period to 5 days after it (`outside_period: true`). Same shape as one row of the list.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Reconciliation with its lines'),
            new OA\Response(response: 403, description: 'Missing accounting:reconcile'),
            new OA\Response(response: 404, description: 'Not found, another branch\'s, or an all-branches one to a branch user'),
        ],
    )]
    public function show(AccountingReconciliation $reconciliation): JsonResponse
    {
        $this->authorize('accounting:reconcile');

        return response()->json(['data' => $this->matcher->present($reconciliation)]);
    }

    #[OA\Post(
        path: '/api/accounting/reconciliations/{id}/match',
        summary: 'Confirm or undo pairings',
        description: 'Either `{"matches":[{"line_id":1,"journal_line_id":2}]}` (a null `journal_line_id` UNMATCHES) or `{"accept_suggestions":true}`, which confirms every row currently marked `possible`. Suggestions are re-derived server-side, so a stale tab cannot write pairings the books no longer support. A ledger line may be claimed by only one statement line, and a draft never. Besides the period\'s own lines, an outstanding item can be matched: an entry dated up to 180 days before the period or 5 days after it. A match needs the statement line\'s own amount and side; otherwise it answers 422 in words ("This statement line is ₱88.00 out; the entry is ₱2,000.00 in. ..."). A suggestion always has the same amount. Every leg of a reversal is an ordinary line. Each row\'s `matchable` uses the same rule. Returns the whole reconciliation.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent),
        responses: [
            new OA\Response(response: 200, description: 'Updated reconciliation'),
            new OA\Response(response: 403, description: 'Missing accounting:reconcile'),
            new OA\Response(response: 404, description: 'Not found, another branch\'s, or an all-branches one to a branch user'),
            new OA\Response(response: 409, description: 'The statement line is already matched to another ledger line (unmatch it first; accept-suggestions skips such lines instead), the ledger line was claimed at the same moment, or a lock collided'),
            new OA\Response(response: 422, description: 'A line belongs elsewhere, the journal is a draft, the entry is in another branch\'s books than the reconciliation\'s (or in none), the ledger line is outside the period and not an outstanding item (180 days before to 5 days after), it is already claimed, or its amount or side differs from the statement line\'s'),
        ],
    )]
    public function match(
        MatchReconciliationRequest $request,
        AccountingReconciliation $reconciliation,
    ): JsonResponse {
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

                    $this->assertLedgerLineIsAvailable($reconciliation, $line, $journalLineId);

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
     * The ledger line has to be in the books (not a draft), on THIS account,
     * inside THIS period or an outstanding item of it (up to
     * {@see ReconciliationMatcher::OUTSTANDING_DAYS_BEFORE} days before or
     * {@see ReconciliationMatcher::DATE_WINDOW_DAYS} days after), and not
     * already claimed by another statement line, and for the statement line's
     * own amount and side ("This statement line is ₱88.00 out; the entry is
     * ₱2,000.00 in."). Every leg of a reversal is an ordinary line. The rule is {@see ReconciliationMatcher::matchRefusal()},
     * the same one behind each worksheet row's `matchable`.
     *
     * The last check is the one that matters most. A ₱5,000 deposit that
     * appears twice on a statement could otherwise be matched twice to the
     * single ledger entry behind it, and the reconciliation would report itself
     * clean while one of the two deposits was genuinely missing from the books
     * — a green screen standing for the exact condition it exists to detect.
     * The claim is read under a lock, and the unique index on
     * `matched_journal_line_id` is the backstop; this is what makes the
     * refusal a message rather than a 500.
     */
    private function assertLedgerLineIsAvailable(
        AccountingReconciliation $reconciliation,
        AccountingReconciliationLine $statementLine,
        int $journalLineId,
    ): void {
        $line = ReconciliationMatcher::ledgerLine($journalLineId);

        if ($line === null) {
            throw ValidationException::withMessages([
                'matches' => ["Journal line {$journalLineId} does not exist."],
            ]);
        }

        $line['claimed_by_line'] = AccountingReconciliationLine::query()
            ->where('matched_journal_line_id', $journalLineId)
            ->lockForUpdate()
            ->value('id');

        $refusal = ReconciliationMatcher::matchRefusal($reconciliation, $line, $statementLine);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['matches' => [$refusal]]);
        }
    }

    /**
     * "September 2026" when the range is exactly a calendar month, and the two
     * dates in words otherwise: "Sep 3 – Sep 20, 2026", with the year once
     * when both dates share it, "Dec 15, 2026 – Jan 10, 2027" when they don't.
     *
     * Derived rather than typed so the common case cannot be spelled two
     * different ways for the same month — which, given the unique index on
     * (account, `branch_scope`, period), is the difference between "you
     * already reconciled this" and two cards for September sitting side by
     * side.
     */
    private function periodLabel(string $start, string $end): string
    {
        $from = CarbonImmutable::parse($start);
        $to = CarbonImmutable::parse($end);

        if ($from->isSameDay($from->startOfMonth()) && $to->isSameDay($from->endOfMonth())) {
            return $from->format('F Y');
        }

        if ($from->year === $to->year) {
            return $from->format('M j').' – '.$to->format('M j, Y');
        }

        return $from->format('M j, Y').' – '.$to->format('M j, Y');
    }

    /**
     * The label a part-month range was stored with before dates read in
     * words, "2026-09-03 – 2026-09-20". Read only by the duplicate check;
     * stored labels are never rewritten.
     */
    private function legacyPeriodLabel(string $start, string $end): string
    {
        return CarbonImmutable::parse($start)->toDateString().' – '.CarbonImmutable::parse($end)->toDateString();
    }
}
