<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Borrower\StoreBorrowerRequest;
use App\Http\Requests\Borrower\UpdateBorrowerRequest;
use App\Http\Resources\BorrowerResource;
use App\Http\Resources\DocumentResource;
use App\Models\Borrower;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
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
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'inactive', 'blacklisted'])),
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

        return BorrowerResource::collection($borrowers);
    }

    #[OA\Post(
        path: '/api/borrowers',
        summary: 'Create borrower',
        description: 'Create a new borrower profile',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['first_name', 'last_name', 'branch_id'],
                properties: [
                    new OA\Property(property: 'first_name', type: 'string', example: 'Juan'),
                    new OA\Property(property: 'middle_name', type: 'string', example: 'Santos'),
                    new OA\Property(property: 'last_name', type: 'string', example: 'Dela Cruz'),
                    new OA\Property(property: 'suffix', type: 'string', example: 'Jr.'),
                    new OA\Property(property: 'birthdate', type: 'string', format: 'date', example: '1990-01-15'),
                    new OA\Property(property: 'civil_status', type: 'string', enum: ['single', 'married', 'widowed', 'separated', 'divorced']),
                    new OA\Property(property: 'gender', type: 'string', enum: ['male', 'female']),
                    new OA\Property(property: 'address', type: 'string'),
                    new OA\Property(property: 'contact_number', type: 'string', example: '09171234567'),
                    new OA\Property(property: 'email', type: 'string', example: 'juan@email.com'),
                    new OA\Property(property: 'employer_or_business', type: 'string'),
                    new OA\Property(property: 'monthly_income', type: 'number', example: 25000),
                    new OA\Property(property: 'spouse_first_name', type: 'string'),
                    new OA\Property(property: 'spouse_middle_name', type: 'string'),
                    new OA\Property(property: 'spouse_last_name', type: 'string'),
                    new OA\Property(property: 'spouse_contact_number', type: 'string'),
                    new OA\Property(property: 'spouse_occupation', type: 'string'),
                    new OA\Property(property: 'branch_id', type: 'integer', example: 1),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Borrower created'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreBorrowerRequest $request): JsonResponse
    {
        $borrower = Borrower::create($request->validated());
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
        description: 'Update borrower profile',
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
                    new OA\Property(property: 'address', type: 'string'),
                    new OA\Property(property: 'contact_number', type: 'string'),
                    new OA\Property(property: 'email', type: 'string'),
                    new OA\Property(property: 'employer_or_business', type: 'string'),
                    new OA\Property(property: 'monthly_income', type: 'number'),
                    new OA\Property(property: 'spouse_first_name', type: 'string'),
                    new OA\Property(property: 'spouse_middle_name', type: 'string'),
                    new OA\Property(property: 'spouse_last_name', type: 'string'),
                    new OA\Property(property: 'spouse_contact_number', type: 'string'),
                    new OA\Property(property: 'spouse_occupation', type: 'string'),
                    new OA\Property(property: 'branch_id', type: 'integer'),
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
        $borrower->update($request->validated());
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
                    Storage::disk('public')->delete($doc->file_path);
                }
                $coMaker->documents()->delete();
            }

            // Delete borrower documents from disk
            foreach ($borrower->documents as $doc) {
                Storage::disk('public')->delete($doc->file_path);
            }
            $borrower->documents()->delete();

            // Delete photo from disk
            if ($borrower->photo_path) {
                Storage::disk('public')->delete($borrower->photo_path);
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

    #[OA\Post(
        path: '/api/borrowers/{id}/photo',
        summary: 'Upload borrower photo',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
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
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function uploadPhoto(Borrower $borrower): JsonResponse
    {
        $this->authorize('borrowers:update');

        request()->validate([
            'photo' => ['required', 'image', 'max:5120'],
        ]);

        // Delete old photo
        if ($borrower->photo_path) {
            Storage::disk('public')->delete($borrower->photo_path);
        }

        $path = request()->file('photo')->store("borrowers/photos/{$borrower->id}", 'public');
        $borrower->update(['photo_path' => $path]);

        return response()->json([
            'message' => 'Photo uploaded successfully.',
            'photo_url' => Storage::disk('public')->url($path),
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
            Storage::disk('public')->delete($borrower->photo_path);
            $borrower->update(['photo_path' => null]);
        }

        return response()->json(['message' => 'Photo deleted successfully.']);
    }

    #[OA\Post(
        path: '/api/borrowers/{id}/valid-ids',
        summary: 'Upload borrower valid ID',
        description: 'Accepts either single `file` (legacy) or `front_file`+`back_file` (new) with optional `id_number`.',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['type'],
                    properties: [
                        new OA\Property(property: 'type', type: 'string', example: 'Driver\'s License'),
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
    public function uploadValidId(Borrower $borrower): JsonResponse
    {
        $this->authorize('borrowers:update');

        $request = request();

        // Mutual exclusion: legacy single-file shape vs new front/back shape
        if ($request->hasFile('file') && $request->hasFile('front_file')) {
            throw ValidationException::withMessages([
                'file' => 'Use either `file` (legacy) or `front_file`/`back_file`, not both.',
            ]);
        }

        $rules = [
            'type' => ['required', 'string', 'max:100'],
            'id_number' => ['nullable', 'string', 'max:100'],
            'file' => ['required_without:front_file', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf'],
            'front_file' => ['required_without:file', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf'],
            'back_file' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf'],
        ];

        $request->validate($rules);

        $type = $request->input('type');
        $idNumber = $request->input('id_number');
        $isLegacy = $request->hasFile('file');
        $storedPaths = [];
        $documents = [];

        try {
            DB::transaction(function () use ($borrower, $request, $type, $idNumber, $isLegacy, &$storedPaths, &$documents) {
                if ($isLegacy) {
                    $documents[] = $this->storeValidIdFile($borrower, $request->file('file'), $type, $idNumber, null, $storedPaths);
                } else {
                    $documents[] = $this->storeValidIdFile($borrower, $request->file('front_file'), $type, $idNumber, 'front', $storedPaths);
                    if ($request->hasFile('back_file')) {
                        $documents[] = $this->storeValidIdFile($borrower, $request->file('back_file'), $type, $idNumber, 'back', $storedPaths);
                    }
                }
            });
        } catch (\Throwable $e) {
            // Roll back any files written to disk before the DB failure
            foreach ($storedPaths as $path) {
                Storage::disk('public')->delete($path);
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

    private function storeValidIdFile(Borrower $borrower, UploadedFile $file, string $type, ?string $idNumber, ?string $side, array &$storedPaths): Document
    {
        $path = $file->store("documents/valid_id/borrower/{$borrower->id}", 'public');
        $storedPaths[] = $path;

        return $borrower->documents()->create([
            'type' => 'valid_id',
            'label' => $type,
            'id_number' => $idNumber,
            'side' => $side,
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
        ]);
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
