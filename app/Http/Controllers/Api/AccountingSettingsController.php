<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\UpdateAccountMappingRequest;
use App\Models\AccountingAccountMapping;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * Accounting settings — today, the posting defaults.
 *
 * The posting engine never names an account: it asks for a role and this
 * mapping answers with an id. That is what lets an organisation re-point
 * "interest income" at its own account without anyone editing a posting rule.
 *
 * Both endpoints answer `{"data": {role: account_id}}`, the envelope
 * `api.get`/`api.put` unwrap on the frontend. Roles with no row are absent
 * rather than null: an unseeded chart has no mapping at all, and "unset" is a
 * different statement from "points at nothing".
 */
class AccountingSettingsController extends Controller
{
    #[OA\Get(
        path: '/api/accounting/settings/account-mapping',
        summary: 'Show the posting account mapping',
        description: 'Which account each posting role resolves to, as `{role: account_id}`. Empty until the chart of accounts has been seeded.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The account mapping'),
            new OA\Response(response: 403, description: 'Missing accounting:settings'),
        ],
    )]
    public function showAccountMapping(): JsonResponse
    {
        $this->authorize('accounting:settings');

        return response()->json(['data' => (object) AccountingAccountMapping::resolved()]);
    }

    #[OA\Put(
        path: '/api/accounting/settings/account-mapping',
        summary: 'Re-point one or more posting roles',
        description: 'Partial: roles that are not sent keep the account they had. Every account named must be postable — a group heading is refused (422) because its balance is the total of the accounts beneath it, and an inactive account is refused because the next automatic entry resolving through it would fail.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'cash', type: 'integer', example: 2),
                    new OA\Property(property: 'interest_income', type: 'integer', example: 35),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'The updated account mapping'),
            new OA\Response(response: 403, description: 'Missing accounting:settings'),
            new OA\Response(response: 422, description: 'Unknown, inactive or group account'),
        ],
    )]
    public function updateAccountMapping(UpdateAccountMappingRequest $request): JsonResponse
    {
        $submitted = $request->validated();

        DB::transaction(function () use ($submitted): void {
            foreach ($submitted as $role => $accountId) {
                AccountingAccountMapping::updateOrCreate(
                    ['role' => $role],
                    ['accounting_account_id' => (int) $accountId],
                );
            }
        });

        return response()->json(['data' => (object) AccountingAccountMapping::resolved()]);
    }
}
