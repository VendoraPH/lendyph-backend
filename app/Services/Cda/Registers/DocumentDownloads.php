<?php

namespace App\Services\Cda\Registers;

use App\Models\CdaDocumentVersion;
use App\Models\Document;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

/**
 * Downloads of CDA files (decided: standard), called by FileController
 * before a file is streamed. A file that isn't a CDA document's version (a
 * member's ID, an accounting attachment) is left alone here.
 *
 * - **Who:** the signed-in user, else the user the signed link was made for
 *   (`u`, inside the signature, so it can't be forged). A link opened in a
 *   browser tab has no signed-in user, so the link's user is re-checked on
 *   every download.
 * - **Refused (404):** no such user, an inactive one, or one who may no
 *   longer reach the file (DocumentRepository::downloadableFileIds(): `cda:view`
 *   with `branches:view_all`, plus a mediation, audit or tax view
 *   permission). 404 as for a missing file, so nothing is told.
 * - **Audited:** one row per download on the CDA document, naming it by its
 *   category in words and its id ("Minutes of meetings #12, version 2,
 *   downloaded."), a mediation one as "Mediation document #12", never by its
 *   title. `via: link` when nobody was signed in.
 */
final class DocumentDownloads
{
    public const ACTION = 'downloaded';

    public function __construct(private readonly DocumentRepository $documents) {}

    public function authorizeAndRecord(Request $request, Document $file): void
    {
        $version = CdaDocumentVersion::query()->with('cdaDocument')->where('document_id', $file->id)->first();
        $document = $version?->cdaDocument;

        if ($document === null) {
            return;
        }

        $signedIn = $request->user();
        $actor = $signedIn instanceof User ? $signedIn : $this->linkUser($request);

        abort_if(
            $actor === null || $actor->status !== 'active'
                || ! $this->documents->downloadableFileIds($actor)->where('document_id', $file->id)->exists(),
            404,
        );

        $what = $document->isMediation() ? 'Mediation document' : DocumentCategories::label($document->category);

        AuditLogService::log(
            self::ACTION,
            $document,
            newValues: ['document_id' => (int) $file->id, 'version' => (int) $version->version, ...($signedIn instanceof User ? [] : ['via' => 'link'])],
            description: "{$what} #{$document->id}, version {$version->version}, downloaded.",
            userId: (int) $actor->getKey(),
        );
    }

    private function linkUser(Request $request): ?User
    {
        $id = $request->query('u');

        return is_scalar($id) && ctype_digit((string) $id) ? User::query()->find((int) $id) : null;
    }
}
