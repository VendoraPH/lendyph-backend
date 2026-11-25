<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Traits\RespondsWithValidIds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Borrower\ApproveRegistrationRequest;
use App\Http\Requests\Borrower\RejectBorrowerRequest;
use App\Http\Requests\Borrower\StoreBorrowerRequest;
use App\Http\Requests\Borrower\UpdateBorrowerRequest;
use App\Http\Requests\Document\UploadFileTypes;
use App\Http\Resources\BorrowerResource;
use App\Http\Resources\PossibleDuplicatePresenter;
use App\Models\Borrower;
use App\Services\BorrowerDuplicateDetector;
use App\Services\BorrowerLoanSummaryService;
use App\Services\BorrowerPurgeService;
use App\Services\BorrowerRegistration;
use App\Services\BorrowerSubmissionTokenService;
use App\Services\BranchAccess;
use App\Services\Cda\MembershipStatus;
use App\Services\Diagnostics\ErrorDigest;
use App\Services\MemberLedgerService;
use App\Services\RegistrationReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class BorrowerController extends Controller
{
    use RespondsWithValidIds;

    /**
     * A registration still waiting on, or turned down at, Registration Review.
     * Only approval makes it a member (borrowers:approve, a valid ID and a
     * branch), so deactivate and reactivate leave it alone (Phase 6 D7).
     */
    private const UNREVIEWED_STATUSES = ['pending', 'rejected'];

    private const NOT_DEACTIVATABLE = 'This registration hasn\'t been approved, so it can\'t be deactivated. Approve or reject it from Registration Review.';

    #[OA\Get(
        path: '/api/borrowers',
        summary: 'List borrowers',
        description: <<<'DESC'
Get a paginated list of borrowers with search and filters.

`search` matches borrower code, any name part, contact number or email.

`members_only=1` returns everyone who is a member of the cooperative — every
status except `pending` and `rejected`. Use it for the Members screen instead
of `status`, which only takes one exact value at a time. `inactive` and
`blacklisted` members are included: they are members in poor standing, not
non-members.

The list and `meta.stats` cover only borrowers in the caller's branches (all
branches with `branches:view_all`; a borrower with no branch only with it). A
`branch_id` outside the caller's branches answers 403.

`meta.stats` follows `branch_id` and `search`, and deliberately ignores
`status` and `members_only`.

It follows `search` so the tab totals describe the same borrowers the paginator
is showing — otherwise searching for one member left the Active tab still
claiming the whole branch.

It ignores `status` because each tab needs its own count: scoping by the
selected status would leave every other tab reading zero and the user could
never leave the tab they were on.

