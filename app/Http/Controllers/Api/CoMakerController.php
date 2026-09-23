<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Traits\RespondsWithValidIds;
use App\Http\Controllers\Controller;
use App\Http\Requests\CoMaker\StoreCoMakerRequest;
use App\Http\Requests\CoMaker\UpdateCoMakerRequest;
use App\Http\Resources\CoMakerResource;
use App\Models\Borrower;
use App\Models\CoMaker;
use App\Services\ValidIdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

class CoMakerController extends Controller
{
    use RespondsWithValidIds;

    #[OA\Get(
        path: '/api/borrowers/{borrowerId}/co-makers',
        summary: 'List co-makers for a borrower',
        tags: ['Co-makers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'borrowerId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Co-maker list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Borrower not found'),
        ],
    )]
    public function index(Borrower $borrower): AnonymousResourceCollection
    {
        $this->authorize('borrowers:view');

        return CoMakerResource::collection(
            $borrower->coMakers()->with('documents')->get()
        );
    }

    #[OA\Post(
        path: '/api/borrowers/{borrowerId}/co-makers',
        summary: 'Create co-maker for a borrower',
        tags: ['Co-makers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'borrowerId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['first_name', 'last_name'],
                properties: [
                    new OA\Property(property: 'first_name', type: 'string', example: 'Maria'),
                    new OA\Property(property: 'middle_name', type: 'string'),
                    new OA\Property(property: 'last_name', type: 'string', example: 'Santos'),
                    new OA\Property(property: 'suffix', type: 'string'),
                    new OA\Property(property: 'address', type: 'string'),
                    new OA\Property(property: 'contact_number', type: 'string', example: '09181234567'),
                    new OA\Property(property: 'occupation', type: 'string'),
                    new OA\Property(property: 'employer', type: 'string'),
                    new OA\Property(property: 'monthly_income', type: 'number', example: 20000),
                    new OA\Property(property: 'relationship_to_borrower', type: 'string', example: 'Spouse'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Co-maker created'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreCoMakerRequest $request, Borrower $borrower): JsonResponse
    {
        $coMaker = $borrower->coMakers()->create($request->validated());

        return (new CoMakerResource($coMaker))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/co-makers/{id}',
        summary: 'Show co-maker',
        tags: ['Co-makers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Co-maker details'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(CoMaker $coMaker): CoMakerResource
    {
        $this->authorize('borrowers:view');

        $coMaker->load('borrower', 'documents');

        return new CoMakerResource($coMaker);
    }

    #[OA\Put(
        path: '/api/co-makers/{id}',
        summary: 'Update co-maker',
        tags: ['Co-makers'],
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
                    new OA\Property(property: 'address', type: 'string'),
                    new OA\Property(property: 'contact_number', type: 'string'),
                    new OA\Property(property: 'occupation', type: 'string'),
                    new OA\Property(property: 'employer', type: 'string'),
                    new OA\Property(property: 'monthly_income', type: 'number'),
                    new OA\Property(property: 'relationship_to_borrower', type: 'string'),
                    new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive']),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Co-maker updated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(UpdateCoMakerRequest $request, CoMaker $coMaker): CoMakerResource
    {
        $coMaker->update($request->validated());

        return new CoMakerResource($coMaker);
    }

    #[OA\Delete(
        path: '/api/co-makers/{id}',
        summary: 'Delete co-maker',
        tags: ['Co-makers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Co-maker deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function destroy(CoMaker $coMaker): JsonResponse
    {
        $this->authorize('borrowers:delete');

        /*
         * Same order as BorrowerPurgeService, for the same reason: the rows in
         * one transaction, the files only once it has committed.
         *
         * The files used to be unlinked first, with no transaction at all. But
         * `co_maker_loan.co_maker_id` is restrictOnDelete, so deleting a
         * co-maker who is on a loan fails — after their documents' rows had
         * already been deleted and their files unlinked. The co-maker survived
         * with no ID on file and nothing to restore it from. A refused delete
         * now leaves everything as it was.
         */
        $paths = [];

        DB::transaction(function () use ($coMaker, &$paths) {
            $paths = $coMaker->documents()->pluck('file_path')->all();
            $coMaker->documents()->delete();
            $coMaker->delete();
        });

        // Uploads land in documents/valid_id/co_maker/{id}/; without this an
        // empty directory outlives every deleted co-maker that had an ID.
        $directory = ValidIdService::directoryFor($coMaker);

        DB::afterCommit(function () use ($paths, $directory) {
            $disk = Storage::disk('private');

            foreach (array_filter($paths) as $path) {
                $disk->delete($path);
            }

            $disk->deleteDirectory($directory);
        });

        return response()->json(['message' => 'Co-maker deleted successfully.']);
    }

    #[OA\Post(
        path: '/api/co-makers/{id}/valid-ids',
        summary: 'Upload co-maker valid ID',
        description: <<<'DESC'
Same request contract and responses as `POST /api/borrowers/{id}/valid-ids`, so one client serves both.

Accepts either single `file` (legacy) or `front_file`+`back_file` (new) with optional `id_number` — never both shapes at once. Files must be jpg/jpeg/png/pdf, at most 10 MB each. The legacy shape answers with a single Document; the front/back shape with a list of one or two, one per side.

Files are stored on the private disk and are reachable only through each document's signed, expiring `url`.

Requires a Bearer token with `borrowers:update`, the permission that edits a co-maker. Unlike the borrower endpoint there is no public-registration path: `X-Submission-Token` is not accepted.
DESC,
        tags: ['Co-makers'],
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
            new OA\Response(response: 201, description: 'Valid ID(s) uploaded — `data` is one Document (legacy `file`) or a list of Documents (`front_file`/`back_file`)'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden — requires borrowers:update'),
            new OA\Response(response: 404, description: 'Co-maker not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function uploadValidId(Request $request, CoMaker $coMaker): JsonResponse
    {
        $this->authorize('borrowers:update');

        return $this->validIdUploadResponse($request, $coMaker);
    }

    #[OA\Get(
        path: '/api/co-makers/{id}/valid-ids',
        summary: 'List co-maker valid IDs',
        description: 'Same response as `GET /api/borrowers/{id}/valid-ids`. Returns valid IDs grouped as front/back pairs. Documents sharing the same (label, id_number) are paired into a single entry. The entry "id" is the front document id and is the value to use for DELETE. `front_url`/`back_url` are signed links that expire after 30 minutes.',
        tags: ['Co-makers'],
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
                                    new OA\Property(property: 'front_url', type: 'string', example: 'https://api.example.com/api/files/documents/41?expires=1790000000&fv=0&u=1&signature=3f2a…'),
                                    new OA\Property(property: 'back_url', type: 'string', nullable: true, example: 'https://api.example.com/api/files/documents/42?expires=1790000000&fv=0&u=1&signature=9c1d…'),
                                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                                ],
                            ),
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden — requires borrowers:view'),
            new OA\Response(response: 404, description: 'Co-maker not found'),
        ],
    )]
    public function listValidIds(CoMaker $coMaker): JsonResponse
    {
        $this->authorize('borrowers:view');

        return $this->validIdListResponse($coMaker);
    }

    #[OA\Delete(
        path: '/api/co-makers/{id}/valid-ids/{validIdId}',
        summary: 'Delete a co-maker valid ID',
        description: 'Same contract as `DELETE /api/borrowers/{id}/valid-ids/{validIdId}`. Deletes the valid ID group identified by the front document id, removing both the front and back documents (if present) sharing the same (label, id_number), and their files. Any id that is not one of THIS co-maker\'s valid IDs — another co-maker\'s, a borrower\'s, or a non-ID document — is a 404, the same answer as an id that does not exist.',
        tags: ['Co-makers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'validIdId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Valid ID deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden — requires borrowers:delete'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function deleteValidId(CoMaker $coMaker, int $validIdId): JsonResponse
    {
        $this->authorize('borrowers:delete');

        return $this->validIdDeleteResponse($coMaker, $validIdId);
    }
}
