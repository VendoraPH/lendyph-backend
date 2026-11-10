<?php

namespace App\Http\Controllers\Api\Savings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Savings\StoreSavingsTaxRuleRequest;
use App\Http\Requests\Savings\UpdateSavingsSettingsRequest;
use App\Http\Resources\Savings\SavingsUserRef;
use App\Models\SavingsSetting;
use App\Models\SavingsTaxRule;
use App\Services\Savings\TaxBook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Savings settings (design §1, §2): the approval tiers, dormancy and the
 * large-transaction alert, with the withholding tax rules. Readable with
 * `savings:view` or `savings:settings`; saving and adding a tax rule need
 * `savings:settings` and `branches:view_all`, since they apply to every
 * branch (like Phase 2's company-wide reminder settings).
 */
class SavingsSettingController extends Controller
{
    public function __construct(private readonly TaxBook $taxes) {}

    #[OA\Get(
        path: '/api/savings/settings',
        summary: 'Savings settings and withholding tax rules',
        description: 'Money as two-decimal strings and rates as four-decimal strings; users as `{id, name}`. `current_tax_rates` is the rate in force today per holder type; `tax_rules` is the whole append-only history, newest first.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The settings'),
            new OA\Response(response: 403, description: 'Missing savings:view and savings:settings'),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->canAny(['savings:view', 'savings:settings']), 403);

        return response()->json(['success' => true, 'data' => $this->present(SavingsSetting::current()->load('updater'))]);
    }

    #[OA\Put(
        path: '/api/savings/settings',
        summary: 'Save savings settings',
        description: 'Partial. The second approval limit must stay above the first; above it, exactly two different checkers approve (`tier3_checkers` is 2 in v1). Audited.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'tier1_limit', type: 'string', example: '10000.00'),
            new OA\Property(property: 'tier2_limit', type: 'string', example: '50000.00'),
            new OA\Property(property: 'tier3_checkers', type: 'integer', example: 2),
            new OA\Property(property: 'dormancy_months', type: 'integer', example: 24),
            new OA\Property(property: 'large_transaction_alert', type: 'string', example: '500000.00'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The saved settings'),
            new OA\Response(response: 403, description: 'Missing savings:settings or branches:view_all (company-wide)'),
            new OA\Response(response: 422, description: 'Outside the limits, in words'),
        ],
    )]
    public function update(UpdateSavingsSettingsRequest $request): JsonResponse
    {
        $settings = SavingsSetting::current();
        $settings->fill([...$request->validated(), 'updated_by' => $request->user()->id]);

        if ($settings->isDirty(array_keys($request->validated()))) {
            $settings->save();
        }

        return response()->json(['success' => true, 'message' => 'Savings settings saved.', 'data' => $this->present($settings->refresh()->load('updater'))]);
    }

    #[OA\Post(
        path: '/api/savings/tax-rules',
        summary: 'Add a withholding tax rate on deposit interest',
        description: 'Append-only: a rule starts today or later and is never edited. The rule in force on a date is the latest one starting on or before it.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['holder_type', 'rate', 'effective_from'], properties: [
            new OA\Property(property: 'holder_type', type: 'string', enum: SavingsTaxRule::HOLDER_TYPES),
            new OA\Property(property: 'rate', type: 'string', example: '20.0000'),
            new OA\Property(property: 'effective_from', type: 'string', format: 'date'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The new rule'),
            new OA\Response(response: 403, description: 'Missing savings:settings or branches:view_all (company-wide)'),
            new OA\Response(response: 422, description: 'A past date, an unknown holder type, or a rate outside 0–100% with more than four decimals'),
        ],
    )]
    public function storeTaxRule(StoreSavingsTaxRuleRequest $request): JsonResponse
    {
        $rule = SavingsTaxRule::query()->create([...$request->validated(), 'created_by' => $request->user()->id]);

        return response()->json(['success' => true, 'data' => $this->presentRule($rule->refresh()->load('creator'))], 201);
    }

    /** @return array<string, mixed> */
    private function present(SavingsSetting $settings): array
    {
        return [
            'tier1_limit' => $settings->tier1_limit,
            'tier2_limit' => $settings->tier2_limit,
            'tier3_checkers' => $settings->tier3_checkers,
            'dormancy_months' => $settings->dormancy_months,
            'large_transaction_alert' => $settings->large_transaction_alert,
            'updated_by' => SavingsUserRef::from($settings->updater),
            'updated_at' => $settings->updated_at,
            'current_tax_rates' => $this->taxes->current(),
            'tax_rules' => SavingsTaxRule::query()->with('creator')->orderByDesc('effective_from')->orderByDesc('id')->get()
                ->map(fn (SavingsTaxRule $rule) => $this->presentRule($rule))
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentRule(SavingsTaxRule $rule): array
    {
        return [
            'id' => $rule->id,
            'holder_type' => $rule->holder_type,
            'rate' => $rule->rate,
            'effective_from' => $rule->effective_from->toDateString(),
            'created_by' => SavingsUserRef::from($rule->creator),
            'created_at' => $rule->created_at,
        ];
    }
}
