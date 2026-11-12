<?php

namespace App\Http\Controllers\Api\Savings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Savings\SavingsProductRuleFields;
use App\Http\Requests\Savings\StoreSavingsProductRateRequest;
use App\Http\Requests\Savings\StoreSavingsProductRequest;
use App\Http\Requests\Savings\UpdateSavingsProductRequest;
use App\Http\Resources\Savings\SavingsProductRateResource;
use App\Http\Resources\Savings\SavingsProductResource;
use App\Models\SavingsProduct;
use App\Models\SavingsProductRate;
use App\Services\BranchAccess;
use App\Services\Savings\SavingsBooks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * Savings products and their append-only rate history (design §2). Reading
 * needs `savings:view`; every write needs `savings:settings` and
 * `branches:view_all`, since a product applies to every branch. A product is
 * created off unless turned on at once, and is turned on only when the books
 * are ready (no chart, or an unmapped role it posts to: 422). It can be
 * deleted only while it holds no account or time deposit.
 */
class SavingsProductController extends Controller
{
    public function __construct(private readonly SavingsBooks $books) {}

    #[OA\Get(
        path: '/api/savings/products',
        summary: 'Savings products',
        description: 'In code order. Each carries `current_rates` (the rate in force today per term), `account_count` (accounts and time deposits on it) and `can_delete`. Money as two-decimal strings, rates as four-decimal strings. Filters: `kind`, `is_active`.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'kind', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: SavingsProduct::KINDS)),
            new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The products'),
            new OA\Response(response: 403, description: 'Missing savings:view'),
            new OA\Response(response: 422, description: 'An unknown filter value'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('savings:view');

        $filters = $request->validate([
            'kind' => ['sometimes', Rule::in(SavingsProduct::KINDS)],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'kind.in' => 'A savings product is regular savings or a time deposit.',
            'is_active.boolean' => 'Filter by on or off.',
        ]);

        $products = $this->withCounts(SavingsProduct::query())
            ->when(isset($filters['kind']), fn ($q) => $q->where('kind', $filters['kind']))
            ->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->orderBy('code')
            ->get();

        return response()->json(['success' => true, 'data' => SavingsProductResource::collection($products)->resolve($request)]);
    }

