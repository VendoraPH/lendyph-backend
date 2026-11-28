<?php

namespace App\Http\Controllers\Api\Cic;

use App\Http\Requests\Cic\CicLoanOverrideRequest;
use App\Http\Requests\Cic\DeleteCicLoanOverrideRequest;
use App\Models\CicLoanOverride;
use App\Models\CicProductMapping;
use App\Services\Cic\CicAccess;
use App\Services\Cic\Settings\CicLoanOverrideService;
use App\Services\Cic\Settings\CicSettingsPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * A loan's own CIC classification over its product's mapping, with a reason
 * (prompt §3.3). Rows name the loan by its display number and never the member.
 */
class CicLoanOverrideController extends CicController
{
    #[OA\Get(
        path: '/api/cic/loan-overrides',
        summary: 'CIC loan overrides',
        description: 'Needs `cic:view` and `branches:view_all`. Paged (`per_page` up to 100, default 25), newest first; `loan_id` filters. Rows: {id, loan {id, display_number, status, loan_product {id, name}}, record_type, contract_type, contract_type_label, transaction_type, transaction_type_label, product_mapping (as in product mappings, or null), reason, created_by, created_at, updated_by, updated_at}. A null code keeps the product\'s.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The overrides'), new OA\Response(response: 403, description: 'No access, in words')],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorizeCic($request, CicAccess::VIEW);
        $filters = $request->validate([
            'loan_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], [], ['loan_id' => 'loan', 'per_page' => 'rows per page']);

        $page = CicLoanOverride::query()
            ->with(['loan.loanProduct', 'creator', 'updater'])
            ->when($filters['loan_id'] ?? null, fn ($query, $loanId) => $query->where('loan_id', $loanId))
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        $mappings = CicProductMapping::query()->with('updater')
            ->whereIn('loan_product_id', collect($page->items())->map(fn (CicLoanOverride $override) => $override->loan?->loan_product_id)->filter()->unique()->values())
            ->get()->keyBy('loan_product_id')->all();

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (CicLoanOverride $override) => CicSettingsPresenter::loanOverride($override, $mappings))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    #[OA\Post(
        path: '/api/cic/loan-overrides',
        summary: 'Override a loan\'s CIC classification',
        description: 'Needs `cic:prepare` and `branches:view_all`. {loan_id, record_type? CI|CN, contract_type?, transaction_type?, reason (up to 500)}; at least one code. The loan must have been released (released, ongoing, defaulted, completed or restructured). Codes are checked against the record type the loan reports as. One override per loan (409). Audited.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 201, description: 'The override'), new OA\Response(response: 403, description: 'No access, in words'), new OA\Response(response: 409, description: 'The loan already has one'), new OA\Response(response: 422, description: 'Invalid, in words')],
    )]
    public function store(CicLoanOverrideRequest $request, CicLoanOverrideService $service): JsonResponse
    {
        $override = $service->create($request->values(), $request->user());

        return response()->json(['success' => true, 'message' => 'CIC override saved.', 'data' => CicSettingsPresenter::loanOverride($override->refresh())], 201);
    }

    #[OA\Put(
        path: '/api/cic/loan-overrides/{override}',
        summary: 'Change a loan\'s CIC override',
        description: 'Needs `cic:prepare` and `branches:view_all`. {record_type?, contract_type?, transaction_type?, reason}: replaces all three codes (absent or null keeps the product\'s). Same checks as create. Audited.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'override', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The override'), new OA\Response(response: 403, description: 'No access, in words'), new OA\Response(response: 422, description: 'Invalid, in words')],
    )]
    public function update(CicLoanOverrideRequest $request, CicLoanOverride $override, CicLoanOverrideService $service): JsonResponse
    {
        $override = $service->update($override, $request->values(), $request->user());

        return response()->json(['success' => true, 'message' => 'CIC override saved.', 'data' => CicSettingsPresenter::loanOverride($override->refresh())]);
    }

    #[OA\Delete(
        path: '/api/cic/loan-overrides/{override}',
        summary: 'Remove a loan\'s CIC override',
        description: 'Needs `cic:prepare` and `branches:view_all`. {reason}. The loan reports by its product\'s mapping again. Audited.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'override', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Removed'), new OA\Response(response: 403, description: 'No access, in words'), new OA\Response(response: 422, description: 'No reason')],
    )]
    public function destroy(DeleteCicLoanOverrideRequest $request, CicLoanOverride $override, CicLoanOverrideService $service): JsonResponse
    {
        $service->delete($override, (string) $request->validated('reason'), $request->user());

        return response()->json(['success' => true, 'message' => 'CIC override removed; the loan follows its product\'s mapping again.', 'data' => null]);
    }
}
