<?php

namespace App\Http\Controllers\Api\Cic;

use App\Http\Requests\Cic\UpdateCicCodeMappingsRequest;
use App\Services\Cic\CicAccess;
use App\Services\Cic\Settings\CicCodeMappingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/** Collateral types and valid-ID kinds mapped to CIC codes (prompt §3.2). */
class CicCodeMappingController extends CicController
{
    #[OA\Get(
        path: '/api/cic/code-mappings',
        summary: 'CIC code mappings',
        description: 'Needs `cic:view` and `branches:view_all`. {collateral_type: [{lendy_value (collateral type id), name, cic_domain, cic_code, cic_label, suggested_domain, suggested_code, suggested_label}], id_kind: [... plus standard], code_lists: {GuaranteesDomain, IDTypeDomain, IdentificationTypeDomain: [{code, label}]}}. A suggestion is shown only while a value has no saved code, and is never saved by itself.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The mappings'), new OA\Response(response: 403, description: 'No access, in words')],
    )]
    public function index(Request $request, CicCodeMappingService $service): JsonResponse
    {
        $this->authorizeCic($request, CicAccess::VIEW);

        return response()->json(['success' => true, 'data' => $service->listing()]);
    }

    #[OA\Put(
        path: '/api/cic/code-mappings',
        summary: 'Save CIC code mappings',
        description: 'Needs `cic:settings` and `branches:view_all`. `mappings`: [{domain collateral_type|id_kind, lendy_value, cic_domain (id_kind: IDTypeDomain or IdentificationTypeDomain; collateral_type: GuaranteesDomain or omitted), cic_code (null removes the mapping)}]. Values not listed are untouched. Audited in words.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The mappings'), new OA\Response(response: 403, description: 'No access, in words'), new OA\Response(response: 409, description: 'A simultaneous save'), new OA\Response(response: 422, description: 'Invalid, in words')],
    )]
    public function update(UpdateCicCodeMappingsRequest $request, CicCodeMappingService $service): JsonResponse
    {
        $service->save(array_values($request->validated('mappings')), $request->user());

        return response()->json(['success' => true, 'message' => 'CIC code mappings saved.', 'data' => $service->listing()]);
    }
}
