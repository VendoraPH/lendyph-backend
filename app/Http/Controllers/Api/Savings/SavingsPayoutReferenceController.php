<?php

namespace App\Http\Controllers\Api\Savings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Savings\TimeDeposit\RecordTimeDepositPayoutReferenceRequest;
use App\Http\Requests\Savings\Transaction\ListPayoutsMissingReferenceRequest;
use App\Http\Requests\Savings\Transaction\RecordPayoutReferenceRequest;
use App\Models\SavingsTransaction;
use App\Services\BranchAccess;
use App\Services\Savings\Api\Presenter;
use App\Services\Savings\PayoutReferences;
use App\Services\Savings\PayoutsMissingReference;
use App\Services\Savings\TimeDeposits\TimeDepositPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * A payout's reference, recorded after the transfer, and the payouts still
 * missing one (finish run round 6). Recording moves no money and posts no
 * journal; each is audited.
 */
class SavingsPayoutReferenceController extends Controller
{
    public function __construct(private readonly PayoutReferences $references) {}

    #[OA\Post(
        path: '/api/savings/transactions/{transaction}/reference',
        summary: 'Record a payout\'s reference after the transfer',
        description: 'A posted withdrawal (the Member Portal\'s included) or closing payout by bank transfer, GCash or Maya whose reference is blank, neither a reversal nor reversed. Once only. Trimmed, at most 64 characters, and not already on record (or held by a payout waiting for approval) for the same method (422 in words). A withdrawal needs savings:transact and a closing payout savings:close, the permissions that post them. Moves no money and posts no journal; audited (`savings_payout_reference_recorded`).',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'transaction', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reference'], properties: [
            new OA\Property(property: 'reference', type: 'string', maxLength: 64),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The transaction, with its reference'),
            new OA\Response(response: 403, description: 'Missing the permission that posts this payout'),
            new OA\Response(response: 404, description: 'No such transaction in the caller\'s branches'),
            new OA\Response(response: 409, description: 'Someone recorded its reference a moment ago'),
            new OA\Response(response: 422, description: 'Not a non-cash payout, already has a reference, reversed, or the reference is blank, too long or already used'),
        ],
    )]
    public function transaction(RecordPayoutReferenceRequest $request, Presenter $presenter, int $transaction): JsonResponse
    {
        /** @var SavingsTransaction $row */
        $row = $request->record();
        $reference = (string) $request->validated('reference');
        $user = $request->user();

        $recorded = DB::transaction(fn (): SavingsTransaction => $this->references->recordOnTransaction($row, $reference, $user));

        return response()->json(['success' => true, 'data' => $presenter->transaction($recorded)]);
    }

    #[OA\Post(
        path: '/api/savings/time-deposits/{timeDeposit}/payout-reference',
        summary: 'Record a time deposit payout\'s reference after the transfer',
        description: 'A placement paid out at maturity or on pre-termination by bank transfer, GCash or Maya whose `payout_reference` is blank. Once only. Trimmed, at most 100 characters, and not already on record (or held) for the same method (422 in words). A maturity payout needs savings:transact and a pre-termination savings:close, the permissions that post them. Moves no money and posts no journal; audited (`savings_payout_reference_recorded`).',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'timeDeposit', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reference'], properties: [
            new OA\Property(property: 'reference', type: 'string', maxLength: 100),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The time deposit, with its payout reference'),
            new OA\Response(response: 403, description: 'Missing the permission that posts this payout'),
            new OA\Response(response: 404, description: 'No such placement in the caller\'s branches'),
            new OA\Response(response: 409, description: 'Someone recorded its payout reference a moment ago'),
            new OA\Response(response: 422, description: 'Not paid out by bank transfer, GCash or Maya, already has a reference, or the reference is blank, too long or already used'),
        ],
    )]
    public function timeDeposit(RecordTimeDepositPayoutReferenceRequest $request, TimeDepositPresenter $presenter, int $timeDeposit): JsonResponse
    {
        $deposit = $request->deposit();
        $reference = (string) $request->validated('reference');
        $user = $request->user();

        $recorded = DB::transaction(fn () => $this->references->recordOnTimeDeposit($deposit, $reference, $user));

        return response()->json(['success' => true, 'data' => $presenter->present($recorded)]);
    }

    #[OA\Get(
        path: '/api/savings/payouts/missing-reference',
        summary: 'Payouts missing a reference',
        description: 'Withdrawals (the Member Portal\'s included), closing payouts and time deposit payouts by bank transfer, GCash or Maya with no reference, not reversed, in the caller\'s branches, newest first, 25 a page. Rows: {key, source (transaction|time_deposit), id, kind, kind_label, date, member {id, name, code}, account_id, account_number, time_deposit_id, certificate_number, reference (SVT- or TD-), method, method_label, amount, posted_by {id, name}, can_record_reference}. `meta.total` is the count; the Savings dashboard sends the same count as `kpis.payouts_missing_reference`.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'method', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: SavingsTransaction::PAYOUT_REFERENCE_METHODS)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'One page of payouts'),
            new OA\Response(response: 403, description: 'Missing savings:view, or a branch outside the caller\'s'),
            new OA\Response(response: 422, description: 'A bad filter'),
        ],
    )]
    public function missing(ListPayoutsMissingReferenceRequest $request, PayoutsMissingReference $payouts): JsonResponse
    {
        $filters = $request->validated();
        $user = $request->user();
        $access = app(BranchAccess::class);
        $access->authorizeBranchFilter($user, $filters['branch_id'] ?? null);

        ['page' => $page, 'rows' => $rows] = $payouts->page($user, $access->for($user), $filters, (int) ($filters['page'] ?? 1));

        return Presenter::page($page, $rows);
    }
}