It ignores `members_only` because `stats.pending` is the source for the
pending-registrations badge, and the Members screen sends `members_only=1`.
Scoping by it would zero that badge exactly where it is read.
DESC,
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'inactive', 'blacklisted', 'pending', 'rejected', 'withdrawn', 'deceased'])),
            new OA\Parameter(name: 'members_only', in: 'query', required: false, description: 'Exclude pending and rejected registrations.', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15, maximum: 100)),
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

        $filters = request()->validate([
            'search' => ['nullable', 'string'],
            // Deliberately NOT constrained to the status enum. A client still
            // sending a retired value should get an empty page, not a 422 that
            // takes the whole screen down — the same call Loan::scopeForStatus()
            // documents.
            'status' => ['nullable', 'string'],
            // `min:1` is not cosmetic: 0 is a valid integer that no row can
            // carry, and it used to reach a `when()` that treats it as absent
            // — see the filled() note below.
            'branch_id' => ['nullable', 'integer', 'min:1'],
            // Also load-bearing, and measured rather than assumed:
            // `?per_page=-5` reached the database as a negative LIMIT and was a
            // 500, and `?per_page=0` was silently ignored — Builder::paginate()
            // does `$perPage ?: $model->getPerPage()`, so 0 quietly became 15
            // and the caller was never told. There was no lower clamp here, only
            // the upper `min(..., 100)`.
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        /**
         * Pulled out as locals so every filter below can be gated on
         * filled() — PRESENCE — rather than on truthiness.
         *
         * `Builder::when()` skips its callback for any falsy condition, and
         * `0` and `'0'` are falsy. Gating on the value itself therefore dropped
         * `?branch_id=0` on the floor and answered with every borrower in the
         * cooperative — names, contact numbers, emails — plus organisation-wide
         * `meta.stats`, for a caller who had asked about one branch.
         * `/borrowers/0` on the frontend does exactly that, via
         * Number(params.id). The same hole swallowed `search=0` and
         * `status=0`, which are ordinary values a user can type.
         *
         * `members_only` is the one input that must NOT move to filled():
         * `members_only=0` means "do not apply the members filter", so its
         * falsiness IS the answer rather than a bug, and filter_var() is what
         * turns `0`, `false` and `off` into that answer.
         */
        $search = $filters['search'] ?? null;
        $status = $filters['status'] ?? null;
        $branchId = $filters['branch_id'] ?? null;
        $membersOnly = filter_var(request('members_only'), FILTER_VALIDATE_BOOLEAN);

        $viewer = request()->user();
        app(BranchAccess::class)->authorizeBranchFilter($viewer, $branchId);

        // Eager loaded, never lazily: the reviewer relations cost two queries
        // for the whole page rather than two per row. Both point at `users`, so
        // a page of rows all reviewed by the same admin still costs exactly two.
        $borrowers = Borrower::with('branch', 'approvedBy', 'rejectedBy')
            // Only the caller's branches (BranchAccess); `meta.stats` below
            // takes the same scope.
            ->visibleTo($viewer)
            /*
             * One aggregate subquery for the page, not a documents relation per
             * row. It answers BorrowerResource's `has_valid_id`, which pairs
             * with `is_imported` to mark the members a CSV migration created
             * with no KYC on file — a backlog an operator can only work if the
             * list can show it. Loading `documents` instead would pull every
             * KYC row and its signed-URL machinery for 100 members to answer a
             * yes/no question.
             */
            ->withCount(['documents as valid_id_documents_count' => fn ($q) => $q->where('type', 'valid_id')])
            ->when(filled($search), fn ($q) => $q->search($search))
            ->when(filled($status), fn ($q) => $q->where('status', $status))
            ->when($membersOnly, fn ($q) => $q->members())
            ->when(filled($branchId), fn ($q) => $q->forBranch($branchId))
            ->latest()
            // Tiebreak on the key: members written by one CSV import share a
            // `created_at`, and a partial order lets the drained pickers serve
            // one twice and another never. See DeterministicPaginationTest.
            ->orderByDesc('id')
            ->paginate(min(max((int) ($filters['per_page'] ?? 15), 1), 100));

        /**
         * Status counts so the frontend can render the status tabs without a
         * second request.
         *
         * Scoped by `branch_id` and `search`. NOT by `status`, and NOT by
         * `members_only`. Those last two are both excluded, but for unrelated
         * reasons, and the reasons are what matter if you are deciding whether
         * to change this:
         *
         *  - `search` is now included. Without it the tab totals contradicted
         *    the paginator directly underneath them: searching for one member
         *    left the Active tab still claiming the whole branch.
         *  - `status` is excluded because each tab needs its own count. Scoping
         *    by the selected status would leave every other tab reading zero,
         *    and the user could never leave the tab they were on.
         *  - `members_only` is excluded because `stats.pending` is what feeds
         *    the pending-registrations badge, and the Members screen is exactly
         *    the screen that sends `members_only=1`. Scoping by it would zero
         *    that badge at the one place it is read. This is pinned by
         *    FrontendApiNeedsTest's "keeps meta.stats global while members_only
         *    narrows the page" — do not fold it in without moving the badge to
         *    its own request first.
         *
         * The cost of that last exclusion, stated plainly: under
         * `members_only=1` the tabs do not sum to `meta.total`, because stats
         * still counts the pending and rejected rows the page excludes.
         */
        $stats = Borrower::statusCounts($viewer, filled($branchId) ? (int) $branchId : null, filled($search) ? (string) $search : null);

        return BorrowerResource::collection($borrowers)
            ->additional(['meta' => ['stats' => $stats]]);
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
- `email` must be unique across all borrowers on a staff create (a public registration's email is never refused; see below)
- `pledge_amount` max `9,999,999.99`
- `birthdate` must be after 1900-01-01 and before today

**Duplicate detection:** a borrower matches when one with a similar name already exists:
1. **Exact match** — same normalized first+middle+last (case/whitespace insensitive)
2. **Fuzzy match** — Levenshtein distance ≤ 2 on normalized full name, *and* birthdate matches

A signed-in (staff) create that matches is rejected with 422. A signed-in user holding `borrowers:create` may pass `force=true` in the body to bypass the duplicate check (frontend "Create Anyway" flow).
On a duplicate rejection, the error message in `errors.first_name[0]` contains the matched borrower's code and DOB when the caller can see that borrower's branch, and a generic message otherwise.

An anonymous (public) registration is **never** rejected as a duplicate or for a taken email, and its `force` is ignored: it is always checked, and a match (by name as above, or else by the same email, case and spaces ignored) is saved pending with a server-set link to the matched record (`possible_duplicate` on GET /borrowers/{id}, shown only to a reviewer who can see that record). The answer is the same 201 whether or not it matched.

**Idempotency (public registration):** an anonymous caller may send a client-generated `registration_uuid`
(UUID **v4**, e.g. the browser's `crypto.randomUUID()`). A retry is honoured — same 201 shape, same borrower
id, a freshly issued token, no second row — only when the key matches a **still-pending** submission created
inside the 15-minute submission-token window **and** the retry's `first_name`, `last_name` and `birthdate`
describe the same person that submission did.

Both conditions are security boundaries, not tidiness. The response hands out a submission token, which grants
write access to that borrower's photo and valid-ID uploads, so a leaked key must buy no more than a leaked
token does — and must be useless without the applicant's own identity alongside it. A key failing either
condition is silently discarded and the request is processed as a brand-new registration, which is
indistinguishable from sending a key nobody has used; the endpoint never confirms that a given key exists.

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
                    new OA\Property(property: 'registration_uuid', type: 'string', format: 'uuid', example: '9f1c1a5e-6f2a-4a2e-9f1c-1a5e6f2a4a2e', description: 'Client-generated idempotency key (UUID v4) for the public registration form. Resending the same key inside the 15-minute submission window, with the same first name, last name and birthdate, returns the borrower the first attempt created instead of a duplicate.'),
                    new OA\Property(property: 'force', type: 'boolean', example: false, description: 'Bypass the duplicate-borrower check (use for "Create Anyway" confirmation). Staff with borrowers:create only; ignored for anonymous callers.'),
                    new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive', 'blacklisted', 'pending'], description: 'Anonymous (public registration) requests must set this to "pending".'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Borrower created (authenticated: full BorrowerResource; anonymous: { id, submission_token, expires_at })'),
            new OA\Response(response: 401, description: 'Anonymous request with status other than `pending`'),
            new OA\Response(response: 409, description: 'Kept colliding with other saves taking the next member code. Nothing was saved; submit again.'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(
                response: 422,
                description: 'Validation error. On duplicate, errors.first_name[0] includes the matched borrower code/name/DOB.',
                content: new OA\JsonContent(
                    example: [
                        'message' => 'The first name field has duplicate match.',
                        'errors' => [
                            'first_name' => [
                                'A similar member already exists: Juan Dela Cruz (BRW-000042, born 1990-01-15). Pass force=true to create anyway.',
                            ],
                        ],
                    ],
                ),
            ),
        ],
    )]
    public function store(StoreBorrowerRequest $request, BorrowerSubmissionTokenService $tokens): JsonResponse
    {
        /**
         * A retry of a submission whose response was lost has to resolve to the
         * row the first attempt already created, not to a second applicant. An
         * applicant on a Facebook in-app browser hit a 30-second timeout, never
         * saw the 201, and their retry was refused as a duplicate of their own
         * record. The frontend's cache cannot help — it only fills in once the
         * client has actually seen a response — so the key travels with the
         * request instead.
         *
         * StoreBorrowerRequest::existingRegistration() holds the four
         * conditions a key must satisfy before it is honoured.
         */
        $borrower = $request->existingRegistration() ?? $this->createBorrower($request);

        // Public registration path — return a slim payload plus a submission
        // token the anonymous client uses to attach photo + valid IDs against
        // the borrower row it just created.
        if (! $request->user()) {
            $token = $tokens->issue($borrower, $request->ip());

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $borrower->id,
                    'submission_token' => $token->plainTextToken,
                    'expires_at' => $token->expires_at->toIso8601String(),
                ],
            ], 201);
        }

        $borrower->load('branch', 'approvedBy', 'rejectedBy');

        return (new BorrowerResource($borrower))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Inserts the borrower through BorrowerRegistration::createBorrower(),
     * the one member create, which the online application hand-off uses too.
     */
    private function createBorrower(StoreBorrowerRequest $request): Borrower
    {
        return app(BorrowerRegistration::class)->createBorrower($request);
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

        // `possibleDuplicateOf`: the registration review reads the link here.
        $borrower->load('branch', 'coMakers.documents', 'documents', 'approvedBy', 'rejectedBy', 'possibleDuplicateOf');

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
        $borrower->load('branch', 'approvedBy', 'rejectedBy');

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
    public function destroy(Borrower $borrower, BorrowerPurgeService $purge): JsonResponse
    {
        $this->authorize('borrowers:delete');

        $purge->purge($borrower);

        return response()->json(['message' => 'Member deleted successfully.']);
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
            new OA\Response(response: 422, description: 'A pending or rejected registration (approve or reject it from Registration Review), or a former member'),
        ],
    )]
    public function deactivate(Borrower $borrower): JsonResponse
    {
        $this->authorize('borrowers:update');

        // Deactivating a registration would let /reactivate make it a member
        // without Registration Review (Phase 6 D7).
        if (in_array($borrower->status, self::UNREVIEWED_STATUSES, true)) {
            throw ValidationException::withMessages(['status' => self::NOT_DEACTIVATABLE]);
        }

        if ($borrower->isFormerMember()) {
            throw ValidationException::withMessages(['status' => MembershipStatus::endedMessage($borrower)]);
        }

        $borrower->update(['status' => 'inactive']);

        return response()->json(['message' => 'Member deactivated successfully.']);
    }

    #[OA\Patch(
        path: '/api/borrowers/{id}/reactivate',
        summary: 'Reactivate borrower',
        description: 'A withdrawn or deceased member (their membership ended) needs a `reason`; it is kept on the audit row, and the end date and reason are cleared. Any other member reactivates without one.',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'reason', type: 'string', maxLength: 255, description: 'Required when the membership ended'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Borrower reactivated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'A former member without a reason, or a pending or rejected registration (approve it from Registration Review)'),
        ],
    )]
    public function reactivate(Request $request, Borrower $borrower, MembershipStatus $membership): JsonResponse
    {
        $this->authorize('borrowers:update');

        // A registration becomes a member only through Registration Review,
        // which needs borrowers:approve, a valid ID and a branch (Phase 6 D7).
        if (in_array($borrower->status, self::UNREVIEWED_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'This registration hasn\'t been approved. Approve it from Registration Review.',
            ]);
        }

        if ($borrower->isFormerMember()) {
            $membership->reactivate($borrower, (string) $request->validate([
                'reason' => ['required', 'string', 'max:255'],
            ], [
                'reason.required' => 'Give a reason to reactivate a withdrawn or deceased member.',
            ])['reason']);

            return response()->json(['message' => 'Member reactivated successfully.']);
        }

        $borrower->update(['status' => 'active']);

        return response()->json(['message' => 'Member reactivated successfully.']);
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
- `branch_id` (optional): the member's branch, one of the caller's (any branch with `branches:view_all`). Required (422) when the registration has no branch; when it already has one, it may only repeat it (422 otherwise), so approval never moves a member.
- Returns 422 on `email` while the registration's email (case and spaces ignored) is on another member, whatever is acknowledged: change or clear it with PUT /borrowers/{id} first. It names that member only to a caller who can see them.
- `acknowledge_possible_duplicate` (boolean): required as `true` when the registration was saved as a possible duplicate of a record on file (its `possible_duplicate`); otherwise 422 on `possible_duplicate`, in words, once every other check passes. It names the record only to a caller who can see it.
- Returns 409, in words, when another write got there first: the registration was approved or rejected, or its branch changed, at the same moment, or a member was saved with its email. Reload and try again.
DESC,
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'branch_id', type: 'integer', nullable: true, description: 'The member\'s branch, one of the caller\'s. Required when the registration has none; otherwise it must repeat the registration\'s branch.'),
                    new OA\Property(property: 'acknowledge_possible_duplicate', type: 'boolean', example: true, description: 'The reviewer checked the record this registration may duplicate. Required as true when it has one.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Registration approved; updated borrower returned'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found, or in a branch the caller can\'t see'),
            new OA\Response(response: 422, description: 'Borrower is not in pending status, has no valid ID on file, has no branch and none was chosen, the chosen branch isn\'t the caller\'s or differs from the registration\'s, its email is on another member, or it may be a duplicate and `acknowledge_possible_duplicate` wasn\'t true'),
            new OA\Response(response: 409, description: 'Another write got there first: the registration was approved, rejected or moved to another branch at the same moment, or a member was saved with its email'),
        ],
    )]
    public function approveRegistration(ApproveRegistrationRequest $request, Borrower $borrower): BorrowerResource
    {
        if ($borrower->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'Only members with a pending registration can be approved.',
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

        // Phase 6 FX-1 (D7): a member always leaves review with a branch. A
        // registration with none takes the reviewer's pick; one that has a
        // branch keeps it, since approval is not a member move.
        $branchId = $request->validated('branch_id');

        if ($borrower->branch_id === null && $branchId === null) {
            throw ValidationException::withMessages([
                'branch_id' => 'Choose the branch this member belongs to before approving the registration.',
            ]);
        }

        if ($borrower->branch_id !== null && $branchId !== null && (int) $branchId !== (int) $borrower->branch_id) {
            throw ValidationException::withMessages([
                'branch_id' => 'This registration already belongs to '.($borrower->branch?->name ?? 'another branch').'. Approve it there; moving a member is done from the member\'s record.',
            ]);
        }

        // A member's email is theirs alone, as a staff create refuses a taken
        // one, and the public form no longer refuses it (finish run round 2,
        // decision 3): a registration whose email is on another member waits
        // until it's changed or cleared, whatever the reviewer confirms.
        $emailHolder = app(BorrowerDuplicateDetector::class)->memberWithEmail($borrower->email, $borrower->id);

        if ($emailHolder !== null) {
            throw ValidationException::withMessages([
                'email' => PossibleDuplicatePresenter::emailOnFileRefusal($emailHolder, $request->user()),
            ]);
        }

        // A public registration saved as a possible duplicate is approved only
        // once the reviewer confirms they checked it. Asked last, so it never
        // hides a check that would fail.
        if ($borrower->possible_duplicate_of_id !== null && ! $request->boolean('acknowledge_possible_duplicate')) {
            throw ValidationException::withMessages([
                'possible_duplicate' => PossibleDuplicatePresenter::approvalRefusal($borrower, $request->user()),
            ]);
        }

        // Written under the registration's lock and the member duplicate
        // check's, where the status, branch and email are checked again
        // (finish run round 4): 409 when another write got there first.
        app(RegistrationReview::class)->approve($borrower, $branchId === null ? null : (int) $branchId, $request->user());

        $borrower->load('branch', 'approvedBy', 'rejectedBy');

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
- Returns 409, in words, when another write got there first: the registration was approved or rejected, or its branch changed, at the same moment. Nothing is written, so an approved member is never rejected and keeps their pledge.
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
            new OA\Response(response: 409, description: 'Another write got there first: the registration was approved, rejected or moved to another branch at the same moment'),
        ],
    )]
    public function reject(RejectBorrowerRequest $request, Borrower $borrower): BorrowerResource
    {
        if ($borrower->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'Only members with a pending registration can be rejected.',
            ]);
        }

        // Written under the registration's lock, the one an approval takes
        // first, where the status and branch are checked again (finish run
        // round 4): 409 when another write got there first.
        app(RegistrationReview::class)->reject($borrower, (string) $request->validated('reason'), $request->user());

        $borrower->load('branch', 'approvedBy', 'rejectedBy');

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
            new OA\Response(response: 200, description: 'Bulk result. A pending or rejected registration, or a former member, is listed under `failed` with the reason in words and left unchanged.'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function bulkDeactivate(): JsonResponse
    {
        $this->authorize('borrowers:update');

        $validated = request()->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', app(BranchAccess::class)->constrainExists(Rule::exists('borrowers', 'id'), request()->user())],
        ]);

        $deactivated = [];
        $failed = [];

        foreach ($validated['ids'] as $id) {
            try {
                /** @var Borrower $borrower */
                $borrower = Borrower::visibleTo(request()->user())->findOrFail($id);

                if (in_array($borrower->status, self::UNREVIEWED_STATUSES, true)) {
                    $failed[] = ['id' => $id, 'reason' => self::NOT_DEACTIVATABLE];

                    continue;
                }

                if ($borrower->isFormerMember()) {
                    $failed[] = ['id' => $id, 'reason' => MembershipStatus::endedMessage($borrower)];

                    continue;
                }

                $borrower->update(['status' => 'inactive']);
                $deactivated[] = $id;
            } catch (\Throwable $e) {
                /*
                 * Fixed prose, never $e->getMessage(). The update's own SQL
                 * names one harmless column, but the Auditable trait's
                 * `updated` hook fires a second INSERT that copies the
                 * borrower's FULL attribute set into `audit_logs.old_values` —
                 * name, birthdate, address, contact number, income. A
                 * QueryException's message is the failing SQL with the bindings
                 * substituted in, so when it is that insert which fails, the
                 * message is the member's entire record — and this array is
                 * returned in the HTTP response body. See ErrorDigest.
                 */
                $failed[] = [
                    'id' => $id,
                    'reason' => ErrorDigest::forSubject(
                        $e, "Member {$id}", 'deactivated', 'See the application log.'
                    ),
                ];

                Log::error('borrowers: bulk deactivate failed for one borrower', [
                    'borrower_id' => $id,
                ] + ErrorDigest::context($e));

                ErrorDigest::recordDiagnostics($e, [
                    'source' => 'borrowers.bulk-deactivate',
                    'borrower_id' => $id,
                ], flag: ErrorDigest::BORROWER_DIAGNOSTICS_FLAG);
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
    public function bulkDestroy(BorrowerPurgeService $purge): JsonResponse
    {
        $this->authorize('borrowers:delete');

        $validated = request()->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', app(BranchAccess::class)->constrainExists(Rule::exists('borrowers', 'id'), request()->user())],
        ]);

        $deleted = [];
        $failed = [];

        foreach ($validated['ids'] as $id) {
            try {
                $purge->purge(Borrower::visibleTo(request()->user())->findOrFail($id));

                $deleted[] = $id;
            } catch (\Throwable $e) {
                /*
                 * Fixed prose, never $e->getMessage() — and the stakes here are
                 * higher than on a log leak, because this array is returned in
                 * the HTTP response body.
                 *
                 * Two ways the message carries the member. The Auditable trait's
                 * `deleted` hook copies the borrower's FULL attribute set into
                 * `audit_logs.old_values`, so a failure of THAT insert is a
                 * QueryException whose SQL — bindings substituted in — is the
                 * whole record. And a purge fails routinely: `loans.borrower_id`
                 * is restrictOnDelete, so any member with a loan raises 1451,
                 * which is the common case rather than the exotic one.
                 *
                 * The code is the one variable part, and the operator needs it:
                 * 1451 means "this member still has a loan", which is theirs to
                 * act on, while 1205 means "retry". See ErrorDigest.
                 */
                $failed[] = [
                    'id' => $id,
                    'reason' => ErrorDigest::forSubject(
                        $e, "Member {$id}", 'deleted', 'See the application log.'
                    ),
                ];

                Log::error('borrowers: bulk delete failed for one borrower', [
                    'borrower_id' => $id,
                ] + ErrorDigest::context($e));

                ErrorDigest::recordDiagnostics($e, [
                    'source' => 'borrowers.bulk-destroy',
                    'borrower_id' => $id,
                ], flag: ErrorDigest::BORROWER_DIAGNOSTICS_FLAG);
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

        // The bytes must read as a picture and the name must end in one
        // (UploadFileTypes). Every refusal in words, one sentence (`bail`),
        // never Laravel's default ("The photo field must be an image.").
        $request->validate([
            'photo' => ['bail', 'required', 'image', 'extensions:'.UploadFileTypes::PHOTO_EXTENSIONS, 'max:5120'],
        ], [
            'photo.required' => 'Choose a photo to upload.',
            'photo.uploaded' => 'The photo didn\'t upload. Try again with a picture of at most 5 MB.',
            'photo.image' => 'The photo must be a picture, such as a JPG or PNG.',
            'photo.extensions' => 'The photo\'s file name must end in '.UploadFileTypes::inWords(UploadFileTypes::PHOTO_EXTENSIONS).'.',
            'photo.max' => 'The photo can be at most 5 MB.',
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

        return $this->validIdUploadResponse($request, $borrower);
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

        return $this->validIdListResponse($borrower);
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

        return $this->validIdDeleteResponse($borrower, $validIdId);
    }

    #[OA\Get(
        path: '/api/borrowers/{id}/loan-summary',
        summary: 'Member loan totals',
        description: 'The Member Overview totals, computed by the server. `total_borrowed` is the principal of every released loan (released, ongoing, completed, defaulted) except restructured ones, whose rolled-over balance the successor loan already carries. `total_outstanding` is everything still owed on released, ongoing and defaulted loans (principal, interest and penalty on the periods plus the insurance premium owed, the parts of each loan\'s summary `outstanding_balance`); `outstanding_principal` is the principal alone; `overdue_amount` is what those loans owe on periods past their due date and grace period. `total_paid` adds up posted payments, insurance premium included; `insurance_paid` is the premium inside it. On a loan the CSV importer brought in, `total_paid` also holds what its file says was paid before the import, which no payment here records; `paid_before_import` is that part (0 when none). `counts` has every loan status; `current_loans` is released plus ongoing. Applications that never released count in `counts` only.',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The member loan totals, covering only the loans the caller can see (Loan::visibleTo)'),
            new OA\Response(response: 403, description: 'Missing borrowers:view or loans:view'),
            new OA\Response(response: 404, description: 'Borrower not found'),
        ],
    )]
    public function loanSummary(Request $request, Borrower $borrower, BorrowerLoanSummaryService $summaries): JsonResponse
    {
        $this->authorize('borrowers:view');
        $this->authorize('loans:view');

        return response()->json(['data' => $summaries->summarize($borrower, $request->user())]);
    }

    #[OA\Get(
        path: '/api/borrowers/{id}/ledger',
        summary: 'Member ledger with running balance',
        description: 'The rows of each loan\'s own Ledger (GET /loans/{id}/ledger), for every ever-released loan (released, ongoing, completed, defaulted, restructured) the caller may see, in date order and, within a day, loan by loan in release order. `balance` is the loans\' principal, interest and penalty balances added up, after this row. It leaves out the insurance premium owed, as the loan Ledger does, and share capital, so a release\'s debit is its principal plus the schedule\'s interest. `debit` and `credit` are what the row moved the balance by; a payment\'s `credit` is the principal, interest and penalty it applied. A voided payment and its void are left out as a pair, so between those two dates the balance reads as if the payment was never made. `meta.closing` is the sum of the loans\' Ledger closings; `meta.closing.balance` equals the last row\'s `balance`. Whole centavos throughout. Needs `borrowers:view` and `loans:view`; `scb` is left out for a caller without `share_capital:view`.',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Ledger rows with running balance', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'id', type: 'integer', description: 'The row\'s position in this list'),
                    new OA\Property(property: 'date', type: 'string', format: 'date'),
                    new OA\Property(property: 'description', type: 'string', description: 'The loan Ledger row\'s description'),
                    new OA\Property(property: 'reference', type: 'string', description: 'A payment\'s receipt number, otherwise the loan account number'),
                    new OA\Property(property: 'debit', type: 'number', description: 'What this row raised the balance by'),
                    new OA\Property(property: 'credit', type: 'number', description: 'What this row lowered the balance by'),
                    new OA\Property(property: 'balance', type: 'number', description: 'After this row: principal, interest and penalty'),
                    new OA\Property(property: 'loan_id', type: 'integer'),
                    new OA\Property(property: 'loan_account_number', type: 'string', nullable: true),
                    new OA\Property(property: 'kind', type: 'string', enum: ['release', 'repayment', 'extension_payment', 'extension_charge', 'penalty_charge', 'adjustment'], description: 'The loan Ledger row\'s kind'),
                    new OA\Property(property: 'repayment_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'amount_paid', type: 'number', description: 'Payment rows only: the whole amount the payment took'),
                    new OA\Property(property: 'insurance', type: 'number', description: 'Payment rows only: the insurance premium it collected, outside the balance'),
                    new OA\Property(property: 'scb', type: 'number', description: 'Payment rows only, for a caller with `share_capital:view`: the share capital it credited, outside the balance'),
                ])),
                new OA\Property(property: 'meta', type: 'object', properties: [
                    new OA\Property(property: 'closing', type: 'object', properties: [
                        new OA\Property(property: 'principal', type: 'number'),
                        new OA\Property(property: 'interest', type: 'number'),
                        new OA\Property(property: 'penalty', type: 'number'),
                        new OA\Property(property: 'balance', type: 'number'),
                    ]),
                ]),
            ])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing borrowers:view or loans:view'),
            new OA\Response(response: 404, description: 'Borrower not found'),
        ],
    )]
    public function ledger(Borrower $borrower, MemberLedgerService $ledger): JsonResponse
    {
        $this->authorize('borrowers:view');
        $this->authorize('loans:view');

        ['rows' => $rows, 'closing' => $closing] = $ledger->forMember($borrower, request()->user());

        return response()->json(['data' => $rows, 'meta' => ['closing' => $closing]]);
    }
}
