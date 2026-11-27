<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShareCapital\ProcessAutoCreditRequest;
use App\Models\AutoCreditRunSkip;
use App\Services\ShareCapitalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class AutoCreditController extends Controller
{
    public function __construct(private ShareCapitalService $shareCapitalService) {}

    /**
     * The auto-credit preview for a cycle, for the caller's branches. A run
     * records what it covered (round 9): its scope (`all` for view_all, every
     * branch and members with no branch; `branches`, exactly its recorded
     * branches). A branch reads its cycle as done only when a run covered it
     * (`cycle_branches`, with a last "No branch" row for view_all when some
     * members on auto-credit have no branch; `cycle_run`). `last_run`'s totals
     * show to view_all, and to a branch user only for a `branches` run that
     * covered nothing outside their branches. Runs made before count for
     * view_all only.
     */
    #[OA\Get(
        path: '/api/auto-credit/status',
        summary: 'Get auto-credit status and member breakdown',
        tags: ['Share Capital'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'cycle_date', in: 'query', required: false, description: 'The cycle the run would be for: a month\'s 15th or its 30th (last day), the current one or one up to 92 days ago. Defaults to the current cycle: the latest 15th or month\'s last day on or before today in Manila.', schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Auto-credit status: the run\'s preview for `data.cycle_date` (`cycle_label` in words), with the pledges it would skip and why, and `cycle_options`, the cycles a run may take now, the current one first. Runs count by what they covered, their scope: `all` (a branches:view_all run) covers every branch and members with no branch; `branches` (a branch user\'s run) covers exactly the branches it recorded; runs made before scopes were recorded count for branches:view_all only. `cycle_branches` lists each branch of the caller\'s members on auto-credit, by name, as {branch_id, branch_name, run_id, processed_at}, with the cycle\'s latest run that covered it (run_id and processed_at null when none), `[]` when there are none. For a branches:view_all caller with members on auto-credit who have no branch, one last row {branch_id: null, branch_name: "No branch"}, covered only by `all` runs and runs made before; branch users never get it. `cycle_run` ({id, processed_at, status}) is the latest of those runs once every row has one, else null; with no row, the cycle\'s latest run that covered one of the caller\'s branches. `last_run` is the latest run that covered one of the caller\'s branches (any run for branches:view_all); its total_amount, member_count and skipped_count are null unless the caller holds branches:view_all, or the run was a `branches` run that covered only the caller\'s branches.'),
            new OA\Response(response: 422, description: 'Not a 15th or a month\'s last day, a future cycle, or one more than 92 days ago, in words'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function status(Request $request): JsonResponse
    {
        $this->authorize('share_capital:view');

        $data = $request->validate(['cycle_date' => ProcessAutoCreditRequest::cycleRules(required: false)], ProcessAutoCreditRequest::cycleMessages());

        // Only the caller's branches' members (BranchAccess; all with view_all),
        // and only the runs that covered those branches.
        return response()->json(['data' => $this->shareCapitalService->getAutoCreditStatus($request->user(), $data['cycle_date'] ?? null)]);
    }

    #[OA\Post(
        path: '/api/auto-credit/process',
        summary: 'Execute auto-credit for all eligible members',
        tags: ['Share Capital'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['cycle_date'],
                properties: [
                    new OA\Property(property: 'cycle_date', type: 'string', format: 'date', description: 'The cycle the run is for (finish run round 8): a month\'s 15th or its 30th (last day), the current one or a missed one up to 92 days ago, never a future one. A 15th credits "15" and "15/30" pledges, a 30th "30" and "15/30", each once per cycle, counted in the cycle\'s month.'),
                    new OA\Property(property: 'method', type: 'string', enum: ['cash', 'gcash', 'maya', 'bank_transfer'], default: 'bank_transfer', description: 'The settlement account the credits came through.'),
                    new OA\Property(property: 'reference_number', type: 'string', maxLength: 100, nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Auto-credit processed for the caller\'s branches (the run records its scope and branches: `all` for branches:view_all, else `branches`), and each member\'s credit posted Dr the method\'s account / Cr 3060 Share Capital when the organisation keeps books. A pledge the run doesn\'t credit is skipped: its schedule is credited in the other half, a run already credited it for this cycle, or its instalments for the cycle\'s month are all in. `data.skipped` lists each with its reason, and `data.skipped_count` counts them. `data.cycle_date` is the run\'s cycle.'),
            new OA\Response(response: 422, description: 'No cycle, not a 15th or a month\'s last day, a future cycle or one more than 92 days ago, a method outside the list, or a reference over 100 characters, in words. A caller with no branch and no branches:view_all is refused on `branch`, with nothing written: "No branch is assigned to your account, so there are no members to credit."'),
            new OA\Response(response: 409, description: 'Collided with another save taking the next reference. Not tried again, so overlapping runs cannot credit a pledge twice. Nothing was posted; check the last run before running again.'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function process(ProcessAutoCreditRequest $request): JsonResponse
    {
        // Credits only the caller's branches' members (all with view_all), and
        // records those branches on the run.
        $run = $this->shareCapitalService->processAutoCredit(
            $request->user(),
            $request->input('cycle_date'),
            $request->user(),
            $request->input('method'),
            $request->reference_number,
        );

        return response()->json([
            'message' => 'Auto-credit for '.ShareCapitalService::cycleLabel($run->cycle_date->toDateString())." processed for {$run->member_count} members. Total: ₱".number_format((float) $run->total_amount, 2).'.',
            'data' => [
                'id' => $run->id,
                'total_amount' => (float) $run->total_amount,
                'member_count' => $run->member_count,
                // Frontend-canonical alias
                'processed_count' => $run->member_count,
                'processed_at' => $run->processed_at?->toDateTimeString(),
                'status' => $run->status,
                // The cycle the run was for (finish run round 8).
                'cycle_date' => $run->cycle_date?->toDateString(),
                'cycle_label' => ShareCapitalService::cycleLabel($run->cycle_date->toDateString()),
                // Pledges already credited by hand this month (finish run round 7).
                'skipped_count' => (int) $run->skipped_count,
                'skipped' => $run->skips->map(fn (AutoCreditRunSkip $skip): array => [
                    'pledge_id' => $skip->pledge_id,
                    'borrower_name' => $skip->borrower?->full_name,
                    'amount' => (float) $skip->amount,
                    'reason' => $skip->reason,
                ])->values()->all(),
            ],
        ], 201);
    }
}
