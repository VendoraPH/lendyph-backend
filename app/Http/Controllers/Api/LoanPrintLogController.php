<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\LoanDocumentPrint;
use App\Services\AuditLogService;
use App\Services\LoanDocumentPrints;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        LoanDocumentPrint::DISCLOSURE => 'Printed disclosure statement',
        LoanDocumentPrint::PROMISSORY_NOTE => 'Printed promissory note',
    ];

    /**
     * The statuses GET /loans/{id}/disclosure and GET /loans/{id}/promissory-note
     * answer for. LoanPrintLogTest holds this against both, status by status.
     *
     * @var array<int, string>
     */
    private const PRINTABLE_STATUSES = ['approved', 'released', 'ongoing', 'completed'];

    /** A malformed `terms_fingerprint`, in words. */
    private const BAD_REFERENCE = "The printed document's terms reference isn't valid. Open the document again and print it.";

    /** A malformed `terms_version`, in words. */
    private const BAD_VERSION = "The printed document's terms version isn't valid. Open the document again and print it.";

    /** One of the two sent without the other. */
    private const NOT_TOGETHER = "Send the printed document's terms reference and terms version together.";

    #[OA\Post(
        path: '/api/loans/{id}/print-log',
        summary: 'Record that a loan document was printed',
        description: 'Writes one audit row: action `printed` on the loan, described "Printed disclosure statement" or "Printed promissory note". In the same transaction it records the print with the terms its paper shows: `terms_fingerprint` and `terms_version` as GET /api/loans/{loan}/disclosure or /promissory-note sent them with the payload that was printed, stored as they are. A print that sends neither (a tab opened before the payload carried them) records the terms the document states now. The terms are a versioned fingerprint of the amounts, rates, term and frequency, penalty terms, dates, deductions and charges, net proceeds and schedule; never the print time, the document\'s number or the member\'s details. While the loan is approved, GET /api/loans/{id} lists a printed document whose terms changed since (`printed_documents_outdated`), and the release is refused until it is printed again. Answers for the statuses the document itself is available for: approved, released, ongoing and completed.',
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
                    new OA\Property(property: 'terms_fingerprint', type: 'string', nullable: true, pattern: '^[0-9a-f]{64}$', description: 'Optional, with `terms_version`: the `terms_fingerprint` of the document payload that was printed (64 lowercase hexadecimal characters, or null when that payload had none).'),
                    new OA\Property(property: 'terms_version', type: 'integer', minimum: 1, maximum: 65535, description: 'Optional, with `terms_fingerprint`: the `terms_version` of the document payload that was printed.'),
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
            new OA\Response(response: 422, description: 'Unknown document, a loan status the document is not available for, or (in words, nothing recorded) a `terms_fingerprint` that isn\'t 64 lowercase hexadecimal characters or null, a `terms_version` that isn\'t a whole number from 1 to 65535, or one of the two sent without the other'),
        ],
    )]
    public function __invoke(Request $request, Loan $loan, LoanDocumentPrints $prints): JsonResponse
    {
        $this->authorize('loans:view');

        $validated = $request->validate([
            'document' => ['required', 'string', Rule::in(array_keys(self::DOCUMENTS))],
            // The terms the printed payload carried, stored as they are; both
            // or neither (an older tab sends neither).
            'terms_fingerprint' => ['present_with:terms_version', 'nullable', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'terms_version' => ['present_with:terms_fingerprint', 'integer', 'between:1,65535'],
        ], [
            'terms_fingerprint.present_with' => self::NOT_TOGETHER,
            'terms_fingerprint.string' => self::BAD_REFERENCE,
            'terms_fingerprint.regex' => self::BAD_REFERENCE,
            'terms_version.present_with' => self::NOT_TOGETHER,
            'terms_version.integer' => self::BAD_VERSION,
            'terms_version.between' => self::BAD_VERSION,
        ]);
        $document = $validated['document'];
        $printedTerms = $request->exists('terms_fingerprint')
            ? ['fingerprint' => $validated['terms_fingerprint'], 'version' => (int) $validated['terms_version']]
            : null;

        if (! in_array($loan->status, self::PRINTABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'document' => ['This document is only available for approved, released, ongoing, or completed loans.'],
            ]);
        }

        // The audit row as it always was, and the print with its terms: both
        // or neither.
        DB::transaction(function () use ($request, $loan, $document, $prints, $printedTerms): void {
            AuditLogService::log(
                action: 'printed',
                auditable: $loan,
                description: self::DOCUMENTS[$document],
            );

            $prints->record($loan, $document, $request->user(), $printedTerms);
        });

        return response()->json(['logged' => true], 201);
    }
}
