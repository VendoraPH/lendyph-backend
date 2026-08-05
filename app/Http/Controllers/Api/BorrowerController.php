<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Borrower\RejectBorrowerRequest;
use App\Http\Requests\Borrower\StoreBorrowerRequest;
use App\Http\Requests\Borrower\UpdateBorrowerRequest;
use App\Http\Resources\BorrowerResource;
use App\Http\Resources\DocumentResource;
use App\Models\Borrower;
use App\Models\Document;
use App\Services\BorrowerSubmissionTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class BorrowerController extends Controller
{
    #[OA\Get(
        path: '/api/borrowers',
        summary: 'List borrowers',
        description: 'Get a paginated list of borrowers with search and filters',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'inactive', 'blacklisted', 'pending', 'rejected'])),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated borrower list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('borrowers:view');

        $borrowers = Borrower::with('branch')
            ->when(request('search'), fn ($q, $search) => $q->search($search))
            ->when(request('status'), fn ($q, $status) => $q->where('status', $status))
            ->when(request('branch_id'), fn ($q, $branchId) => $q->forBranch($branchId))
            ->latest()
            ->paginate(min((int) request('per_page', 15), 100));

        // Status count aggregation so the frontend can render status tabs without a second request.
        $stats = Borrower::when(request('branch_id'), fn ($q, $branchId) => $q->forBranch($branchId))
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return BorrowerResource::collection($borrowers)
            ->additional(['meta' => ['stats' => [
                'active' => (int) ($stats['active'] ?? 0),
                'inactive' => (int) ($stats['inactive'] ?? 0),
                'blacklisted' => (int) ($stats['blacklisted'] ?? 0),
                'pending' => (int) ($stats['pending'] ?? 0),
                'rejected' => (int) ($stats['rejected'] ?? 0),
            ]]]);
    }

    #[OA\Post(
        path: '/api/borrowers',
        summary: 'Create borrower',
        description: <<<'DESC'
Create a new borrower profile.

**Authentication:**
- Authenticated requests behave as today (any `status` allowed, response returns the full BorrowerResource).
- Anonymous requests must set `status="pending"`. The response is a slim `{ id, submission_token, expires_at }` shape; the submission token lives for 15 minutes and is used as the `X-Submission-Token` header on the photo + valid-ids uploads. Per-IP rate limit (5 / 10 min) is enforced for the public-registration path.
- Anonymous requests with any status other than `pending` are rejected (401/422).
- `branch_id` is **required for authenticated (operator) creates** and **optional for anonymous submissions** — the admin assigns the branch during review.

**Validation rules (enforced):**
- `contact_number` must match `^(\+?\d{7,15}|0\d{9,10})$`
- `email` must be unique across all borrowers
- `pledge_amount` max `9,999,999.99`
- `birthdate` must be after 1900-01-01 and before today

**Duplicate detection:** the request is rejected with 422 if a borrower with a similar name already exists:
1. **Exact match** — same normalized first+middle+last (case/whitespace insensitive) → always rejected
2. **Fuzzy match** — Levenshtein distance ≤ 2 on normalized full name, *and* birthdate matches

Pass `force=true` in the body to bypass the duplicate check (frontend "Create Anyway" flow).
On a duplicate rejection, the error message in `errors.first_name[0]` contains the matched borrower's code and DOB.

