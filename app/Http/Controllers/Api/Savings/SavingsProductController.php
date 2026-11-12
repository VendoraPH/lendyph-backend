<?php

namespace App\Http\Controllers\Api\Savings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Savings\StoreSavingsProductRateRequest;
use App\Http\Requests\Savings\StoreSavingsProductRequest;
use App\Http\Requests\Savings\UpdateSavingsProductRequest;
use App\Http\Resources\Savings\SavingsProductRateResource;
use App\Http\Resources\Savings\SavingsProductResource;
use App\Models\SavingsProduct;
use App\Models\SavingsProductRate;
use App\Services\Accounting\Money;
use App\Services\AuditLogService;
use App\Services\BranchAccess;
use App\Services\Savings\SavingsBooks;
use App\Services\Savings\SavingsMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The product'),
            new OA\Response(response: 403, description: 'Missing savings:settings or branches:view_all (company-wide)'),
            new OA\Response(response: 422, description: 'Invalid fields, a code in use, or turned on before the books are ready'),
        ],
    )]
    public function store(StoreSavingsProductRequest $request): JsonResponse
    {
        $data = $request->validated();
        $isActive = (bool) ($data['is_active'] ?? false);

        if ($isActive) {
            $this->books->assertCanActivate($data['kind']);
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
        $data = $request->validated();

        if (($data['is_active'] ?? false) && ! $product->is_active) {
            $this->books->assertCanActivate($product->kind);
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
        summary: 'Add a rate, or a ladder of balance tiers, from a date',
        description: 'From today or later (a past date is 422, so interest already figured never changes). A time deposit product\'s rate names one of its terms; a regular product\'s has none. Send either one `annual_rate` (the base tier; answers the new row) or, for a regular product only, `tiers`: the base tier first (`min_balance` null) and exactly once, then minimum balances above ₱0.00 rising with no repeats, at most 20 (answers the new rows). The ladder in force on a day is the rows sharing the latest `effective_from`; a same-date post replaces the whole ladder for that date (a single rate included), and the response is the full ladder in force for the date. Nothing is deleted. Each row is audited in words.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['effective_from'], properties: [
            new OA\Property(property: 'annual_rate', type: 'string', example: '1.5000'),
            new OA\Property(property: 'term_days', type: 'integer', nullable: true, example: 180),
            new OA\Property(property: 'effective_from', type: 'string', format: 'date'),
            new OA\Property(property: 'tiers', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'min_balance', type: 'string', nullable: true, example: '50000.00'),
                new OA\Property(property: 'annual_rate', type: 'string', example: '2.5000'),
            ])),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The new rate, or the new tier rows'),
            new OA\Response(response: 403, description: 'Missing savings:settings or branches:view_all (company-wide)'),
            new OA\Response(response: 404, description: 'No such product'),
            new OA\Response(response: 422, description: 'A past date, a term the product doesn\'t offer, a rate outside 0–100%, tiers on a time deposit product, or a ladder without exactly one base tier or with minimums out of order'),
        ],
    )]
    public function storeRate(StoreSavingsProductRateRequest $request, SavingsProduct $product): JsonResponse
    {
        $tiers = $request->validated('tiers');
        $ladder = $tiers ?? [['min_balance' => null, 'annual_rate' => $request->validated('annual_rate')]];
        $termDays = $request->validated('term_days');
        $effectiveFrom = (string) $request->validated('effective_from');
        $userId = (int) $request->user()->id;

        $postId = (string) Str::uuid();

        $rates = DB::transaction(function () use ($product, $ladder, $termDays, $effectiveFrom, $userId, $postId): Collection {
            $locked = SavingsProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            $rates = AuditLogService::withoutModelAuditing(fn (): Collection => collect($ladder)->map(fn (array $tier): SavingsProductRate => SavingsProductRate::query()->create([
                'product_id' => $locked->id,
                'term_days' => $termDays,
                'annual_rate' => (string) $tier['annual_rate'],
                'min_balance' => ($tier['min_balance'] ?? null) === null ? null : SavingsMath::pesos(SavingsMath::centavos($tier['min_balance'])),
                'rate_post_id' => $postId,
                'effective_from' => $effectiveFrom,
                'created_by' => $userId,
            ])->refresh()));

            foreach ($rates->values() as $i => $rate) {
                AuditLogService::log('created', $rate, null, $rate->getAttributes(), $this->rateWords($locked, $rate, $rates->get($i + 1)));
            }

            return $rates;
        });

        $rates = (new EloquentCollection($rates->all()))->load('creator');

        $data = $tiers === null
            ? (new SavingsProductRateResource($rates->first()))->resolve($request)
            : SavingsProductRateResource::collection($rates)->resolve($request);

        return response()->json(['success' => true, 'data' => $data], 201);
    }

    /**
     * A new rate row in words, for its audit row: "Regular Savings rate from
     * Dec 1, 2026. ₱50,000.00 and up: 2.5% a year." The base tier of a ladder
     * reads "Below ₱10,000.00", a single rate reads just its rate, and a time
     * deposit rate names its term. Rates are never rounded.
     */
    private function rateWords(SavingsProduct $product, SavingsProductRate $rate, ?SavingsProductRate $next): string
    {
        $percent = rtrim(rtrim($rate->annual_rate, '0'), '.').'% a year';
        $peso = fn (string $pesos): string => '₱'.number_format(Money::toCentavos($pesos) / 100, 2);

        $tier = match (true) {
            $rate->term_days !== null => "{$rate->term_days} days: {$percent}",
            $rate->min_balance !== null => $peso($rate->min_balance)." and up: {$percent}",
            $next !== null && $next->min_balance !== null => 'Below '.$peso($next->min_balance).": {$percent}",
            default => $percent,
        };

        return "{$product->name} rate from {$rate->effective_from->format('M j, Y')}. {$tier}.";
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
