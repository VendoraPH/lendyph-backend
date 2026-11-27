<?php

namespace App\Http\Controllers\Api\Traits;

use App\Http\Resources\DocumentResource;
use App\Models\Borrower;
use App\Models\CoMaker;
use App\Services\ValidIdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The valid-ID endpoints' responses, shared by BorrowerController and
 * CoMakerController.
 *
 * Each controller action authorises its caller and then hands over to one of
 * these, so /borrowers/{id}/valid-ids and /co-makers/{id}/valid-ids answer with
 * the same statuses and the same JSON, and there is no second copy of either to
 * drift. The work itself is App\Services\ValidIdService.
 */
trait RespondsWithValidIds
{
    /**
     * 201. The legacy `file` shape answers with a single document; the
     * `front_file`/`back_file` shape with a list of one or two.
     */
    protected function validIdUploadResponse(Request $request, Borrower|CoMaker $owner): JsonResponse
    {
        $upload = app(ValidIdService::class)->store($request, $owner);

        if ($upload['legacy']) {
            return (new DocumentResource($upload['documents'][0]))
                ->response()
                ->setStatusCode(201);
        }

        return DocumentResource::collection($upload['documents'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * 200 with `{ "data": [...] }`, one entry per ID; see ValidIdService::grouped().
     */
    protected function validIdListResponse(Borrower|CoMaker $owner): JsonResponse
    {
        return response()->json(['data' => app(ValidIdService::class)->grouped($owner)]);
    }

    /**
     * 200, or 404 for any id that is not one of the owner's own valid IDs; see
     * ValidIdService::delete().
     */
    protected function validIdDeleteResponse(Borrower|CoMaker $owner, int $validIdId): JsonResponse
    {
        app(ValidIdService::class)->delete($owner, $validIdId);

        return response()->json(['message' => 'Valid ID deleted successfully.']);
    }
}
