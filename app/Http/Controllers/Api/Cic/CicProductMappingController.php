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
}
