<?php

namespace App\Http\Controllers\Api\Cic;

use App\Http\Requests\Cic\UpdateCicProductMappingsRequest;
use App\Services\Cic\CicAccess;
use App\Services\Cic\Settings\CicProductMappingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/** Loan products' default CIC classification (prompt §3.3). */
class CicProductMappingController extends CicController
{
    #[OA\Get(
        path: '/api/cic/product-mappings',
        summary: 'CIC product mappings',
        description: 'Needs `cic:view` and `branches:view_all`. {products: [{loan_product {id, name, status}, mapping ({record_type, contract_type, contract_type_label, transaction_type, transaction_type_label, purpose_code, purpose_label, updated_by, updated_at} or null)}], code_lists: {"CI:ContractTypeDomain", "CN:ContractTypeDomain", TransactionTypeDomain, CreditPurposeDomain: [{code, label}]}}.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Every product with its mapping'), new OA\Response(response: 403, description: 'No access, in words')],
    )]
    public function index(Request $request, CicProductMappingService $service): JsonResponse
    {
        $this->authorizeCic($request, CicAccess::VIEW);

        return response()->json(['success' => true, 'data' => $service->listing()]);
    }

    #[OA\Put(
        path: '/api/cic/product-mappings',
        summary: 'Save CIC product mappings',
        description: 'Needs `cic:settings` and `branches:view_all`. `mappings`: [{loan_product_id, record_type CI|CN, contract_type, transaction_type, purpose_code? (CI only)}]; products not listed are untouched. Codes are checked against CIC\'s lists for the record type (422 in words). Each change is audited in words.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Every product with its mapping'), new OA\Response(response: 403, description: 'No access, in words'), new OA\Response(response: 409, description: 'A simultaneous save'), new OA\Response(response: 422, description: 'Invalid, in words')],
    )]
    public function update(UpdateCicProductMappingsRequest $request, CicProductMappingService $service): JsonResponse
    {
        $service->save(array_values($request->validated('mappings')), $request->user());

        return response()->json(['success' => true, 'message' => 'CIC product mappings saved.', 'data' => $service->listing()]);
    }

    #[OA\Delete(
        path: '/api/cic/product-mappings/{loan_product_id}',
        summary: 'Clear a CIC product mapping',
        description: 'Needs `cic:settings` and `branches:view_all`. Removes the product\'s mapping (404 in words when it has none); its loans then show the unmapped-product error until it is mapped again or a loan gets its own classification. Audited in words. Answers with the GET shape.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'loan_product_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Every product with its mapping'), new OA\Response(response: 403, description: 'No access, in words'), new OA\Response(response: 404, description: 'No mapping')],
    )]
    public function destroy(Request $request, int $loanProductId, CicProductMappingService $service): JsonResponse
    {
        $user = $this->authorizeCic($request, CicAccess::SETTINGS);
        $service->clear($loanProductId, $user);

        return response()->json(['success' => true, 'message' => 'CIC product mapping cleared.', 'data' => $service->listing()]);
    }
}
