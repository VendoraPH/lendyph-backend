<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LedgerEntryResource;
use App\Models\AccountingAccount;
use App\Services\Accounting\AccountingDashboardBuilder;
use App\Services\Accounting\GeneralLedgerBuilder;
use App\Services\Accounting\ReceivableAgingBuilder;
use App\Services\Accounting\TrialBalanceBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * The reports built on top of the journals: the general ledger, the trial
 * balance, and the accounting dashboard.
 *
 * The balance sheet and the income statement are deliberately NOT here. Both
 * are pure regroupings of the trial balance and are built client-side from the
 * rows `GET /trial-balance` already returns, so there is exactly one place a
 * balance-sheet figure can come from. Adding endpoints for them would create a
 * second source of truth for numbers that have to agree exactly.
 *
 * Those three read `status IN ('posted','reversed')`. A reversed entry is a
 * posted historical fact whose mirror nets it to zero; counting only `posted`
 * would include every reversal while excluding what it reverses.
 *
 * `receivableAging()` is the odd one out and is worth flagging: it reads the
 * LENDING tables — `amortization_schedules` and `loans` — not the journals. It
 * lives here because it answers on an accounting screen and is gated on
 * `accounting:view` like its neighbours, but it is a portfolio measure rather
 * than a ledger one, and it will not agree with Loans Receivable on the trial
 * balance until the automatic posting engine lands. Note the unit change that
 * comes with it: lending money is `decimal:2` PESOS, accounting money is
 * integer CENTAVOS, and the conversion is made once inside
 * {@see ReceivableAgingBuilder}.
 */
class AccountingReportController extends Controller
{
    public function __construct(
        private TrialBalanceBuilder $trialBalance,
        private GeneralLedgerBuilder $generalLedger,
        private AccountingDashboardBuilder $dashboard,
        private ReceivableAgingBuilder $receivableAging,
    ) {}

    #[OA\Get(
        path: '/api/accounting/general-ledger',
        summary: "One account's history, with running balances",
        description: "Returns the raw Laravel paginator envelope ({data, links, meta}). `account_id` is REQUIRED: a running balance across several accounts is meaningless, since it accumulates in one account's normal direction. Each row's `running_balance` carries everything before it — including movements before `from`, which are folded into an opening balance — so row 1 of page 2 continues from row 100 of page 1 rather than restarting at zero.",
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'account_id', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated ledger entries'),
            new OA\Response(response: 403, description: 'Missing accounting:view'),
            new OA\Response(response: 422, description: 'Missing or unknown account_id'),
        ],
    )]
    public function generalLedger(): AnonymousResourceCollection
    {
        $this->authorize('accounting:view');

        $filters = request()->validate([
            'account_id' => ['required', 'integer', 'exists:accounting_accounts,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $account = AccountingAccount::query()->findOrFail($filters['account_id']);

        // A heading's balance is the sum of its subtree, so it has no ledger of
        // its own — and since nothing can post to one, its ledger would always
        // be empty. An empty page is indistinguishable from "no activity", so
        // this is refused rather than answered with nothing.
        if ($account->is_group) {
            throw ValidationException::withMessages([
                'account_id' => [
                    "{$account->code} {$account->name} is a heading and has no ledger of its own. "
                    .'Open one of the accounts beneath it.',
                ],
            ]);
        }

        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        $entries = $this->generalLedger->build(
            $account,
            $filters['from'] ?? null,
            $filters['to'] ?? null,
            $filters['branch_id'] ?? null,
            $page,
            $perPage,
        );

        return LedgerEntryResource::collection($entries);
    }

    #[OA\Get(
        path: '/api/accounting/trial-balance',
        summary: 'Trial balance as of a date',
        description: 'Every account that moved, netted, placed in the column it actually landed on, with both totals and their difference. Answers `{data: TrialBalance}`. An account that swung against its normal side — an overdrawn cash account — appears in the OTHER column as a positive figure rather than as a negative in its own, because a trial balance has no negatives and hiding the swing would make the columns balance while concealing it. The balance sheet and income statement are regroupings of these rows and are built client-side.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'as_of', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'), description: 'Defaults to today.'),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The trial balance'),
            new OA\Response(response: 403, description: 'Missing accounting:view'),
        ],
    )]
    public function trialBalance(): JsonResponse
    {
        $this->authorize('accounting:view');

        $filters = request()->validate([
            'as_of' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'data' => $this->trialBalance->build(
                $this->asOf($filters),
                $filters['branch_id'] ?? null,
            ),
        ]);
    }

    #[OA\Get(
        path: '/api/accounting/dashboard',
        summary: 'Accounting dashboard figures',
        description: 'Answers `{data: AccountingDashboard}`. Every figure is derived from the trial balance rather than from its own aggregate, so the cards cannot disagree with the balance sheet shown beside them. `open_period` is always null: this application has no accounting-period table yet, and naming the current month would be an invention. `unposted_journals` counts draft headers and deliberately ignores `as_of` — it is a work queue, not a position.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'as_of', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'), description: 'Defaults to today.'),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dashboard figures'),
            new OA\Response(response: 403, description: 'Missing accounting:view'),
        ],
    )]
    public function dashboard(): JsonResponse
    {
        $this->authorize('accounting:view');

        $filters = request()->validate([
            'as_of' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'data' => $this->dashboard->build(
                $this->asOf($filters),
                $filters['branch_id'] ?? null,
            ),
        ]);
    }

    #[OA\Get(
        path: '/api/accounting/loans/aging',
        summary: 'Receivable aging by days past due',
        description: "Outstanding loan receivables split into the buckets a provisioning policy needs. Answers `{data: Aging}`. All six buckets are always present, in report order, even when empty, so the table has a stable shape. Amounts are integer CENTAVOS — this is the one accounting report that reads the LENDING tables, where money is `decimal:2` pesos, and the conversion happens once inside the builder. `count` is outstanding INSTALMENTS, not loans, because the screen's footer sums the column. Days past due are measured from the bare due date, deliberately ignoring each product's grace period, exactly as `ReportService::agingReport()` does.",
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'as_of', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'), description: 'Defaults to today.'),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The aged receivables report'),
            new OA\Response(response: 403, description: 'Missing accounting:view'),
        ],
    )]
    public function receivableAging(): JsonResponse
    {
        $this->authorize('accounting:view');

        $filters = request()->validate([
            'as_of' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'data' => $this->receivableAging->build(
                $this->asOf($filters),
                $filters['branch_id'] ?? null,
            ),
        ]);
    }

    /**
     * The report date, defaulting to today in the application's timezone.
     *
     * `now()->toDateString()`, never a date sliced out of an ISO instant: the
     * app runs at UTC+8, so a UTC-derived string reads as YESTERDAY for the
     * first eight hours of every Manila day — and a trial balance dated
     * yesterday silently omits everything posted this morning.
     *
     * @param  array<string, mixed>  $filters
     */
    private function asOf(array $filters): string
    {
        $asOf = $filters['as_of'] ?? null;

        return $asOf === null || trim((string) $asOf) === ''
            ? now()->toDateString()
            : (string) $asOf;
    }
}
