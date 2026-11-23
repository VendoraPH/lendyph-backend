<?php

namespace App\Http\Controllers\Api\LoanOrigination\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoanOrigination\Staff\DocumentDecisionRequest;
use App\Http\Requests\LoanOrigination\Staff\ListDocumentsRequest;
use App\Models\OriginationApplication;
use App\Models\OriginationDocument;
use App\Services\LikePattern;
use App\Services\LoanOrigination\Staff\DocumentPresenter;
use App\Services\LoanOrigination\Staff\DocumentReview;
use App\Services\LoanOrigination\Staff\StaffAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The documents queue and staff document decisions (Phase 6, LO-B3). The
 * queue lists the files of submitted applications in the caller's branches;
 * drafts' uploads stay the applicant's until they submit. Another branch's
 * document answers 404, the same as a missing one.
 */
class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentPresenter $presenter,
        private readonly DocumentReview $review,
        private readonly StaffAccess $access,
    ) {}

    #[OA\Get(
        path: '/api/loan-origination/documents',
        summary: 'The documents queue',
        description: 'Files of submitted applications in the caller\'s branches, oldest change first. Filters: status, type, branch_id, application_id, search (reference or name). `meta.stats` holds per-status counts and `total`, over every filter but the status.',
        tags: ['Loan Origination'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'A page of documents'), new OA\Response(response: 403, description: 'Missing loan_origination:view, or a branch outside the caller\'s')],
    )]
    public function index(ListDocumentsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $query = fn (): Builder => $this->queue($request, $filters);

        $page = $query()
            ->when(filled($filters['status'] ?? null), fn (Builder $q) => $q->where('origination_documents.status', $filters['status']))
            ->with(['currentVersion', 'versions', 'verifier', 'application:id,reference_number,applicant_name,branch_id,loan_product_id,status,version'])
            ->orderBy('origination_documents.updated_at')
            ->orderBy('origination_documents.id')
            ->paginate($request->perPage());

        $counts = $query()->selectRaw('origination_documents.status, COUNT(*) as aggregate')
            ->groupBy('origination_documents.status')->pluck('aggregate', 'status');
        $stats = [];

        foreach (OriginationDocument::STATUSES as $status) {
            $stats[$status] = (int) ($counts[$status] ?? 0);
        }

        $stats['total'] = array_sum($stats);
        $required = [];

        return response()->json([
            'data' => $page->getCollection()->map(function (OriginationDocument $document) use (&$required, $request) {
                $application = $document->application;
                $required[$application->loan_product_id ?? 0] ??= $this->presenter->requiredTypes($application->loan_product_id);

                return [
                    ...$this->presenter->present($document, $required[$application->loan_product_id ?? 0], $this->access->canSeeContacts($request->user(), $application)),
                    'application_id' => $application->id,
                    'reference_number' => $application->reference_number,
                    'applicant_name' => (string) $application->applicant_name,
                ];
            })->values(),
            'links' => ['first' => $page->url(1), 'last' => $page->url($page->lastPage()), 'prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'stats' => $stats,
            ],
        ]);
    }

    #[OA\Post(path: '/api/loan-origination/documents/{document}/verify', summary: 'Verify a document', description: 'Optional `notes`. An ID (`id_document`) also needs `id_type` (a member valid ID type: philippine_id, drivers_license, passport, sss, umid, voters_id, postal_id, prc_id, tin_id or others), `custom_type_name` for others, and `id_number`, kept encrypted; the hand-off files the ID as the member\'s valid ID with them.', tags: ['Loan Origination'], security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'document', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The document'), new OA\Response(response: 422, description: 'Not reviewable now, in words')])]
    public function verify(DocumentDecisionRequest $request): JsonResponse
    {
        return $this->decide($request, 'verified');
    }

    #[OA\Post(path: '/api/loan-origination/documents/{document}/reject', summary: 'Reject a document', tags: ['Loan Origination'], security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'document', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The document'), new OA\Response(response: 422, description: 'No reason, or not reviewable now')])]
    public function reject(DocumentDecisionRequest $request): JsonResponse
    {
        return $this->decide($request, 'rejected');
    }

    #[OA\Post(path: '/api/loan-origination/documents/{document}/request-replacement', summary: 'Ask for another file', description: 'Logs a message for the applicant, never sent.', tags: ['Loan Origination'], security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'document', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The document'), new OA\Response(response: 422, description: 'No reason, or not reviewable now')])]
    public function requestReplacement(DocumentDecisionRequest $request): JsonResponse
    {
        return $this->decide($request, 'replacement_requested');
    }

    #[OA\Get(
        path: '/api/loan-origination/documents/{document}/download',
        summary: 'Download a document',
        description: 'The current file, or `?version=<public id>`, as an attachment, never cached. Needs borrowers:view and the application\'s branch, since a document shows the applicant\'s address and contacts. Audited by ids only.',
        tags: ['Loan Origination'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'document', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The file'), new OA\Response(response: 403, description: 'Missing loan_origination:view, or contacts masked for the caller'), new OA\Response(response: 404, description: 'No such document or file, or another branch\'s')],
    )]
    public function download(Request $request, int $document): StreamedResponse
    {
        abort_unless(StaffAccess::canView($request->user()), 403, StaffAccess::VIEW_REFUSAL);
        $version = $request->query('version');
        $found = $this->find($request, $document);
        abort_unless($this->access->canSeeContacts($request->user(), $found->application), 403, StaffAccess::DOWNLOAD_REFUSAL);

        return $this->review->download($found, is_string($version) && $version !== '' ? $version : null)
            ?? abort(404, 'This document has no file to download.');
    }

    private function decide(DocumentDecisionRequest $request, string $to): JsonResponse
    {
        $document = $this->review->decide($request->document(), $to, $request->user(), $request->text(), $request->idDetails());
        $application = OriginationApplication::query()->find($document->application_id);

        return response()->json(['success' => true, 'data' => [
            ...$this->presenter->present(
                $document,
                $this->presenter->requiredTypes($application?->loan_product_id),
                $application !== null && $this->access->canSeeContacts($request->user(), $application),
            ),
            'application_id' => $document->application_id,
            'application_version' => $application?->version,
        ]]);
    }

    private function find(Request $request, int $id): OriginationDocument
    {
        return DocumentReview::visibleTo($request->user())->with(['currentVersion', 'versions'])->find($id)
            ?? abort(404, DocumentDecisionRequest::NOT_FOUND);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<OriginationDocument>
     */
    private function queue(Request $request, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return OriginationDocument::query()
            ->whereNotNull('origination_documents.current_version_id')
            ->whereHas('application', fn (Builder $q) => $q
                ->visibleTo($request->user())
                ->whereNotIn('status', OriginationApplication::UNSUBMITTED_STATUSES)
                ->when(filled($filters['branch_id'] ?? null), fn (Builder $b) => $b->where('branch_id', (int) $filters['branch_id']))
                ->when($search !== '', fn (Builder $s) => $s->where(fn (Builder $m) => $m
                    ->where('reference_number', 'like', LikePattern::contains($search))
                    ->orWhere('applicant_name', 'like', LikePattern::contains($search)))))
            ->when(filled($filters['type'] ?? null), fn (Builder $q) => $q->where('origination_documents.type', $filters['type']))
            ->when(filled($filters['application_id'] ?? null), fn (Builder $q) => $q->where('origination_documents.application_id', (int) $filters['application_id']));
    }
}
