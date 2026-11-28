<?php

namespace App\Http\Controllers\Api\Cic;

use App\Http\Requests\Cic\Submissions\AcknowledgeCicSubmissionRequest;
use App\Http\Requests\Cic\Submissions\EncryptCicSubmissionRequest;
use App\Http\Requests\Cic\Submissions\ListCicSubmissionsRequest;
use App\Http\Requests\Cic\Submissions\StoreCicSubmissionRequest;
use App\Models\CicCapture;
use App\Models\CicPublicKey;
use App\Models\CicSubmission;
use App\Models\User;
use App\Services\Cic\CicAccess;
use App\Services\Cic\File\CicEncryptor;
use App\Services\Cic\Submissions\CicSubmissionPresenter;
use App\Services\Cic\Submissions\CicSubmissionService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CIC files (prompt §11–§15): the submission history, generation, the
 * pre-encryption review, encryption, the encrypted download, marking a file
 * submitted and recording CIC's answer. Every route needs its `cic:*`
 * permission plus `branches:view_all`, and shows no member's data.
 */
class CicSubmissionController extends CicController
{
    private ?bool $keyUsable = null;

    public function __construct(
        private readonly CicSubmissionService $submissions,
        private readonly CicEncryptor $encryptor,
    ) {}

    #[OA\Get(
        path: '/api/cic/submissions',
        summary: 'CIC submission history',
        description: 'Needs `cic:view` and `branches:view_all`. Filters `status`, `reference_date` (YYYY-MM-DD), `submission_type` (0|1); paged (`per_page` ≤ 100), newest first. {success, data: row[], meta}; each row carries the caller\'s `actions` {encrypt, download, mark_submitted, acknowledge, correct}, the same object as the detail\'s.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The history'), new OA\Response(response: 403, description: 'Missing cic:view or branches:view_all'), new OA\Response(response: 422, description: 'A bad filter')],
    )]
    public function index(ListCicSubmissionsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $validations = [];

        $page = CicSubmission::query()
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['reference_date']), fn ($query) => $query->whereDate('reference_date', $filters['reference_date']))
            ->when(isset($filters['submission_type']), fn ($query) => $query->where('submission_type', (int) $filters['submission_type']))
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15));

        return response()->json([
            'success' => true,
            'data' => CicSubmissionPresenter::rows($page->getCollection(), fn (CicSubmission $submission) => $this->actions(
                $request->user(),
                $submission,
                function () use ($submission, &$validations) {
                    return $validations[$submission->capture_id] ??= $this->submissions->validate($submission->capture()->firstOrFail());
                },
            )),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/cic/submissions/{id}',
        summary: 'A CIC file and its pre-encryption review',
        description: 'Needs `cic:view` and `branches:view_all`. The file\'s counts by record type, dates, type, filename and checksums, the live validation summary, the active key and whether it can encrypt, the correction link and affected records, the status history and the caller\'s actions. No member data.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The file'), new OA\Response(response: 403, description: 'Missing cic:view or branches:view_all'), new OA\Response(response: 404, description: 'No such file')],
    )]
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->authorizeCic($request, CicAccess::VIEW);
        $submission = CicSubmission::query()->find($id) ?? abort(404, 'There is no such CIC file.');

        return response()->json(['success' => true, 'data' => $this->detail($user, $submission)]);
    }

    #[OA\Post(
        path: '/api/cic/submissions',
        summary: 'Generate a CIC file from a complete capture',
        description: 'Needs `cic:prepare` and `branches:view_all`. Body {capture_id, submission_type 0|1, comments? (≤100), confirm_warnings?, corrects_submission_id?, correction_reason?}. Refused (422, in words, with `data.validation` counts and top issues by field) while the capture isn\'t complete, the provider code isn\'t 8 letters or digits, any error remains, or warnings aren\'t confirmed. A correction comes from a correction capture of the corrected file\'s reference date, corrects a submitted, accepted or rejected file and lists the affected records. Audited.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 201, description: 'The generated file'), new OA\Response(response: 403, description: 'Missing cic:prepare or branches:view_all'), new OA\Response(response: 409, description: 'A file of the same name was made this second'), new OA\Response(response: 422, description: 'Refused, in words')],
    )]
    public function store(StoreCicSubmissionRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $this->submissions->generate($user, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'File generated. Review it, then encrypt it.',
            'data' => $this->detail($user, $result['submission'], $result['validation'], $result['affected']),
        ], 201);
    }

    #[OA\Post(
        path: '/api/cic/submissions/{id}/encrypt',
        summary: 'Encrypt a generated CIC file to CIC\'s active key',
        description: 'Needs `cic:submit` and `branches:view_all`, and {confirm: true} after the pre-encryption review. The file must be generated, the active key verified and unexpired, and gpg installed. The TXT is rebuilt (409 when its checksum differs), zipped and encrypted in a temporary folder that is deleted; only the `.zip.gpg` is kept. Audited.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The encrypted file'), new OA\Response(response: 403, description: 'Missing cic:submit or branches:view_all'), new OA\Response(response: 409, description: 'The rebuilt file differs'), new OA\Response(response: 422, description: 'Refused, in words')],
    )]
    public function encrypt(EncryptCicSubmissionRequest $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $submission = $this->submissions->encrypt($user, $id);

        return response()->json(['success' => true, 'message' => 'File encrypted for CIC.', 'data' => $this->detail($user, $submission)]);
    }

    #[OA\Get(
        path: '/api/cic/submissions/{id}/download',
        summary: 'Download the encrypted CIC file',
        description: 'Needs `cic:submit` and `branches:view_all`. Streams only the `.zip.gpg`, once the file is encrypted or later; never a plain file. Every download is counted and audited.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The encrypted file'), new OA\Response(response: 403, description: 'Missing cic:submit or branches:view_all'), new OA\Response(response: 409, description: 'The stored file is missing or changed'), new OA\Response(response: 422, description: 'Not encrypted yet')],
    )]
    public function download(Request $request, int $id): StreamedResponse
    {
        $this->authorizeCic($request, CicAccess::SUBMIT);
        $file = $this->submissions->recordDownload($id);

        return Storage::disk(CicSubmissionService::DISK)->download($file['path'], $file['name'], [
            'Content-Type' => 'application/pgp-encrypted',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    #[OA\Post(
        path: '/api/cic/submissions/{id}/mark-submitted',
        summary: 'Mark an encrypted CIC file submitted',
        description: 'Needs `cic:submit` and `branches:view_all`. Moves an encrypted file to submitted, after staff upload it on CIC\'s portal. Audited.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The file'), new OA\Response(response: 403, description: 'Missing cic:submit or branches:view_all'), new OA\Response(response: 422, description: 'Not encrypted')],
    )]
    public function markSubmitted(Request $request, int $id): JsonResponse
    {
        $user = $this->authorizeCic($request, CicAccess::SUBMIT);
        $submission = $this->submissions->markSubmitted($user, $id);

        return response()->json(['success' => true, 'message' => 'File marked submitted to CIC.', 'data' => $this->detail($user, $submission)]);
    }

    #[OA\Post(
        path: '/api/cic/submissions/{id}/acknowledgement',
        summary: 'Record CIC\'s answer to a submitted file',
        description: 'Needs `cic:review` and `branches:view_all`, and never the file\'s generator (403 in words). Body {status: accepted|rejected, notes (required when rejected, ≤2000), cic_reference_no? (CIC\'s own reference, ≤60: letters, digits, spaces and - _ / . # :)}. From submitted only. Audited (the notes\' length, never their text, and CIC\'s reference number).',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The file'), new OA\Response(response: 403, description: 'Missing cic:review or branches:view_all, or the generator'), new OA\Response(response: 422, description: 'Not submitted, or a bad body')],
    )]
    public function acknowledge(AcknowledgeCicSubmissionRequest $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $notes = $request->validated('notes');
        $submission = $this->submissions->acknowledge(
            $user,
            $id,
            $request->validated('status'),
            is_string($notes) && trim($notes) !== '' ? trim($notes) : null,
            $request->validated('cic_reference_no'),
        );

        return response()->json(['success' => true, 'message' => 'CIC\'s answer recorded.', 'data' => $this->detail($user, $submission)]);
    }

    /**
     * @param  array{errors: int, warnings: int, top_issues: list<array<string, mixed>>}|null  $validation
     * @param  list<array{record_type: string, record_key: string, change: string}>|null  $affected
     * @return array<string, mixed>
     */
    private function detail(User $user, CicSubmission $submission, ?array $validation = null, ?array $affected = null): array
    {
        $submission->refresh();
        /** @var CicCapture $capture */
        $capture = $submission->capture()->firstOrFail();
        $validation ??= $this->submissions->validate($capture);
        $affected ??= $this->submissions->affected($submission);
        $actions = $this->actions($user, $submission, fn () => $validation);

        return CicSubmissionPresenter::detail($submission, $validation, $affected, $this->encryptor, $actions);
    }

    /**
     * What the caller can do next with a file: the same object on the list's
     * rows and the detail. Encrypting also needs a capture with no errors,
     * so the capture is validated only when everything else allows it.
     *
     * @param  Closure(): array{errors: int, warnings: int, top_issues: list<array<string, mixed>>}  $validation
     * @return array{encrypt: bool, download: bool, mark_submitted: bool, acknowledge: bool, correct: bool}
     */
    private function actions(User $user, CicSubmission $submission, Closure $validation): array
    {
        $access = app(CicAccess::class);

        return [
            'encrypt' => $submission->status === CicSubmission::STATUS_GENERATED
                && $access->allows($user, CicAccess::SUBMIT)
                && $this->keyUsable()
                && $this->encryptor->available()
                && $validation()['errors'] === 0,
            'download' => in_array($submission->status, CicSubmissionService::DOWNLOADABLE, true)
                && $submission->gpg_path !== null
                && $access->allows($user, CicAccess::SUBMIT),
            'mark_submitted' => $submission->status === CicSubmission::STATUS_ENCRYPTED && $access->allows($user, CicAccess::SUBMIT),
            'acknowledge' => $submission->status === CicSubmission::STATUS_SUBMITTED
                && $access->allows($user, CicAccess::REVIEW)
                && $submission->generated_by !== $user->id,
            'correct' => in_array($submission->status, CicSubmissionService::CORRECTABLE, true) && $access->allows($user, CicAccess::PREPARE),
        ];
    }

    private function keyUsable(): bool
    {
        return $this->keyUsable ??= CicSubmissionService::keyProblem(CicPublicKey::activeKey()) === null;
    }
}