**Side effect:** a ShareCapitalPledge row is auto-created for the new borrower inside the same transaction.
DESC,
        tags: ['Borrowers'],
        security: [['sanctum' => []], []],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['first_name', 'last_name'],
                properties: [
                    new OA\Property(property: 'first_name', type: 'string', example: 'Juan'),
                    new OA\Property(property: 'middle_name', type: 'string', example: 'Santos'),
                    new OA\Property(property: 'last_name', type: 'string', example: 'Dela Cruz'),
                    new OA\Property(property: 'suffix', type: 'string', example: 'Jr.'),
                    new OA\Property(property: 'birthdate', type: 'string', format: 'date', example: '1990-01-15'),
                    new OA\Property(property: 'civil_status', type: 'string', enum: ['single', 'married', 'widowed', 'separated', 'divorced']),
                    new OA\Property(property: 'gender', type: 'string', enum: ['male', 'female']),
                    new OA\Property(property: 'address', type: 'string', description: 'Legacy single-line address (use structured fields below instead)'),
                    new OA\Property(property: 'street_address', type: 'string', example: '123 Rizal St.'),
                    new OA\Property(property: 'barangay', type: 'string', example: 'Poblacion'),
                    new OA\Property(property: 'city', type: 'string', example: 'Butuan'),
                    new OA\Property(property: 'province', type: 'string', example: 'Agusan del Norte'),
                    new OA\Property(property: 'contact_number', type: 'string', example: '09171234567', description: 'PH mobile (09xxxxxxxxx) or international (+63…)'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'juan@email.com', description: 'Must be unique'),
                    new OA\Property(property: 'employer_or_business', type: 'string'),
                    new OA\Property(property: 'date_hired', type: 'string', format: 'date', example: '2020-03-01', description: 'Employment start date (not in the future)'),
                    new OA\Property(property: 'monthly_income', type: 'number', example: 25000),
                    new OA\Property(property: 'pledge_amount', type: 'number', example: 500, description: 'Share capital pledge amount (max 9,999,999.99, defaults to 0)'),
                    new OA\Property(property: 'spouse_first_name', type: 'string'),
                    new OA\Property(property: 'spouse_middle_name', type: 'string'),
                    new OA\Property(property: 'spouse_last_name', type: 'string'),
                    new OA\Property(property: 'spouse_contact_number', type: 'string'),
                    new OA\Property(property: 'spouse_occupation', type: 'string'),
                    new OA\Property(property: 'branch_id', type: 'integer', example: 1, description: 'Required for authenticated (operator) creates; optional for anonymous submissions (admin assigns during review).'),
                    new OA\Property(property: 'force', type: 'boolean', example: false, description: 'Bypass the duplicate-borrower check (use for "Create Anyway" confirmation)'),
                    new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive', 'blacklisted', 'pending'], description: 'Anonymous (public registration) requests must set this to "pending".'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Borrower created (authenticated: full BorrowerResource; anonymous: { id, submission_token, expires_at })'),
            new OA\Response(response: 401, description: 'Anonymous request with status other than `pending`'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(
                response: 422,
                description: 'Validation error. On duplicate, errors.first_name[0] includes the matched borrower code/name/DOB.',
                content: new OA\JsonContent(
                    example: [
                        'message' => 'The first name field has duplicate match.',
                        'errors' => [
                            'first_name' => [
                                'A similar borrower already exists: Juan Dela Cruz (BRW-000042, born 1990-01-15). Pass force=true to create anyway.',
                            ],
                        ],
                    ],
                ),
            ),
        ],
    )]
    public function store(StoreBorrowerRequest $request, BorrowerSubmissionTokenService $tokens): JsonResponse
    {
        // Wrap in a transaction so the borrower insert and the Borrower::created
        // hook (which creates the ShareCapitalPledge) are atomic — if either fails,
        // neither row is left behind.
        $borrower = DB::transaction(function () use ($request) {
            return Borrower::create($request->safe()->except('force'));
        });

        // Public registration path — return a slim payload plus a submission
        // token the anonymous client uses to attach photo + valid IDs against
        // the borrower row it just created.
        if (! $request->user()) {
            $token = $tokens->issue($borrower, $request->ip());

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $borrower->id,
                    'submission_token' => $token->token,
                    'expires_at' => $token->expires_at->toIso8601String(),
                ],
            ], 201);
        }

        $borrower->load('branch');

        return (new BorrowerResource($borrower))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/borrowers/{id}',
        summary: 'Show borrower',
        description: 'Get full borrower profile with co-makers and documents',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Borrower details'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(Borrower $borrower): BorrowerResource
    {
        $this->authorize('borrowers:view');

        $borrower->load('branch', 'coMakers.documents', 'documents');

        return new BorrowerResource($borrower);
    }

    #[OA\Put(
        path: '/api/borrowers/{id}',
        summary: 'Update borrower',
        description: <<<'DESC'
Update borrower profile. All fields are optional (`sometimes` rules).

The same validation and duplicate-detection rules as the create endpoint apply, except:
- Email uniqueness ignores the current borrower being updated
- Duplicate detection ignores the current borrower being updated

