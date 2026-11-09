<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\FinaliseOpeningBalanceRequest;
use App\Http\Requests\Accounting\SaveOpeningBalanceRequest;
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\AccountingOpeningBalance;
use App\Models\AccountingOpeningBalanceLine;
use App\Models\AccountingPeriod;
use App\Models\User;
use App\Services\Accounting\Money;
use App\Services\Accounting\OpeningBalancePoster;
use App\Services\Accounting\PeriodGuard;
use App\Services\AuditLogService;
use App\Services\BranchAccess;
use App\Services\LoanWriteTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * Opening balances on a screen (Phase 2, Accounting round C, slice C2).
 *
 * One entry for the whole organisation, with no branch, kept as a draft and
 * posted once on finalise through {@see OpeningBalancePoster}, the path the
 * `accounting:opening-balances` CLI shares. The screen never balances the
 * entry by itself: the server offers the 3050 line for the difference, and
 * the user adds it. Every figure here is the server's, in integer centavos.
 */
class AccountingOpeningBalanceController extends Controller
{
    public const STATUS_NONE = 'none';

    public const STATUS_POSTED_ELSEWHERE = 'posted_elsewhere';

    public function __construct(
        private OpeningBalancePoster $poster,
        private PeriodGuard $periods,
    ) {}

    #[OA\Get(
        path: '/api/accounting/opening-balances',
        summary: 'The opening balances entry, with the server\'s totals and the 3050 line that would balance it',
        description: '`{data: OpeningBalance}`: `status` (`none`, `draft`, `finalised`, `posted_elsewhere`), `id`, `as_of`, `lines[]` in code order, `total_debit`, `total_credit`, `difference`, `is_balanced`, `opening_equity_line`, `suggested_opening_equity_line`, `posted_journals_on_or_before_as_of`, `journal`, `edit_version`, `finalised_at`, `finalised_by`, `can_edit`, `can_finalise` and `blocking_reasons[]`. Integer centavos. Nothing is written.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The entry'),
            new OA\Response(response: 403, description: 'Missing accounting:view or branches:view_all'),
        ],
    )]
    public function show(): JsonResponse
    {
        $this->authorize('accounting:view');
        $this->authorize(BranchAccess::VIEW_ALL);

        return response()->json(['data' => $this->present(request()->user())]);
    }

    #[OA\Put(
        path: '/api/accounting/opening-balances',
        summary: 'Create the opening balances draft, or replace its lines',
        description: 'Body `{as_of, lines: [{account_id, debit, credit}], edit_version?}`, integer centavos, one side per line, one line per account, at most 500 lines (may be empty). Answers the same shape as GET.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The saved entry'),
            new OA\Response(response: 403, description: 'Missing accounting:settings or branches:view_all'),
            new OA\Response(response: 409, description: 'Already finalised or posted by the CLI, or a stale edit_version'),
            new OA\Response(response: 422, description: 'A bad line, or as_of in a closed period'),
        ],
    )]
    public function update(SaveOpeningBalanceRequest $request): JsonResponse
    {
        $asOf = (string) $request->validated('as_of');
        $lines = $request->openingLines();
        $editVersion = $request->has('edit_version') ? (int) $request->validated('edit_version') : null;
        $userId = (int) $request->user()->id;

        LoanWriteTransaction::run(
            fn () => $this->save($asOf, $lines, $editVersion, $userId),
            'accounting_opening_balances_singleton_unique',
            OpeningBalancePoster::STALE,
        );

        return response()->json([
            'message' => 'Opening balances saved.',
            'data' => $this->present($request->user()),
        ]);
    }

    #[OA\Post(
        path: '/api/accounting/opening-balances/finalise',
        summary: 'Post the opening balances draft, once, as one journal',
        description: 'Body `{edit_version}`. Posts the draft as one `opening_balance` journal dated `as_of`, with no branch. Answers the same shape as GET, with `status: finalised` and the journal.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The finalised entry'),
            new OA\Response(response: 403, description: 'Missing accounting:settings, journals:post or branches:view_all'),
            new OA\Response(response: 409, description: 'Already finalised, an opening balance already posted, or a changed edit_version'),
            new OA\Response(response: 422, description: 'Not balanced, or its period is closed'),
        ],
    )]
    public function finalise(FinaliseOpeningBalanceRequest $request): JsonResponse
    {
        $draft = AccountingOpeningBalance::query()->first();

        if ($draft === null) {
            $posted = $this->poster->openingJournal();

            if ($posted !== null) {
                throw OpeningBalancePoster::conflict('An opening balance has already been posted ('.$this->poster->journalLabel($posted).').');
            }

            throw ValidationException::withMessages(['lines' => ['Add at least two lines.']]);
        }

        $journal = $this->poster->finalise($draft, (int) $request->validated('edit_version'), (int) $request->user()->id);

        return response()->json([
            'message' => "Opening balances posted as {$journal->journal_no}.",
            'data' => $this->present($request->user()),
        ]);
    }

    /**
     * The save itself, inside the transaction. The same lock order as the
     * finalise (the allowance row when a line is on it, then 3050, then the
     * draft), so a save and a finalise queue rather than deadlock on the
     * account rows the new lines' foreign keys read.
     *
     * @param  list<array{account_id: int, debit: int, credit: int}>  $lines
     */
    private function save(string $asOf, array $lines, ?int $editVersion, int $userId): void
    {
        $this->poster->lockInOrder(array_column($lines, 'account_id'), requireOpeningEquity: false);

        $draft = AccountingOpeningBalance::query()->lockForUpdate()->first();

        if ($draft?->isFinalised()) {
            throw OpeningBalancePoster::conflict('The opening balances have already been finalised; they can\'t be changed.');
        }

        $posted = AccountingJournal::query()->where('source', OpeningBalancePoster::SOURCE)->sharedLock()->first();

        if ($posted !== null) {
            throw OpeningBalancePoster::conflict('An opening balance has already been posted ('.$this->poster->journalLabel($posted).'); it can\'t be changed here.');
        }

        if ($draft !== null && $editVersion === null) {
            throw ValidationException::withMessages([
                'edit_version' => ['Send the edit_version the opening balances were loaded at; a draft already exists.'],
            ]);
        }

        if ($editVersion !== null && $editVersion !== ($draft?->edit_version ?? 0)) {
            throw OpeningBalancePoster::conflict(OpeningBalancePoster::STALE);
        }

        try {
            $this->periods->assertOpen($asOf, 'The opening balances');
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['as_of' => $e->errors()['date'] ?? $e->errors()]);
        }

        $laterClosed = $this->poster->laterClosedReason($asOf, lock: true);

        if ($laterClosed !== null) {
            throw ValidationException::withMessages(['as_of' => [$laterClosed]]);
        }

        $old = $draft === null ? null : $this->summary($draft->as_of->toDateString(), $this->storedLines($draft));
        $new = $this->summary($asOf, $lines);

        if ($old !== null && $old === $new) {
            return;
        }

        if ($draft === null) {
            $draft = new AccountingOpeningBalance(['as_of' => $asOf, 'created_by' => $userId, 'updated_by' => $userId]);
            $draft->forceFill(['status' => AccountingOpeningBalance::STATUS_DRAFT, 'edit_version' => 1])->save();
        } else {
            $draft->forceFill([
                'as_of' => $asOf,
                'updated_by' => $userId,
                'edit_version' => $draft->edit_version + 1,
            ])->save();
            $draft->lines()->delete();
        }

        $draft->lines()->createMany(array_map(static fn (array $line): array => [
            'accounting_account_id' => $line['account_id'],
            'debit' => $line['debit'],
            'credit' => $line['credit'],
        ], $lines));

        // `_centavos` names, so the Audit Trail reads them as pesos.
        $codes = AccountingAccount::query()
            ->whereKey(array_unique([...array_column($lines, 'account_id'), ...array_column($old['lines'] ?? [], 'account_id')]))
            ->pluck('code', 'id');
        // Each line by account code, so a save that moves money between
        // accounts shows a difference even when the totals don't move.
        $figures = static fn (array $summary): array => [
            'as_of' => $summary['as_of'],
            'line_count' => $summary['line_count'],
            'total_debit_centavos' => $summary['total_debit'],
            'total_credit_centavos' => $summary['total_credit'],
            'lines' => collect($summary['lines'])
                ->map(static fn (array $line): array => [
                    'account' => (string) $codes->get($line['account_id']),
                    'debit_centavos' => $line['debit'],
                    'credit_centavos' => $line['credit'],
                ])
                ->sortBy('account')
                ->values()
                ->all(),
        ];

        if ($old === null) {
            AuditLogService::log(
                'accounting_opening_balance_created',
                auditable: $draft,
                newValues: $figures($new),
                description: sprintf('Started the opening balances as of %s with %d line(s)', $asOf, count($lines)),
                userId: $userId,
            );

            return;
        }

        AuditLogService::log(
            'accounting_opening_balance_saved',
            auditable: $draft,
            oldValues: $figures($old),
            newValues: $figures($new),
            description: sprintf(
                'Saved the opening balances as of %s: %d line(s), %s debit and %s credit',
                $asOf,
                count($lines),
                Money::format($new['total_debit']),
                Money::format($new['total_credit']),
            ),
            userId: $userId,
        );
    }

    /**
     * What a save compares and audits. `lines` is compared, not audited.
     *
     * @param  list<array{account_id: int, debit: int, credit: int}>  $lines
     * @return array{as_of: string, line_count: int, total_debit: int, total_credit: int, lines: list<array{account_id: int, debit: int, credit: int}>}
     */
    private function summary(string $asOf, array $lines): array
    {
        return [
            'as_of' => $asOf,
            'line_count' => count($lines),
            'total_debit' => Money::sum(array_column($lines, 'debit')),
            'total_credit' => Money::sum(array_column($lines, 'credit')),
            'lines' => $lines,
        ];
    }

    /**
     * A draft's stored lines, ordered by account like a request's.
     *
     * @return list<array{account_id: int, debit: int, credit: int}>
     */
    private function storedLines(AccountingOpeningBalance $draft): array
    {
        // A locking read, so it is current: the transaction's snapshot was
        // taken before it queued on the 3050 and draft locks.
        return $draft->lines()
            ->orderBy('accounting_account_id')
            ->sharedLock()
            ->get(['accounting_account_id', 'debit', 'credit'])
            ->map(static fn (AccountingOpeningBalanceLine $line): array => [
                'account_id' => (int) $line->accounting_account_id,
                'debit' => (int) $line->debit,
                'credit' => (int) $line->credit,
            ])
            ->all();
    }

    /**
     * The OpeningBalance shape. Every figure is built here; the browser
     * never adds lines.
     *
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        $draft = AccountingOpeningBalance::query()->with(['journal:id,journal_no', 'finaliser:id,first_name,last_name'])->first();
        $opening = $this->poster->openingJournal();

        $status = match (true) {
            $draft?->isFinalised() === true => AccountingOpeningBalance::STATUS_FINALISED,
            $opening !== null => self::STATUS_POSTED_ELSEWHERE,
            $draft !== null => AccountingOpeningBalance::STATUS_DRAFT,
            default => self::STATUS_NONE,
        };

        $asOf = $draft?->as_of?->toDateString() ?? ($status === self::STATUS_POSTED_ELSEWHERE ? $opening->date->toDateString() : null);
        $lines = $draft === null ? [] : $this->presentedLines($draft);
        $totalDebit = Money::sum(array_column($lines, 'debit'));
        $totalCredit = Money::sum(array_column($lines, 'credit'));
        $difference = $totalDebit - $totalCredit;
        $journal = $status === AccountingOpeningBalance::STATUS_FINALISED ? $draft->journal : ($status === self::STATUS_POSTED_ELSEWHERE ? $opening : null);

        $equityLine = null;

        foreach ($lines as $line) {
            if ($line['account_code'] === OpeningBalancePoster::OPENING_EQUITY_CODE) {
                $equityLine = $line;
            }
        }

        $blocking = $this->blockingReasons($journal, $lines, $asOf);
        $canEdit = $user->can('accounting:settings') && in_array($status, [self::STATUS_NONE, AccountingOpeningBalance::STATUS_DRAFT], true);

        return [
            'status' => $status,
            'id' => $draft?->id,
            'as_of' => $asOf,
            'lines' => $lines,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'difference' => $difference,
            'is_balanced' => count($lines) >= 2 && $totalDebit === $totalCredit && $totalDebit > 0,
            'opening_equity_line' => $equityLine,
            'suggested_opening_equity_line' => $this->suggestedEquityLine($difference, $equityLine),
            'posted_journals_on_or_before_as_of' => $asOf === null ? 0 : AccountingJournal::query()
                ->whereIn('status', ['posted', 'reversed'])
                ->where('source', '!=', OpeningBalancePoster::SOURCE)
                ->whereDate('date', '<=', $asOf)
                ->count(),
            'journal' => $journal === null ? null : ['id' => $journal->id, 'journal_no' => $journal->journal_no],
            'edit_version' => $draft?->edit_version ?? 0,
            'finalised_at' => $draft?->finalised_at?->toJSON(),
            'finalised_by' => $draft?->finaliser === null ? null : ['id' => $draft->finaliser->id, 'name' => $draft->finaliser->full_name],
            'can_edit' => $canEdit,
            'can_finalise' => $canEdit && $status === AccountingOpeningBalance::STATUS_DRAFT && $user->can('journals:post') && $blocking === [],
            'blocking_reasons' => $blocking,
        ];
    }

    /**
     * @return list<array{id: int, account_id: int, account_code: string, account_name: string, account_type: string, debit: int, credit: int}>
     */
    private function presentedLines(AccountingOpeningBalance $draft): array
    {
        return AccountingOpeningBalanceLine::query()
            ->join('accounting_accounts', 'accounting_accounts.id', '=', 'accounting_opening_balance_lines.accounting_account_id')
            ->where('accounting_opening_balance_lines.accounting_opening_balance_id', $draft->id)
            ->orderBy('accounting_accounts.code')
            ->get([
                'accounting_opening_balance_lines.id',
                'accounting_opening_balance_lines.accounting_account_id',
                'accounting_accounts.code as account_code',
                'accounting_accounts.name as account_name',
                'accounting_accounts.type as account_type',
                'accounting_opening_balance_lines.debit',
                'accounting_opening_balance_lines.credit',
            ])
            ->map(static fn (AccountingOpeningBalanceLine $line): array => [
                'id' => (int) $line->id,
                'account_id' => (int) $line->accounting_account_id,
                'account_code' => (string) $line->getAttribute('account_code'),
                'account_name' => (string) $line->getAttribute('account_name'),
                'account_type' => (string) $line->getAttribute('account_type'),
                'debit' => (int) $line->debit,
                'credit' => (int) $line->credit,
            ])
            ->all();
    }

    /**
     * The 3050 line that would make the entry balance: the difference on the
     * lighter side, added to any 3050 line already there. Null when the entry
     * already balances or the chart has no postable 3050.
     *
     * @param  array{debit: int, credit: int}|null  $equityLine
     * @return array{account_id: int, account_code: string, account_name: string, debit: int, credit: int}|null
     */
    private function suggestedEquityLine(int $difference, ?array $equityLine): ?array
    {
        if ($difference === 0) {
            return null;
        }

        $account = AccountingAccount::query()->where('code', OpeningBalancePoster::OPENING_EQUITY_CODE)->first();

        if ($account === null || ! $account->isPostable()) {
            return null;
        }

        // The 3050 line's net debit once the difference moves onto it.
        $net = (($equityLine['debit'] ?? 0) - ($equityLine['credit'] ?? 0)) - $difference;

        return [
            'account_id' => (int) $account->id,
            'account_code' => (string) $account->code,
            'account_name' => (string) $account->name,
            'debit' => max($net, 0),
            'credit' => max(-$net, 0),
        ];
    }

    /**
     * Why a finalise would be refused now, in words.
     *
     * @param  list<array{debit: int, credit: int}>  $lines
     * @return list<string>
     */
    private function blockingReasons(?AccountingJournal $journal, array $lines, ?string $asOf): array
    {
        if ($journal !== null) {
            return ['An opening balance has already been posted ('.$this->poster->journalLabel($journal).').'];
        }

        $reasons = [];
        $balance = OpeningBalancePoster::balanceReason($lines);

        if ($balance !== null) {
            $reasons[] = $balance;
        }

        $equity = AccountingAccount::query()->where('code', OpeningBalancePoster::OPENING_EQUITY_CODE)->exists();

        if (! $equity) {
            $reasons[] = 'The chart has no '.OpeningBalancePoster::OPENING_EQUITY_CODE.' Opening Balance Equity. Add it under 3000 Equity first.';
        }

        $unpostable = AccountingAccount::query()
            ->whereKey(array_column($lines, 'account_id'))
            ->where(static fn ($query) => $query->where('is_group', true)->orWhere('is_active', false))
            ->orderBy('code')
            ->get(['code', 'name', 'is_group', 'is_active']);

        foreach ($unpostable as $account) {
            $reasons[] = $account->is_group
                ? "{$account->code} {$account->name} is a heading. Move its balance to the accounts under it."
                : "{$account->code} {$account->name} is deactivated. Reactivate it or move its balance to another account.";
        }

        if ($asOf !== null) {
            $period = AccountingPeriod::query()->covering($asOf)->first();

            if ($period?->isClosed()) {
                $reasons[] = "{$period->name} is closed.";
            }

            $laterClosed = $this->poster->laterClosedReason($asOf);

            if ($laterClosed !== null) {
                $reasons[] = $laterClosed;
            }
        }

        return $reasons;
    }
}
