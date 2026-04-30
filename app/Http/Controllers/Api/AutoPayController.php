<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AutoPay\PreviewAutoPayRequest;
use App\Http\Requests\AutoPay\ProcessAutoPayRequest;
use App\Services\AutoPayService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class AutoPayController extends Controller
{
    public function __construct(private AutoPayService $autoPayService) {}

    #[OA\Get(
        path: '/api/auto-pay/preview',
        summary: 'Preview auto-pay deductions for a date window',
        description: 'Returns summary totals plus a list of partial-payment rows so staff can decide which to include in the next process call.',
        tags: ['Auto-Pay'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'product_ids[]', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'integer'))),
            new OA\Parameter(name: 'date_from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'), example: '2026-04-01'),
            new OA\Parameter(name: 'date_to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'), example: '2026-04-30'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Preview totals and partial rows'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing auto_pay:view permission'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function preview(PreviewAutoPayRequest $request): JsonResponse
    {
        $data = $this->autoPayService->preview(
            productIds: $request->input('product_ids', []) ?? [],
            dateFrom: $request->input('date_from'),
            dateTo: $request->input('date_to'),
        );

        return response()->json(['data' => $data]);
    }

    #[OA\Post(
        path: '/api/auto-pay/process',
        summary: 'Execute auto-pay deductions for the eligible loan set',
        description: 'Creates one repayment per loan with method=auto_pay; skipped/failed totals report per-loan outcomes.',
        tags: ['Auto-Pay'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['date_from', 'date_to'],
                properties: [
                    new OA\Property(property: 'product_ids', type: 'array', items: new OA\Items(type: 'integer'), example: [1, 2]),
                    new OA\Property(property: 'date_from', type: 'string', format: 'date', example: '2026-04-01'),
                    new OA\Property(property: 'date_to', type: 'string', format: 'date', example: '2026-04-30'),
                    new OA\Property(property: 'include_schedule_ids', type: 'array', items: new OA\Items(type: 'integer'), example: [156, 301]),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Auto-pay run completed'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing auto_pay:process permission'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function process(ProcessAutoPayRequest $request): JsonResponse
    {
        $result = $this->autoPayService->process(
            productIds: $request->input('product_ids', []) ?? [],
            dateFrom: $request->input('date_from'),
            dateTo: $request->input('date_to'),
            includeScheduleIds: $request->input('include_schedule_ids', []) ?? [],
            user: $request->user(),
        );

        return response()->json(['data' => $result], 201);
    }
}