Pass `force=true` to bypass the duplicate check.
DESC,
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'first_name', type: 'string'),
                    new OA\Property(property: 'middle_name', type: 'string'),
                    new OA\Property(property: 'last_name', type: 'string'),
                    new OA\Property(property: 'suffix', type: 'string'),
                    new OA\Property(property: 'birthdate', type: 'string', format: 'date'),
                    new OA\Property(property: 'civil_status', type: 'string'),
                    new OA\Property(property: 'gender', type: 'string'),
                    new OA\Property(property: 'address', type: 'string', description: 'Legacy single-line address'),
                    new OA\Property(property: 'street_address', type: 'string'),
                    new OA\Property(property: 'barangay', type: 'string'),
                    new OA\Property(property: 'city', type: 'string'),
                    new OA\Property(property: 'province', type: 'string'),
                    new OA\Property(property: 'contact_number', type: 'string', description: 'PH mobile or international'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', description: 'Must be unique (self-ignored on update)'),
                    new OA\Property(property: 'employer_or_business', type: 'string'),
                    new OA\Property(property: 'date_hired', type: 'string', format: 'date', description: 'Employment start date (not in the future)'),
                    new OA\Property(property: 'monthly_income', type: 'number'),
                    new OA\Property(property: 'pledge_amount', type: 'number', description: 'Max 9,999,999.99'),
                    new OA\Property(property: 'spouse_first_name', type: 'string'),
                    new OA\Property(property: 'spouse_middle_name', type: 'string'),
                    new OA\Property(property: 'spouse_last_name', type: 'string'),
                    new OA\Property(property: 'spouse_contact_number', type: 'string'),
                    new OA\Property(property: 'spouse_occupation', type: 'string'),
                    new OA\Property(property: 'branch_id', type: 'integer'),
                    new OA\Property(property: 'force', type: 'boolean', description: 'Bypass duplicate-borrower check'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Borrower updated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(UpdateBorrowerRequest $request, Borrower $borrower): BorrowerResource
    {
        $borrower->update($request->safe()->except('force'));
        $borrower->load('branch');

        return new BorrowerResource($borrower);
    }

    #[OA\Delete(
        path: '/api/borrowers/{id}',
        summary: 'Delete borrower',
        description: 'Permanently delete a borrower and all related records',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Borrower deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function destroy(Borrower $borrower): JsonResponse
    {
        $this->authorize('borrowers:delete');

        DB::transaction(function () use ($borrower) {
            // Delete co-maker documents from disk
            foreach ($borrower->coMakers as $coMaker) {
                foreach ($coMaker->documents as $doc) {
                    Storage::disk('private')->delete($doc->file_path);
                }
                $coMaker->documents()->delete();
            }

            // Delete borrower documents from disk
            foreach ($borrower->documents as $doc) {
                Storage::disk('private')->delete($doc->file_path);
            }
            $borrower->documents()->delete();

            // Delete photo from disk
            if ($borrower->photo_path) {
                Storage::disk('private')->delete($borrower->photo_path);
            }

            // Delete share capital records (FK uses restrictOnDelete)
            $borrower->shareCapitalLedger()->delete();
            $borrower->shareCapitalPledge()->delete();

            $borrower->delete();
        });

        return response()->json(['message' => 'Borrower deleted successfully.']);
    }

    #[OA\Patch(
        path: '/api/borrowers/{id}/deactivate',
        summary: 'Deactivate borrower',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Borrower deactivated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function deactivate(Borrower $borrower): JsonResponse
    {
        $this->authorize('borrowers:update');

        $borrower->update(['status' => 'inactive']);

        return response()->json(['message' => 'Borrower deactivated successfully.']);
    }

    #[OA\Patch(
        path: '/api/borrowers/{id}/reactivate',
        summary: 'Reactivate borrower',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Borrower reactivated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function reactivate(Borrower $borrower): JsonResponse
    {
        $this->authorize('borrowers:update');

        $borrower->update(['status' => 'active']);

        return response()->json(['message' => 'Borrower reactivated successfully.']);
    }

    #[OA\Patch(
        path: '/api/borrowers/{id}/approve-registration',
        summary: 'Approve a pending borrower registration',
        description: <<<'DESC'
Approve a borrower whose status is `pending` (public-registration review).

- Required permission: `borrowers:approve` (distinct from `borrowers:update` so the approval action can be granted separately from generic borrower edits).
- Flips status `pending` → `active` and records `approved_by` / `approved_at`.
- Returns the updated borrower.
- The share-capital pledge and `borrower_code` are already created at submission time, so no extra setup runs here.
- Requires at least one valid ID (`type=valid_id` document) on file — returns 422 otherwise.
- Returns 422 if the borrower is not currently `pending`. Use `/reactivate` to re-activate a previously-active member — keeping the two distinct preserves the audit distinction between "approved an application" and "reactivated a member".
DESC,
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Registration approved; updated borrower returned'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Borrower is not in pending status, or has no valid ID on file'),
        ],
    )]
    public function approveRegistration(Request $request, Borrower $borrower): BorrowerResource
    {
        $this->authorize('borrowers:approve');

        if ($borrower->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'Only borrowers with a pending registration can be approved.',
            ]);
        }

        // KYC gate: a membership cannot be approved until the applicant has at
        // least one valid ID on file. The public form enforces this client-side;
        // this server check prevents an operator from bypassing it during review.
        if (! $borrower->documents()->where('type', 'valid_id')->exists()) {
            throw ValidationException::withMessages([
                'valid_id' => 'At least one valid ID must be uploaded before this registration can be approved.',
            ]);
        }

        $borrower->update([
            'status' => 'active',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        $borrower->load('branch');

        return new BorrowerResource($borrower);
    }

    #[OA\Patch(
        path: '/api/borrowers/{id}/reject',
        summary: 'Reject a pending borrower registration',
        description: <<<'DESC'
Reject a borrower whose status is `pending` **without hard-deleting the row**, preserving the audit trail.

- Required permission: `borrowers:approve` (same gate as `/approve-registration` — registration review is a single approval permission).
- Sets status `pending` → `rejected` and records `rejection_reason` / `rejected_at` / `rejected_by`.
- Deletes the share-capital pledge auto-created at submission (a rejected applicant is not a member).
- Returns the updated borrower.
- Returns 422 if the borrower is not currently `pending`, or if `reason` is missing.
DESC,
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', example: 'Submitted ID does not match the applicant name.', description: 'Why the registration was rejected (stored on the borrower).'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Registration rejected; updated borrower returned'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Borrower is not in pending status, or reason is missing'),
        ],
    )]
    public function reject(RejectBorrowerRequest $request, Borrower $borrower): BorrowerResource
    {
        if ($borrower->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'Only borrowers with a pending registration can be rejected.',
            ]);
        }

        $borrower->update([
            'status' => 'rejected',
            'rejection_reason' => $request->validated('reason'),
            'rejected_by' => $request->user()->id,
            'rejected_at' => now(),
        ]);

        // A rejected applicant is not a member: remove the share-capital pledge
        // auto-created at submission so it doesn't linger in share-capital totals.
        $borrower->shareCapitalPledge()->delete();

        $borrower->load('branch');

        return new BorrowerResource($borrower);
    }

    #[OA\Patch(
        path: '/api/borrowers/bulk-deactivate',
        summary: 'Bulk deactivate borrowers',
        description: 'Marks the given borrower IDs as inactive in a single request. Returns per-id success/failure so the frontend can report partial results.',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['ids'],
                properties: [
                    new OA\Property(property: 'ids', type: 'array', items: new OA\Items(type: 'integer'), example: [1, 2, 3]),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Bulk result'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function bulkDeactivate(): JsonResponse
    {
        $this->authorize('borrowers:update');

        $validated = request()->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'exists:borrowers,id'],
        ]);

        $deactivated = [];
        $failed = [];

        foreach ($validated['ids'] as $id) {
            try {
                /** @var Borrower $borrower */
                $borrower = Borrower::findOrFail($id);
                $borrower->update(['status' => 'inactive']);
                $deactivated[] = $id;
            } catch (\Throwable $e) {
                $failed[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        return response()->json([
            'message' => 'Bulk deactivation complete.',
            'deactivated' => $deactivated,
            'failed' => $failed,
        ]);
    }

    #[OA\Delete(
        path: '/api/borrowers/bulk',
        summary: 'Bulk delete borrowers',
        description: 'Deletes the given borrower IDs in a single request. Each delete runs in its own transaction (photo, documents, pledge, and ledger are removed). Returns per-id success/failure.',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['ids'],
                properties: [
                    new OA\Property(property: 'ids', type: 'array', items: new OA\Items(type: 'integer'), example: [1, 2, 3]),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Bulk result'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function bulkDestroy(): JsonResponse
    {
        $this->authorize('borrowers:delete');

        $validated = request()->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'exists:borrowers,id'],
        ]);

        $deleted = [];
        $failed = [];

        foreach ($validated['ids'] as $id) {
            try {
                $borrower = Borrower::findOrFail($id);

                DB::transaction(function () use ($borrower) {
                    foreach ($borrower->coMakers as $coMaker) {
                        foreach ($coMaker->documents as $doc) {
                            Storage::disk('private')->delete($doc->file_path);
                        }
                        $coMaker->documents()->delete();
                    }
                    foreach ($borrower->documents as $doc) {
                        Storage::disk('private')->delete($doc->file_path);
                    }
                    $borrower->documents()->delete();

                    if ($borrower->photo_path) {
                        Storage::disk('private')->delete($borrower->photo_path);
                    }

                    $borrower->shareCapitalLedger()->delete();
                    $borrower->shareCapitalPledge()->delete();
                    $borrower->delete();
                });

                $deleted[] = $id;
            } catch (\Throwable $e) {
                $failed[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        return response()->json([
            'message' => 'Bulk delete complete.',
            'deleted' => $deleted,
            'failed' => $failed,
        ]);
    }

    #[OA\Post(
        path: '/api/borrowers/{id}/photo',
        summary: 'Upload borrower photo',
        description: 'Accepts a Bearer token (operator flow) or an `X-Submission-Token` header (public registration). When both are present, the auth header wins. Token must be bound to this borrower id and unexpired.',
        tags: ['Borrowers'],
        security: [['sanctum' => []], []],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'X-Submission-Token', in: 'header', required: false, description: 'Submission token from POST /api/borrowers (public registration fallback)', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['photo'],
                    properties: [
                        new OA\Property(property: 'photo', type: 'string', format: 'binary'),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Photo uploaded'),
            new OA\Response(response: 401, description: 'Unauthenticated — no Bearer token, no/invalid submission token, or token bound to a different borrower'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function uploadPhoto(Request $request, Borrower $borrower): JsonResponse
    {
        // When the AllowAuthOrSubmissionToken middleware has flagged the request
        // as token-authenticated, the per-user permission check is skipped —
        // the token itself is the authorization (and the middleware already
        // verified the token is bound to *this* borrower).
        if (! $request->attributes->get('submission_token_authenticated')) {
            $this->authorize('borrowers:update');
        }

        $request->validate([
            'photo' => ['required', 'image', 'max:5120'],
        ]);

        // Store the new photo BEFORE touching the old one so a failed upload
        // cannot leave the borrower without a photo.
        $oldPath = $borrower->photo_path;
        $newPath = $request->file('photo')->store("borrowers/photos/{$borrower->id}", 'private');
        $borrower->update(['photo_path' => $newPath]);

        if ($oldPath) {
            Storage::disk('private')->delete($oldPath);
        }

        return response()->json([
            'message' => 'Photo uploaded successfully.',
            'photo_url' => $borrower->fresh()->photo_url,
        ]);
    }

    #[OA\Delete(
        path: '/api/borrowers/{id}/photo',
        summary: 'Delete borrower photo',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Photo deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function deletePhoto(Borrower $borrower): JsonResponse
    {
        $this->authorize('borrowers:update');

        if ($borrower->photo_path) {
            Storage::disk('private')->delete($borrower->photo_path);
            $borrower->update(['photo_path' => null]);
        }

        return response()->json(['message' => 'Photo deleted successfully.']);
    }

    #[OA\Post(
        path: '/api/borrowers/{id}/valid-ids',
        summary: 'Upload borrower valid ID',
        description: 'Accepts either single `file` (legacy) or `front_file`+`back_file` (new) with optional `id_number`. Authenticates via Bearer token (operator flow) or `X-Submission-Token` header (public registration). When both are present the Bearer wins. Token must be bound to this borrower id and unexpired.',
        tags: ['Borrowers'],
        security: [['sanctum' => []], []],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'X-Submission-Token', in: 'header', required: false, description: 'Submission token from POST /api/borrowers (public registration fallback)', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['type'],
                    properties: [
                        new OA\Property(property: 'type', type: 'string', example: 'philippine_id'),
                        new OA\Property(property: 'custom_type_name', type: 'string', nullable: true, description: 'Required when type is "others"', example: 'Company HR ID'),
                        new OA\Property(property: 'id_number', type: 'string', nullable: true, example: 'N01-23-456789'),
                        new OA\Property(property: 'front_file', type: 'string', format: 'binary'),
                        new OA\Property(property: 'back_file', type: 'string', format: 'binary', nullable: true),
                        new OA\Property(property: 'file', type: 'string', format: 'binary', description: 'Legacy single-file upload'),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Valid ID(s) uploaded'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function uploadValidId(Request $request, Borrower $borrower): JsonResponse
    {
        if (! $request->attributes->get('submission_token_authenticated')) {
            $this->authorize('borrowers:update');
        }

        // Mutual exclusion: legacy single-file shape vs new front/back shape
        if ($request->hasFile('file') && $request->hasFile('front_file')) {
            throw ValidationException::withMessages([
                'file' => 'Use either `file` (legacy) or `front_file`/`back_file`, not both.',
            ]);
        }

        $rules = [
            'type' => ['required', 'string', 'max:100'],
            'custom_type_name' => ['nullable', 'string', 'max:100', 'required_if:type,others'],
            'id_number' => ['nullable', 'string', 'max:100'],
            'file' => ['required_without:front_file', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf'],
            'front_file' => ['required_without:file', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf'],
            'back_file' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf'],
        ];

        $request->validate($rules);

        $type = $request->input('type');
        $customTypeName = $request->input('custom_type_name');
        $idNumber = $request->input('id_number');
        $isLegacy = $request->hasFile('file');
        $storedPaths = [];
        $documents = [];

        try {
            DB::transaction(function () use ($borrower, $request, $type, $customTypeName, $idNumber, $isLegacy, &$storedPaths, &$documents) {
                if ($isLegacy) {
                    $documents[] = $this->storeValidIdFile($borrower, $request->file('file'), $type, $customTypeName, $idNumber, null, $storedPaths);
                } else {
                    $documents[] = $this->storeValidIdFile($borrower, $request->file('front_file'), $type, $customTypeName, $idNumber, 'front', $storedPaths);
                    if ($request->hasFile('back_file')) {
                        $documents[] = $this->storeValidIdFile($borrower, $request->file('back_file'), $type, $customTypeName, $idNumber, 'back', $storedPaths);
                    }
                }
            });
        } catch (\Throwable $e) {
            // Roll back any files written to disk before the DB failure
            foreach ($storedPaths as $path) {
                Storage::disk('private')->delete($path);
            }
            throw $e;
        }

        if ($isLegacy) {
            return (new DocumentResource($documents[0]))
                ->response()
                ->setStatusCode(201);
        }

        return DocumentResource::collection($documents)
            ->response()
            ->setStatusCode(201);
    }

    private function storeValidIdFile(Borrower $borrower, UploadedFile $file, string $type, ?string $customTypeName, ?string $idNumber, ?string $side, array &$storedPaths): Document
    {
        $path = $file->store("documents/valid_id/borrower/{$borrower->id}", 'private');
        $storedPaths[] = $path;

        return $borrower->documents()->create([
            'type' => 'valid_id',
            'label' => $type,
            'custom_type_name' => $customTypeName,
            'id_number' => $idNumber,
            'side' => $side,
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
        ]);
    }

    #[OA\Get(
        path: '/api/borrowers/{id}/valid-ids',
        summary: 'List borrower valid IDs',
        description: 'Returns valid IDs grouped as front/back pairs. Documents sharing the same (label, id_number) are paired into a single entry. The entry "id" is the front document id and is the value to use for DELETE.',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Valid ID list',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'type', type: 'string', example: 'philippine_id'),
                                    new OA\Property(property: 'custom_type_name', type: 'string', nullable: true, example: null),
                                    new OA\Property(property: 'id_number', type: 'string', nullable: true, example: '1234-5678-9012'),
                                    new OA\Property(property: 'front_url', type: 'string', example: '/storage/documents/valid_id/borrower/1/front-xxx.jpg'),
                                    new OA\Property(property: 'back_url', type: 'string', nullable: true, example: '/storage/documents/valid_id/borrower/1/back-xxx.jpg'),
                                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                                ],
                            ),
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function listValidIds(Borrower $borrower): JsonResponse
    {
        $this->authorize('borrowers:view');

        $documents = $borrower->documents()
            ->where('type', 'valid_id')
            ->orderBy('id')
            ->get();

        $groups = $documents->groupBy(fn (Document $doc) => $doc->label.'|'.($doc->id_number ?? ''));

        $validIds = $groups->map(function ($group) {
            $front = $group->firstWhere('side', 'front')
                ?? $group->firstWhere('side', null)
                ?? $group->first();
            $back = $group->firstWhere('side', 'back');

            return [
                'id' => $front->id,
                'type' => $front->label,
                'custom_type_name' => $front->custom_type_name,
                'id_number' => $front->id_number,
                'front_url' => $front->url,
                'back_url' => $back?->url,
                'created_at' => $front->created_at,
            ];
        })->values();

        return response()->json(['data' => $validIds]);
    }

    #[OA\Delete(
        path: '/api/borrowers/{id}/valid-ids/{validIdId}',
        summary: 'Delete a borrower valid ID',
        description: 'Deletes the valid ID group identified by the front document id. Removes both the front and back documents (if present) sharing the same (label, id_number).',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'validIdId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Valid ID deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function deleteValidId(Borrower $borrower, int $validIdId): JsonResponse
    {
        $this->authorize('borrowers:delete');

        $anchor = Document::where('id', $validIdId)
            ->where('documentable_type', Borrower::class)
            ->where('documentable_id', $borrower->id)
            ->where('type', 'valid_id')
            ->firstOrFail();

        $pair = Document::where('documentable_type', Borrower::class)
            ->where('documentable_id', $borrower->id)
            ->where('type', 'valid_id')
            ->where('label', $anchor->label)
            ->where(function ($q) use ($anchor) {
                if ($anchor->id_number === null) {
                    $q->whereNull('id_number');
                } else {
                    $q->where('id_number', $anchor->id_number);
                }
            })
            ->get();

        DB::transaction(function () use ($pair) {
            foreach ($pair as $doc) {
                Storage::disk('private')->delete($doc->file_path);
                $doc->delete();
            }
        });

        return response()->json(['message' => 'Valid ID deleted successfully.']);
    }

    #[OA\Get(
        path: '/api/borrowers/{id}/ledger',
        summary: 'Borrower transactional ledger',
        description: 'Returns chronological ledger entries (loan releases as debits, repayments as credits) with running balance.',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Ledger entries with running balance'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Borrower not found'),
        ],
    )]
    public function ledger(Borrower $borrower): JsonResponse
    {
        $this->authorize('borrowers:view');

        // Build chronological list of loan releases (debits) and repayments (credits)
        $entries = collect();

        $loans = $borrower->loans()
            ->with(['repayments' => fn ($q) => $q->where('status', 'posted')])
            ->whereIn('status', ['released', 'ongoing', 'completed'])
            ->whereNotNull('released_at')
            ->get();

        foreach ($loans as $loan) {
            // Loan release as debit entry
            $entries->push([
                'date' => $loan->released_at?->toDateString(),
                'sort_key' => $loan->released_at?->toDateTimeString().'-0-'.$loan->id,
                'description' => 'Loan released'.($loan->purpose ? ' — '.$loan->purpose : ''),
                'reference' => $loan->loan_account_number ?? $loan->application_number,
                'debit' => (float) $loan->principal_amount,
                'credit' => 0.0,
            ]);

            // Each repayment as credit entry
            foreach ($loan->repayments as $repayment) {
                $entries->push([
                    'date' => $repayment->payment_date?->toDateString(),
                    'sort_key' => $repayment->payment_date?->toDateTimeString().'-1-'.$repayment->id,
                    'description' => 'Payment received'.($repayment->method ? ' via '.ucwords(str_replace('_', ' ', $repayment->method)) : ''),
                    'reference' => $repayment->receipt_number,
                    'debit' => 0.0,
                    'credit' => (float) $repayment->amount_paid,
                ]);
            }
        }

        // Sort chronologically and compute running balance
        $sorted = $entries->sortBy('sort_key')->values();
        $runningBalance = 0.0;
        $withBalance = $sorted->map(function ($entry, $index) use (&$runningBalance) {
            $runningBalance += $entry['debit'] - $entry['credit'];

            return [
                'id' => $index + 1,
                'date' => $entry['date'],
                'description' => $entry['description'],
                'reference' => $entry['reference'],
                'debit' => round($entry['debit'], 2),
                'credit' => round($entry['credit'], 2),
                'balance' => round($runningBalance, 2),
            ];
        });

        return response()->json(['data' => $withBalance->toArray()]);
    }
}
