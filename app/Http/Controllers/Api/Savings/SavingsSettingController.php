<?php

namespace App\Http\Controllers\Api\Savings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Savings\PrepareSavingsBooksRequest;
use App\Http\Requests\Savings\StoreSavingsTaxRuleRequest;
use App\Http\Requests\Savings\UpdateSavingsSettingsRequest;
use App\Http\Resources\Savings\SavingsUserRef;
use App\Models\AccountingAccountMapping;
use App\Models\SavingsProduct;
use App\Models\SavingsSetting;
use App\Models\SavingsTaxRule;
use App\Services\Savings\SavingsBooks;
use App\Services\Savings\TaxBook;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use PDOException;

/**
 * Savings settings (design §1, §2): the approval tiers, dormancy and the
 * large-transaction alert, with the withholding tax rules. Readable with
 * `savings:view` or `savings:settings`; saving and adding a tax rule need
 * `savings:settings` and `branches:view_all`, since they apply to every
 * branch (like Phase 2's company-wide reminder settings).
 *
 * The settings also say whether savings is set up in the books
 * (`books_ready`, `books_missing`), and `POST /savings/settings/prepare-books`
 * sets it up: the same {@see SavingsBooks::prepare()} as the
 * `savings:prepare-books` command, so staff can turn savings on for a
 * lending company's chart without a deploy.
 */
class SavingsSettingController extends Controller
{
    use DetectsConcurrencyErrors;

    private const BOOKS_CONFLICT = 'Someone else was setting up the savings books at the same moment. Refresh to see the result.';

    public function __construct(
        private readonly TaxBook $taxes,
        private readonly SavingsBooks $books,
    ) {}

    #[OA\Get(
        path: '/api/savings/settings',
        summary: 'Savings settings and withholding tax rules',
        description: 'Money as two-decimal strings and rates as four-decimal strings; users as `{id, name}`. `current_tax_rates` is the rate in force today per holder type; `tax_rules` is the whole append-only history, newest first. `books_ready` is true when a chart of accounts exists and all four savings posting roles have an account; `books_missing` lists the roles without one, in words (all four when there is no chart).',
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
            new OA\Property(property: 'notice_days', type: 'array', items: new OA\Items(type: 'integer'), example: [30, 15, 7, 3, 0]),
            new OA\Property(property: 'dormancy_warning_days', type: 'integer', example: 60),
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

    #[OA\Post(
        path: '/api/savings/settings/prepare-books',
        summary: 'Set up savings in the books',
        description: 'Adds any missing 2050 Savings Deposits, 2060 Time Deposits and 5160 Interest Expense on Deposits, and maps the four savings posting roles (withholding tax on deposits follows the account withholding tax payable posts to, else 2210). Idempotent: a role already mapped is kept, and a second call changes nothing. Each account added and role mapped writes an audit row in the caller\'s name. Answers in words: `added`, `mapped`, `unchanged` (roles that already had an account) and `skipped` (roles it could not map, with the reason), plus `books_ready` and `books_missing` as on GET /savings/settings.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: '{books_ready, books_missing, added, mapped, unchanged, skipped}, each a list of sentences'),
            new OA\Response(response: 403, description: 'Missing savings:settings or branches:view_all (company-wide)'),
            new OA\Response(response: 409, description: 'Someone else set up the savings books at the same moment'),
            new OA\Response(response: 422, description: 'No chart of accounts yet (`errors.books`)'),
        ],
    )]
    public function prepareBooks(PrepareSavingsBooksRequest $request): JsonResponse
    {
        try {
            $result = $this->books->prepare(false, $request->user()->id);
        } catch (UniqueConstraintViolationException) {
            abort(409, self::BOOKS_CONFLICT);
        } catch (PDOException $e) {
            if (! $this->causedByConcurrencyError($e)) {
                throw $e;
            }

            abort(409, self::BOOKS_CONFLICT);
        }

        if (! $result['chart']) {
            throw ValidationException::withMessages(['books' => [
                'There is no chart of accounts yet, so the savings books can\'t be set up. Set one up in Accounting → Chart of Accounts first.',
            ]]);
        }

        $status = $this->booksStatus();
        $names = DB::table('accounting_accounts')->pluck('name', 'code');
        $account = fn (?string $code): string => $code === null ? 'an account' : trim($code.' '.($names[$code] ?? ''));

        return response()->json([
            'success' => true,
            'message' => $this->booksMessage($result, $status['books_missing']),
            'data' => [
                ...$status,
                'added' => array_map(fn (array $row): string => "{$row['code']} {$row['name']} ({$row['type']})", $result['created']),
                'mapped' => array_map(fn (array $row): string => AccountingAccountMapping::label($row['role'])." now posts to {$row['code']} {$row['name']}", $result['mapped']),
                'unchanged' => array_map(fn (array $row): string => AccountingAccountMapping::label($row['role']).' already posts to '.$account($row['code']), $result['kept']),
                'skipped' => array_map(fn (array $row): string => AccountingAccountMapping::label($row['role']).": {$row['reason']}", $result['skipped']),
            ],
        ]);
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
            'notice_days' => $settings->noticeDays(),
            'dormancy_warning_days' => $settings->dormancy_warning_days,
            'updated_by' => SavingsUserRef::from($settings->updater),
            'updated_at' => $settings->updated_at,
            ...$this->booksStatus(),
            'current_tax_rates' => $this->taxes->current(),
            'tax_rules' => SavingsTaxRule::query()->with('creator')->orderByDesc('effective_from')->orderByDesc('id')->get()
                ->map(fn (SavingsTaxRule $rule) => $this->presentRule($rule))
                ->all(),
        ];
    }

    /**
     * Whether savings is set up in the books: a chart exists and every
     * savings posting role has an account. The roles missing, in words.
     *
     * @return array{books_ready: bool, books_missing: list<string>}
     */
    private function booksStatus(): array
    {
        $missing = $this->books->missingRoles(SavingsProduct::KIND_TIME_DEPOSIT) ?? SavingsBooks::ROLES;

        return [
            'books_ready' => $missing === [],
            'books_missing' => array_map(AccountingAccountMapping::label(...), array_values($missing)),
        ];
    }

    /**
     * @param  array{created: list<mixed>, mapped: list<mixed>}  $result
     * @param  list<string>  $missing
     */
    private function booksMessage(array $result, array $missing): string
    {
        if ($missing !== []) {
            $one = count($missing) === 1;

            return 'The savings books are not ready: the '.collect($missing)->map(Str::lower(...))->join(', ', ' and ')
                .' posting '.($one ? 'role still has' : 'roles still have').' no account. Set '.($one ? 'it' : 'them').' in Accounting → Settings → Default Accounts.';
        }

        if ($result['created'] === [] && $result['mapped'] === []) {
            return 'The savings books were already set up. Nothing changed.';
        }

        $added = count($result['created']);
        $mapped = count($result['mapped']);

        return "The savings books are ready: {$added} ".Str::plural('account', $added)." added and {$mapped} posting ".Str::plural('role', $mapped).' mapped.';
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
