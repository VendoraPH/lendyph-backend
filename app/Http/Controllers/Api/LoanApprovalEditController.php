<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Loan\ApprovalEditLoanRequest;
use App\Http\Requests\Loan\ApprovalEditPreviewRequest;
use App\Http\Resources\LoanResource;
use App\Models\Loan;
use App\Services\LoanApprovalEditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * The Chairman/Chairwoman edits a loan application while it waits at their
 * approval step, then approves or forwards it with the chain's own endpoints
 * (finish run round 2, decision 14). See LoanApprovalEditService.
 */
class LoanApprovalEditController extends Controller
{
    public function __construct(private LoanApprovalEditService $edits) {}

    #[OA\Get(
        path: '/api/loans/{id}/approval-edit',
        summary: 'Whether the caller may edit the loan at its approval step now',
        description: 'Needs `loans:edit_in_approval`, held by the Chairman/Chairwoman\'s role only. `can_edit` is true while the loan is for approval (not a restructure) and waits at a step whose role the caller holds and which carries the permission, and that step isn\'t forwarded to someone else. Otherwise `reason` says why, in words. `version` (only with `can_edit`) is what PATCH must send back; any change to the loan, its co-makers, its collateral or its step since makes it stale.',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The edit\'s state',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'object', properties: [
                        new OA\Property(property: 'can_edit', type: 'boolean'),
                        new OA\Property(property: 'reason', type: 'string', nullable: true, example: 'You can edit this loan only while it waits at your approval step. It is at the "Manager" step.'),
                        new OA\Property(property: 'step', type: 'object', nullable: true, properties: [
                            new OA\Property(property: 'id', type: 'integer'),
                            new OA\Property(property: 'name', type: 'string', example: 'BOD Chairwoman'),
                        ]),
                        new OA\Property(property: 'version', type: 'string', nullable: true),
                    ]),
                ]),
            ),
            new OA\Response(response: 403, description: 'Missing loans:edit_in_approval'),
            new OA\Response(response: 404, description: 'Loan not found, or in a branch the caller cannot see'),
        ],
    )]
    public function show(Request $request, Loan $loan): JsonResponse
    {
        $this->authorize(LoanApprovalEditService::PERMISSION);

        return response()->json(['data' => $this->edits->state($loan, $request->user())]);
    }

    #[OA\Post(
        path: '/api/loans/{id}/approval-edit/preview',
        summary: 'The loan form\'s figures for an edit at the approval step',
        description: 'The same body and answer as POST /api/loans/preview with `loan_id` (the edit page\'s preview) for the loan in the path, which needs `loans:edit_in_approval` instead of `loans:update`, at the caller\'s step. Fields left out read as the loan has them; with no `deductions` the loan\'s own items are worked out again. `maturity_date` and `amortization` follow the loan\'s own interest method, term unit and rate period, exactly as saving stores them. Writes nothing.',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The preview, as POST /api/loans/preview answers it'),
            new OA\Response(response: 403, description: 'Missing loans:edit_in_approval, or the loan isn\'t waiting at the caller\'s step (message in words)'),
            new OA\Response(response: 422, description: 'Validation error, or the loan isn\'t for approval (`status`) or is a restructure (`loan`)'),
        ],
    )]
    public function preview(ApprovalEditPreviewRequest $request, Loan $loan): JsonResponse
    {
        return response()->json(['data' => $this->edits->preview($loan, $request->validated(), $request->user())]);
    }

    #[OA\Patch(
        path: '/api/loans/{id}/approval-edit',
        summary: 'Edit a loan application at the approval step',
        description: 'The Chairman/Chairwoman (`loans:edit_in_approval`) edits a loan for approval while it waits at their step: the loan form\'s fields (`principal_amount`, `interest_rate`, `term`, `frequency`, `start_date`, `purpose`, `scb_amount`, `deductions`, `account_officer_id`, `co_maker_ids` with `borrowers:view`, `collaterals` with `collaterals:update`), with the `version` from GET /api/loans/{id}/approval-edit. The member, product and policy exception can\'t change (the same value passes). Each changed figure is held to the product\'s limits; the maturity date, deductions and schedule are worked out as POST /api/loans/preview works them out. One audit row, `loan_edited_in_approval`, records each changed field\'s old and new value. The loan stays at the step for the caller to approve or forward. `amortization` is the saved loan\'s schedule, in the shape of the preview\'s.',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Saved (or nothing changed); `data` is the loan, `amortization` its schedule'),
            new OA\Response(response: 403, description: 'Missing loans:edit_in_approval (or collaterals:update / borrowers:view for those fields), or the loan isn\'t waiting at the caller\'s step (message in words)'),
            new OA\Response(response: 409, description: 'The loan, its co-makers, collateral or step changed since `version` was read; nothing was written. Message: "This loan changed after you opened it. Reload it to see the changes, then make your edit again."'),
            new OA\Response(response: 422, description: 'Validation error in words: a figure outside the product\'s limits, a principal that brings in a step the chain left out, a fixed field changed, the loan not for approval (`status`) or a restructure (`loan`)'),
        ],
    )]
    public function update(ApprovalEditLoanRequest $request, Loan $loan): JsonResponse
    {
        $result = $this->edits->edit($loan, $request->validated(), $request->user());
        $saved = $result['loan']->load('borrower', 'loanProduct', 'branch', 'coMakers', 'accountOfficer');
        $step = $result['step']->name;

        return response()->json([
            'message' => $result['changed']
                ? "Loan updated. It is still at the \"{$step}\" step for you to approve or forward."
                : "Nothing changed. The loan is still at the \"{$step}\" step for you to approve or forward.",
            'data' => (new LoanResource($saved))->resolve($request),
            'amortization' => $result['amortization'],
        ]);
    }
}
