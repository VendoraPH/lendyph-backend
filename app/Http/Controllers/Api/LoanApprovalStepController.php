<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\LoanApprovalStep;
use App\Models\User;
use App\Services\LoanApprovalChainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use OpenApi\Attributes as OA;

/**
 * The server-side loan approval chain.
 *
 * Replaces the browser-local `loan-approval-{id}` localStorage state that used
 * to hold every intermediate BOD signoff, so approvals cross devices and reach
 * the audit log. The response is shaped to the loan detail page's existing
 * `ApprovalState` / `ApprovalStep` / `RevisionRound` interfaces — notably
 * `index` rather than `step_order`, and `acted_by` as a display name.
 */
class LoanApprovalStepController extends Controller
{
    public function __construct(private LoanApprovalChainService $chain) {}

    #[OA\Get(
        path: '/api/loans/{loan}/approval-steps',
        summary: "Get a loan's approval chain",
        description: 'Returns the current revision round and every frozen earlier round. `can_act` answers whether the REQUESTING user may act on that step right now — role, step status and loan status combined.',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'loan', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Approval chain state',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'current_steps', type: 'array', items: new OA\Items(ref: '#/components/schemas/LoanApprovalStep')),
                                new OA\Property(
                                    property: 'rounds',
                                    type: 'array',
                                    description: 'Frozen earlier revision rounds, oldest first.',
                                    items: new OA\Items(
                                        properties: [
                                            new OA\Property(property: 'round', type: 'integer', example: 1),
                                            new OA\Property(property: 'sent_back_by', type: 'string', nullable: true),
                                            new OA\Property(property: 'sent_back_at', type: 'string', nullable: true),
                                            new OA\Property(property: 'sent_back_remarks', type: 'string', nullable: true),
                                            new OA\Property(property: 'steps', type: 'array', items: new OA\Items(ref: '#/components/schemas/LoanApprovalStep')),
                                        ],
                                        type: 'object',
                                    ),
                                ),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function index(Request $request, Loan $loan): JsonResponse
    {
        $this->authorize('loans:view');

        return response()->json(['data' => $this->state($loan, $request->user())]);
    }

