<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StoreFundTransferRequest;
use App\Http\Resources\JournalEntryResource;
use App\Services\Accounting\FundTransferRecorder;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Cash and bank: moving money between the organisation's own accounts.
 *
 * `GET /accounting/cash-accounts` is NOT here. It is the chart of accounts
 * filtered to the rows carrying a `cash_kind`, with the balances the accounts
 * endpoint already attaches, and it belongs with that controller rather than
 * with this one.
 */
class AccountingCashAccountController extends Controller
{
    public function __construct(private FundTransferRecorder $recorder) {}

    #[OA\Post(
        path: '/api/accounting/cash-accounts/transfer',
        summary: 'Transfer between money accounts',
        description: 'Posts `fund_transfer`: Dr the destination, Cr the source, and — when there is one — Dr a charge expense account, with the SOURCE giving up amount + charge. Emphatically not income; a sweep recorded as revenue would inflate the income statement by the whole amount moved, every time. Returns the posted JournalEntry. Amounts are INTEGER CENTAVOS.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['date', 'from_account_id', 'to_account_id', 'amount', 'description'],
                properties: [
                    new OA\Property(property: 'date', type: 'string', format: 'date'),
                    new OA\Property(property: 'from_account_id', type: 'integer'),
                    new OA\Property(property: 'to_account_id', type: 'integer'),
                    new OA\Property(property: 'amount', type: 'integer', description: 'Centavos. What ARRIVES at the destination.'),
                    new OA\Property(property: 'charge', type: 'integer', nullable: true, description: 'Centavos, taken on top and out of the source.'),
                    new OA\Property(property: 'charge_account_id', type: 'integer', nullable: true, description: 'Where the charge lands. Defaults to the chart\'s Bank/GCash Charges account; refused rather than dropped if neither resolves.'),
                    new OA\Property(property: 'description', type: 'string', maxLength: 500),
                    new OA\Property(property: 'branch_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'reference', type: 'string', nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'The posted journal entry'),
            new OA\Response(response: 403, description: 'Missing cash_accounts:transfer'),
            new OA\Response(response: 422, description: 'Not a money account, same account twice, a closed period, or no account for the charge'),
        ],
    )]
    public function transfer(StoreFundTransferRequest $request): JsonResponse
    {
        $journal = $this->recorder->record($request->validated(), $request->user()?->id);

        // The same relations and the same resource the Journals screen reads,
        // because `accountingService.transfer` is typed to return a
        // `JournalEntry` and the Cash & Bank screen may render it.
        $journal->load([
            'lines.account:id,code,name',
            'branch:id,name',
            'creator:id,first_name,last_name',
            'poster:id,first_name,last_name',
        ]);

        return (new JournalEntryResource($journal))->response()->setStatusCode(201);
    }
}