    #[OA\Post(
        path: '/api/savings/products',
        summary: 'Create a savings product',
        description: '`annual_interest_rate` becomes the first rate from today (one per offered term on a time deposit product). Off by default; `is_active: true` is refused (422) while there is no chart of accounts or a posting role the product needs has no account.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['code', 'name', 'kind', 'annual_interest_rate'], properties: [
            new OA\Property(property: 'code', type: 'string', example: 'RS-01'),
            new OA\Property(property: 'name', type: 'string', example: 'Regular Savings'),
            new OA\Property(property: 'kind', type: 'string', enum: SavingsProduct::KINDS),
            new OA\Property(property: 'interest_crediting', type: 'string', enum: SavingsProduct::CREDITING),
            new OA\Property(property: 'minimum_opening_balance', type: 'string', example: '500.00'),
            new OA\Property(property: 'maintaining_balance', type: 'string', example: '100.00'),
            new OA\Property(property: 'minimum_adb_for_interest', type: 'string', example: '1000.00'),
            new OA\Property(property: 'term_options_days', type: 'array', items: new OA\Items(type: 'integer'), example: [90, 180, 360]),
            new OA\Property(property: 'pretermination_product_id', type: 'integer', nullable: true),
            new OA\Property(property: 'annual_interest_rate', type: 'string', example: '1.2500'),
            new OA\Property(property: 'is_active', type: 'boolean'),
            new OA\Property(property: 'max_balance', type: 'string', nullable: true, example: '500000.00', description: 'Regular products only; null = no limit (also min_deposit, max_deposit, min_withdrawal, max_withdrawal)'),
            new OA\Property(property: 'free_withdrawals_per_month', type: 'integer', nullable: true, description: 'Null = unlimited'),
            new OA\Property(property: 'excess_withdrawal_fee', type: 'string', nullable: true, example: '25.00', description: 'Charged beyond the free withdrawals; null = refuse them'),
            new OA\Property(property: 'allow_deposits', type: 'boolean', description: 'Also allow_withdrawals and allow_transfers; default true'),
            new OA\Property(property: 'eligible_member_types', type: 'array', nullable: true, items: new OA\Items(type: 'string', enum: ['regular', 'associate']), description: 'Null = every member type'),
            new OA\Property(property: 'eligible_branch_ids', type: 'array', nullable: true, items: new OA\Items(type: 'integer'), description: 'Null = every branch'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The product'),
            new OA\Response(response: 403, description: 'Missing savings:settings or branches:view_all (company-wide)'),
            new OA\Response(response: 422, description: 'Invalid fields, a code in use, or turned on before the books are ready'),
        ],
    )]
    public function store(StoreSavingsProductRequest $request): JsonResponse
    {
        $data = SavingsProductRuleFields::normalize($request->validated());
        $isActive = (bool) ($data['is_active'] ?? false);

        if ($isActive) {
            $this->books->assertCanActivate($data['kind'], ($data['excess_withdrawal_fee'] ?? null) !== null);
        }

        $userId = $request->user()->id;

        $product = DB::transaction(function () use ($data, $isActive, $userId): SavingsProduct {
            $product = SavingsProduct::query()->create([
                ...collect($data)->except(['annual_interest_rate', 'is_active'])->all(),
                'term_options_days' => isset($data['term_options_days']) ? array_values(array_map('intval', $data['term_options_days'])) : null,
                'is_active' => $isActive,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $terms = $product->isTimeDeposit() ? $product->term_options_days : [null];

            foreach ($terms as $term) {
                SavingsProductRate::query()->create([
                    'product_id' => $product->id,
                    'term_days' => $term,
                    'annual_rate' => (string) $data['annual_interest_rate'],
                    'effective_from' => now()->toDateString(),
                    'created_by' => $userId,
                ]);
            }

            return $product;
        });

        return response()->json(['success' => true, 'data' => $this->present($product, $request)], 201);
    }

    #[OA\Put(
        path: '/api/savings/products/{product}',
        summary: 'Update a savings product',
        description: 'Partial. The kind never changes, and the code only while the product holds no account or time deposit. The rate is never edited here (422); add one through the rates endpoint. Turning it on is refused (422) until the books are ready.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The product'),
            new OA\Response(response: 403, description: 'Missing savings:settings or branches:view_all (company-wide)'),
            new OA\Response(response: 404, description: 'No such product'),
            new OA\Response(response: 422, description: 'Invalid fields, or turned on before the books are ready'),
        ],
    )]
    public function update(UpdateSavingsProductRequest $request, SavingsProduct $product): JsonResponse
    {
        $data = SavingsProductRuleFields::normalize($request->validated());
        $chargesFee = (array_key_exists('excess_withdrawal_fee', $data) ? $data['excess_withdrawal_fee'] : $product->excess_withdrawal_fee) !== null;

        if (($data['is_active'] ?? false) && ! $product->is_active) {
            $this->books->assertCanActivate($product->kind, $chargesFee);
        } elseif ($product->is_active && ($data['is_active'] ?? true) && $chargesFee && $product->excess_withdrawal_fee === null) {
            $this->books->assertCanChargeFee();
        }

        if (is_array($data['term_options_days'] ?? null)) {
            $data['term_options_days'] = array_values(array_map('intval', $data['term_options_days']));
        }

        $product->fill([...$data, 'updated_by' => $request->user()->id]);

        if ($product->isDirty(array_keys($data))) {
            $product->save();
        }

        return response()->json(['success' => true, 'data' => $this->present($product, $request)]);
    }

    #[OA\Delete(
        path: '/api/savings/products/{product}',
        summary: 'Delete a savings product',
        description: 'Only while it holds no account or time deposit and no time deposit product pays early terminations at its rate (otherwise 422). Its rate history goes with it. Audited.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Deleted'),
            new OA\Response(response: 403, description: 'Missing savings:settings or branches:view_all (company-wide)'),
            new OA\Response(response: 404, description: 'No such product'),
            new OA\Response(response: 422, description: 'The product is in use'),
        ],
    )]
    public function destroy(Request $request, SavingsProduct $product): JsonResponse
    {
        abort_unless(
            $request->user()->can('savings:settings') && $request->user()->can(BranchAccess::VIEW_ALL),
            403,
            UpdateSavingsProductRequest::COMPANY_WIDE_REFUSAL,
        );

        DB::transaction(function () use ($product): void {
            $locked = SavingsProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if ($locked->accounts()->exists() || $locked->timeDeposits()->exists()) {
                throw ValidationException::withMessages(['product' => [
                    "{$locked->name} holds accounts or time deposits, so it can't be deleted. Turn it off instead.",
                ]]);
            }

            if ($locked->preterminationFor()->exists()) {
                throw ValidationException::withMessages(['product' => [
                    "A time deposit product pays early terminations at {$locked->name}'s rate, so it can't be deleted.",
                ]]);
            }

            $locked->delete();
        });

        return response()->json(['success' => true, 'message' => 'Savings product deleted.']);
    }

    #[OA\Get(
        path: '/api/savings/products/{product}/rates',
        summary: 'A savings product\'s rate history',
        description: 'Newest first, each with `created_by` as `{id, name}`. Append-only: rates are added, never changed or removed.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The rates'),
            new OA\Response(response: 403, description: 'Missing savings:view'),
            new OA\Response(response: 404, description: 'No such product'),
        ],
    )]
    public function rates(Request $request, SavingsProduct $product): JsonResponse
    {
        $this->authorize('savings:view');

        $rates = $product->rates()->with('creator')->orderByDesc('effective_from')->orderByDesc('id')->get();

        return response()->json(['success' => true, 'data' => SavingsProductRateResource::collection($rates)->resolve($request)]);
    }

    #[OA\Post(
        path: '/api/savings/products/{product}/rates',
        summary: 'Add a rate from a date',
        description: 'From today or later (a past date is 422, so interest already figured never changes). A time deposit product\'s rate names one of its terms; a regular product\'s has none.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['annual_rate', 'effective_from'], properties: [
            new OA\Property(property: 'annual_rate', type: 'string', example: '1.5000'),
            new OA\Property(property: 'term_days', type: 'integer', nullable: true, example: 180),
            new OA\Property(property: 'effective_from', type: 'string', format: 'date'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The new rate'),
            new OA\Response(response: 403, description: 'Missing savings:settings or branches:view_all (company-wide)'),
            new OA\Response(response: 404, description: 'No such product'),
            new OA\Response(response: 422, description: 'A past date, a term the product doesn\'t offer, or a rate outside 0–100%'),
        ],
    )]
    public function storeRate(StoreSavingsProductRateRequest $request, SavingsProduct $product): JsonResponse
    {
        $rate = SavingsProductRate::query()->create([
            'product_id' => $product->id,
            'term_days' => $request->validated('term_days'),
            'annual_rate' => (string) $request->validated('annual_rate'),
            'effective_from' => $request->validated('effective_from'),
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['success' => true, 'data' => (new SavingsProductRateResource($rate->refresh()->load('creator')))->resolve($request)], 201);
    }

    /**
     * @param  Builder<SavingsProduct>  $query
     * @return Builder<SavingsProduct>
     */
    private function withCounts($query)
    {
        return $query->withCount(['accounts', 'timeDeposits', 'preterminationFor'])->with('rates');
    }

    /** @return array<string, mixed> */
    private function present(SavingsProduct $product, Request $request): array
    {
        $fresh = $this->withCounts(SavingsProduct::query())->findOrFail($product->id);

        return (new SavingsProductResource($fresh))->resolve($request);
    }
}
