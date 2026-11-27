<?php

namespace App\Http\Controllers\Api\Savings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Savings\Transfer\ShareCapitalTransferRequest;
use App\Models\ShareCapitalLedger;
use App\Services\Savings\Api\Presenter;
use App\Services\Savings\IdempotentWrite;
use App\Services\Savings\SavingsMath;
use App\Services\Savings\ShareCapitalTransfer;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Savings to share capital (gap round S3). Needs an `Idempotency-Key`
 * header; another branch's account answers 404.
 */
class SavingsShareCapitalTransferController extends Controller
{
    public function __construct(
        private readonly Presenter $presenter,
        private readonly IdempotentWrite $idempotent,
    ) {}

    #[OA\Post(
        path: '/api/savings/accounts/{account}/share-capital-transfers',
        summary: 'Transfer savings to the member\'s share capital',
        description: 'Needs `savings:post` and `share_capital:create`. An active member only, from an active account in the member\'s own branch, within the product\'s transfer rules and the available balance. Up to tier 1 it posts (201): a `share_capital_transfer` savings row, a share capital credit linked to it (method `savings_transfer`) and one journal, Dr 2050 / Cr 3060. Above it, it waits for approval and reserves the amount (202, `{approval}`). Never reversed: a mistake is corrected with a share capital entry and a savings deposit. `share_capital_balance` is null without `share_capital:view`.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'account', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['amount'], properties: [
            new OA\Property(property: 'amount', type: 'string', example: '1000.00'),
            new OA\Property(property: 'remarks', type: 'string', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 201, description: '`{transaction, share_capital_entry, share_capital_balance}`'),
            new OA\Response(response: 202, description: '`{approval}`: sent for approval'),
            new OA\Response(response: 403, description: 'Missing savings:post or share_capital:create'),
            new OA\Response(response: 404, description: 'No such account in the caller\'s branches'),
            new OA\Response(response: 409, description: 'Another write got there first'),
            new OA\Response(response: 422, description: 'Not an active member, another branch, an account that can\'t pay out, a product rule, or above the available balance'),
        ],
    )]
    public function store(ShareCapitalTransferRequest $request, ShareCapitalTransfer $transfer, int $account): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        return $this->idempotent->run($request, function () use ($transfer, $account, $data, $user): JsonResponse {
            $result = $transfer->request($account, $data, $user);

            if ($result['approval'] !== null) {
                return response()->json(['success' => true, 'data' => ['approval' => $this->presenter->approval($result['approval'])]], 202);
            }

            $entry = $result['entry'];
            $balance = $user->can('share_capital:view')
                ? SavingsMath::pesos(SavingsMath::signedCentavos((string) (ShareCapitalLedger::balancesQuery()->where('borrower_id', $entry->borrower_id)->first()?->balance ?? '0')))
                : null;

            return response()->json(['success' => true, 'data' => [
                'transaction' => $this->presenter->transaction($result['transaction']),
                'share_capital_entry' => [
                    'id' => $entry->id,
                    'reference' => $entry->reference,
                    'date' => $entry->date?->toDateString(),
                    'credit' => SavingsMath::pesos(SavingsMath::centavos($entry->credit)),
                    'method' => $entry->method,
                    'reference_number' => $entry->reference_number,
                ],
                'share_capital_balance' => $balance,
            ]], 201);
        });
    }
}
