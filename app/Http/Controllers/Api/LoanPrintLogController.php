<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class LoanPrintLogController extends Controller
{
    /**
     * The documents whose printing is recorded, and the Audit Trail description
     * each one writes.
     *
     * @var array<string, string>
     */
    private const DOCUMENTS = [
        'disclosure' => 'Printed disclosure statement',
        'promissory_note' => 'Printed promissory note',
    ];

    /**
     * The statuses GET /loans/{id}/disclosure and GET /loans/{id}/promissory-note
     * answer for. LoanPrintLogTest holds this against both, status by status.
     *
     * @var array<int, string>
     */
    private const PRINTABLE_STATUSES = ['approved', 'released', 'ongoing', 'completed'];

    #[OA\Post(
        path: '/api/loans/{id}/print-log',
        summary: 'Record that a loan document was printed',
        description: 'Writes one audit row: action `printed` on the loan, described "Printed disclosure statement" or "Printed promissory note". Answers for the statuses the document itself is available for: approved, released, ongoing and completed.',
        tags: ['Loan Documents'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['document'],
                properties: [
                    new OA\Property(property: 'document', type: 'string', enum: ['disclosure', 'promissory_note']),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Recorded',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'logged', type: 'boolean', example: true),
                ]),
            ),
            new OA\Response(response: 403, description: 'Missing loans:view'),
            new OA\Response(response: 404, description: 'Loan not found, or in a branch the caller cannot see'),
            new OA\Response(response: 422, description: 'Unknown document, or a loan status the document is not available for'),
        ],
    )]
    public function __invoke(Request $request, Loan $loan): JsonResponse
    {
        $this->authorize('loans:view');

        $document = $request->validate([
            'document' => ['required', 'string', Rule::in(array_keys(self::DOCUMENTS))],
        ])['document'];

        if (! in_array($loan->status, self::PRINTABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'document' => ['This document is only available for approved, released, ongoing, or completed loans.'],
            ]);
        }

        AuditLogService::log(
            action: 'printed',
            auditable: $loan,
            description: self::DOCUMENTS[$document],
        );

        return response()->json(['logged' => true], 201);
    }
}
