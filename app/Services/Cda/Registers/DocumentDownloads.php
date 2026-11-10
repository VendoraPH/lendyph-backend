<?php

namespace App\Services\Cda\Registers;

use App\Models\CdaDocumentVersion;
use App\Models\Document;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

/**
 * One audit row per download of a CDA file (decided: standard), written by
 * FileController before the file is streamed. A file that isn't a CDA
 * document's version (a member's ID, an accounting attachment) writes
 * nothing here.
 *
 * The row is on the CDA document, by the signed-in user, else the user the
 * signed link was made for. It names the document by its category in words
 * and its id ("Minutes of meetings #12, version 2, downloaded."), and a
 * mediation document as "Mediation document #12": never by its title, which
 * can name the parties to a dispute.
 */
final class DocumentDownloads
{
    public const ACTION = 'downloaded';

    public function record(Request $request, Document $file): void
    {
        $version = CdaDocumentVersion::query()->with('cdaDocument')->where('document_id', $file->id)->first();
        $document = $version?->cdaDocument;

        if ($document === null) {
            return;
        }

        $what = $document->isMediation() ? 'Mediation document' : DocumentCategories::label($document->category);
        $linkUser = $request->query('u');

        AuditLogService::log(
            self::ACTION,
            $document,
            newValues: ['document_id' => (int) $file->id, 'version' => (int) $version->version],
            description: "{$what} #{$document->id}, version {$version->version}, downloaded.",
            userId: $request->user()?->getKey() ?? (is_scalar($linkUser) ? (int) $linkUser : null),
        );
    }
}
