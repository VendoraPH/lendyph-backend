<?php

namespace App\Http\Controllers\Api\Savings;

use App\Http\Controllers\Controller;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Services\LoanListTotals;
use App\Services\LoanService;
use App\Services\Savings\Api\AccountScope;
use App\Services\Savings\ProductRules;
use App\Services\Savings\SavingsMath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * The member check on the New Account screen (gap round S1, the Savings
 * task's TASK 4): the member's standing, every figure from the server.
 *
 * - Needs `savings:view`, and the member in the caller's branches (another
 *   branch's member answers 404, the same as a missing one).
 * - Loan figures need `loans:view` and cover only the loans the caller can
 *   see; share capital needs `share_capital:view`. Without them each reads
 *   null, never refused.
 * - The savings accounts are those in the caller's branches.
 * - The eligible products are the active regular products the member may
 *   open (ProductRules: member type and branch) and holds no open account on.
 */
class SavingsOpeningCheckController extends Controller
{
    public function __construct(
        private readonly AccountScope $scope,
        private readonly ProductRules $rules,
    ) {}

    #[OA\Get(
        path: '/api/savings/opening-check',
        summary: 'Member check before opening a savings account',
        description: 'The member\'s status, branch, membership date and type; collectible loans (count and `total_outstanding`, null without loans:view); share capital balance (null without share_capital:view); the member\'s savings accounts (product, number, status, balance); and the products the member is eligible to open. Money as two-decimal strings.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'borrower_id', in: 'query', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The member check'),
            new OA\Response(response: 403, description: 'Missing savings:view'),
            new OA\Response(response: 404, description: 'No such member in the caller\'s branches'),
            new OA\Response(response: 422, description: 'No member chosen'),
        ],
    )]
    public function __invoke(Request $request, LoanListTotals $totals): JsonResponse
    {
        $this->authorize('savings:view');
        $user = $request->user();

        $borrowerId = (int) $request->validate(
            ['borrower_id' => ['required', 'integer', 'min:1']],
            ['borrower_id.required' => 'Choose the member.', 'borrower_id.integer' => 'Choose the member from the list.', 'borrower_id.min' => 'Choose the member from the list.'],
        )['borrower_id'];

        $member = Borrower::query()->visibleTo($user)->with('branch:id,name')->find($borrowerId) ?? abort(404, 'Member not found.');

        $loans = ['collectible_count' => null, 'total_outstanding' => null];

        if ($user->can('loans:view')) {
            $collectible = fn () => Loan::query()->visibleTo($user)
                ->where('loans.borrower_id', $member->id)
                ->whereIn('loans.status', Loan::COLLECTIBLE_STATUSES);

            $loans = [
                'collectible_count' => $collectible()->count(),
                'total_outstanding' => SavingsMath::pesos(LoanService::toCentavos($totals->for($collectible())['total_outstanding'])),
            ];
        }

        $shareCapitalBalance = $user->can('share_capital:view')
            ? SavingsMath::pesos(SavingsMath::signedCentavos((string) DB::table('share_capital_ledger')
                ->where('borrower_id', $member->id)
                ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) AS balance')
                ->value('balance')))
            : null;

        $accounts = $this->scope->accounts($user)
            ->where('borrower_id', $member->id)
            ->with('product:id,code,name,kind')
            ->orderBy('id')
            ->get();

        $openProductIds = SavingsAccount::query()->where('borrower_id', $member->id)->whereNotNull('open_key')->pluck('product_id')->all();

        $eligible = ProductRules::constrainEligible(SavingsProduct::query(), $member)
            ->where('kind', SavingsProduct::KIND_REGULAR)
            ->where('is_active', true)
            ->whereNotIn('id', $openProductIds)
            ->orderBy('code')
            ->get();

        return response()->json(['success' => true, 'data' => [
            'member' => [
                'id' => $member->id,
                'name' => $member->full_name,
                'borrower_code' => $member->borrower_code,
                'membership_number' => $member->membership_number,
                'status' => $member->status,
                'branch_id' => $member->branch_id,
                'branch_name' => $member->branch?->name,
                'membership_date' => $member->membership_date?->toDateString(),
                'member_type' => $member->member_type,
            ],
            'loans' => $loans,
            'share_capital_balance' => $shareCapitalBalance,
            'savings_accounts' => $accounts->map(fn (SavingsAccount $a): array => [
                'id' => $a->id,
                'account_number' => $a->account_number,
                'product_id' => $a->product_id,
                'product_name' => $a->product?->name,
                'product_kind' => $a->product?->kind,
                'status' => $a->status,
                'balance' => SavingsMath::pesos(SavingsMath::signedCentavos($a->ledger_balance)),
            ])->values()->all(),
            'eligible_products' => $eligible
                ->filter(fn (SavingsProduct $p): bool => $this->rules->isEligible($member, $p))
                ->map(fn (SavingsProduct $p): array => [
                    'id' => $p->id,
                    'code' => $p->code,
                    'name' => $p->name,
                    'minimum_opening_balance' => $p->minimum_opening_balance,
                    'maintaining_balance' => $p->maintaining_balance,
                ])->values()->all(),
        ]]);
    }
}