    #[OA\Patch(
        path: '/api/loans/{loan}/approval-steps/{approvalStep}/approve',
        summary: 'Sign off the pending approval step',
        description: "Marks the step approved and advances the chain by one. When the next step is the chain's `release` step this also runs the `for_review` → `approved` loan transition, so `loans.status` stays authoritative. Also the re-submit path after a send-back.",
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'loan', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'approvalStep', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [new OA\Property(property: 'remarks', type: 'string', nullable: true, maxLength: 2000)],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Step approved'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: "Requesting user does not hold the step's role"),
            new OA\Response(response: 404, description: 'Step does not belong to this loan'),
            new OA\Response(response: 422, description: 'Step is not pending, or loan is not in for_review'),
        ],
    )]
    public function approve(Request $request, Loan $loan, LoanApprovalStep $approvalStep): JsonResponse
    {
        $this->authorize('loans:view');

        $validated = $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->chain->approve($loan, $approvalStep, $request->user(), $validated['remarks'] ?? null);

        return response()->json([
            'message' => 'Approval step signed off.',
            'data' => $this->state($loan->refresh(), $request->user()),
        ]);
    }

    #[OA\Patch(
        path: '/api/loans/{loan}/approval-steps/{approvalStep}/send-back',
        summary: 'Send the loan back to an earlier step for revision',
        description: 'Freezes the current round as history and opens round N+1 with `target_step_order` pending. The loan stays in `for_review` — a send-back is a move within review, not a rejection.',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'loan', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'approvalStep', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['target_step_order', 'remarks'],
                properties: [
                    new OA\Property(property: 'target_step_order', type: 'integer', minimum: 0, description: 'Must be earlier than the acting step and exist in the chain.'),
                    new OA\Property(property: 'remarks', type: 'string', maxLength: 2000, description: 'Required — the reason the loan is going back.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Loan sent back; a new round is open'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: "Requesting user does not hold the step's role"),
            new OA\Response(response: 404, description: 'Step does not belong to this loan'),
            new OA\Response(response: 422, description: 'Missing remarks, bad target, non-pending step, or loan not in for_review'),
        ],
    )]
    public function sendBack(Request $request, Loan $loan, LoanApprovalStep $approvalStep): JsonResponse
    {
        $this->authorize('loans:view');

        // `remarks` is REQUIRED here and optional on approve: a send-back is
        // the only action that asks somebody else to redo work, and the whole
        // point of the round it opens is to say why.
        $validated = $request->validate([
            'target_step_order' => ['required', 'integer', 'min:0'],
            'remarks' => ['required', 'string', 'max:2000'],
        ]);

        $this->chain->sendBack(
            $loan,
            $approvalStep,
            (int) $validated['target_step_order'],
            $request->user(),
            $validated['remarks'],
        );

        return response()->json([
            'message' => 'Loan sent back for revision.',
            'data' => $this->state($loan->refresh(), $request->user()),
        ]);
    }

    /**
     * @return array{current_steps: list<array<string, mixed>>, rounds: list<array<string, mixed>>}
     */
    private function state(Loan $loan, ?User $viewer): array
    {
        $steps = $loan->approvalSteps()->with('actedByUser')->get();

        if ($steps->isEmpty()) {
            return ['current_steps' => [], 'rounds' => []];
        }

        $currentRound = (int) $steps->max('round');
        $byRound = $steps->groupBy('round');

        $rounds = $byRound
            ->filter(fn ($_, $round) => (int) $round < $currentRound)
            ->sortKeys()
            ->map(function ($roundSteps, $round) use ($loan, $viewer) {
                // A frozen round is closed by exactly one sent_back step — the
                // one whose actor asked for the revision. That row IS the
                // round's sent_back_by/at/remarks; the frontend's RevisionRound
                // reads them as three flat fields.
                $sentBack = $roundSteps->firstWhere('status', LoanApprovalStep::STATUS_SENT_BACK);

                return [
                    'round' => (int) $round,
                    'sent_back_by' => $sentBack?->actedByUser?->full_name,
                    'sent_back_at' => $sentBack?->acted_at?->toIso8601String(),
                    'sent_back_remarks' => $this->composeSentBackRemarks($sentBack, $roundSteps),
                    'steps' => $roundSteps->map(fn (LoanApprovalStep $s) => $this->presentStep($s, $loan, $viewer))->values()->all(),
                ];
            })
            ->values()
            ->all();

        return [
            'current_steps' => $byRound
                ->get($currentRound, collect())
                ->map(fn (LoanApprovalStep $s) => $this->presentStep($s, $loan, $viewer))
                ->values()
                ->all(),
            'rounds' => $rounds,
        ];
    }

    /**
     * The round summary line, render-ready.
     *
     * The frontend prints this string verbatim, so the target step's name has
     * to be in it — "To Loan Processor: Missing collateral docs" — otherwise
     * the summary never says where the loan went back to. Composed on read
     * rather than stored, so `loan_approval_steps.remarks` and the audit row
     * both keep the reviewer's own words with nothing prepended; the step row
     * in the UI renders that bare remark separately.
     *
     * @param  Collection<int, LoanApprovalStep>  $roundSteps
     */
    private function composeSentBackRemarks(?LoanApprovalStep $sentBack, $roundSteps): ?string
    {
        if ($sentBack === null) {
            return null;
        }

        $target = $sentBack->sent_back_to_step_order === null
            ? null
            : $roundSteps->firstWhere('step_order', $sentBack->sent_back_to_step_order);

        return $target === null
            ? $sentBack->remarks
            : "To {$target->name}: {$sentBack->remarks}";
    }

    /**
     * @return array<string, mixed>
     */
    private function presentStep(LoanApprovalStep $step, Loan $loan, ?User $viewer): array
    {
        return [
            // Not in the frontend's ApprovalStep interface, but the two PATCH
            // endpoints are addressed by it, so the client cannot act without it.
            'id' => $step->id,

            // `index` rather than `step_order`: the loan detail page has always
            // called this field index, and renaming it there is a frontend change.
            'index' => $step->step_order,
            'step_id' => $step->step_id,
            'name' => $step->name,
            'role' => $step->role,
            'kind' => $step->kind,
            'status' => $step->status,
            'remarks' => $step->remarks,

            // ISO 8601 with the +08:00 offset rather than this codebase's usual
            // toDateTimeString(): the frontend annotates this field `// ISO` and
            // parses it with `new Date()`, which is only unambiguous with an offset.
            'acted_at' => $step->acted_at?->toIso8601String(),

            // A display NAME, not an id — the existing UI renders this string
            // directly. `acted_by_id` carries the id for anything that needs it.
            'acted_by' => $step->actedByUser?->full_name,
            'acted_by_id' => $step->acted_by,

            // The server's full answer, not just the client's role check: a step
            // is only actionable if the viewer holds the role AND the step is
            // pending AND the loan is still in review.
            'can_act' => $step->status === LoanApprovalStep::STATUS_PENDING
                && $loan->status === 'for_review'
                && $this->chain->canAct($step, $viewer),
        ];
    }
}
