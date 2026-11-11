<?php

namespace App\Http\Controllers\Api\Cda;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cda\Registers\CdaSubmissionProofRequest;
use App\Http\Resources\Cda\RegisterPresenter;
use App\Models\CdaSubmission;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Registers\SubmissionProofs;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Proof of a filing with CDA (CDA design §3). Needs `cda:submit` and
 * `branches:view_all`, plus the report type's view permission when it is
 * restricted. The proof is a document about the submission; each upload is
 * a new version that becomes the final one, never replacing the last.
 */
class CdaSubmissionProofController extends Controller
{
    public function __construct(
        private readonly CdaAccess $access,
        private readonly SubmissionProofs $proofs,
    ) {}

    #[OA\Post(
        path: '/api/cda/submissions/{id}/proof',
        summary: 'Upload the proof of a submission',
        description: 'Needs `cda:submit` and `branches:view_all`, plus the report\'s view permission when it is restricted (mediation, audit, tax). Multipart `file` (up to 10 MB; a picture, PDF, Word document or Excel workbook) and optional `notes`. Each upload is a new final version of the submission\'s proof document; the older ones are kept. Audited.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(required: ['file'], properties: [
            new OA\Property(property: 'file', type: 'string', format: 'binary'),
            new OA\Property(property: 'notes', type: 'string', nullable: true),
        ]))),
        responses: [new OA\Response(response: 201, description: '{success, message, data: {submission_id, document}}'), new OA\Response(response: 403, description: 'Missing cda:submit, branches:view_all or the report\'s view permission'), new OA\Response(response: 404, description: 'No such submission'), new OA\Response(response: 422, description: 'Refused, in words')],
    )]
    public function store(CdaSubmissionProofRequest $request, int $submission): JsonResponse
    {
        $found = CdaSubmission::query()->with('report')->find($submission);
        abort_if($found === null || $found->report === null, 404, 'There is no such submission.');
        $this->access->authorizeType($request->user(), $found->report->report_type);

        $document = $this->proofs->upload($found, $request->file('file'), $request->user(), $request->validated('notes'));

        return RegisterPresenter::one([
            'submission_id' => (int) $found->id,
            'document' => RegisterPresenter::document($document->fresh(['period', 'report', 'versions.file'])),
        ], 201, 'Proof uploaded.');
    }
}
