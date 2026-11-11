<?php

namespace App\Services\Portal\Pdf;

use App\Models\Document;
use App\Services\AuditLogService;
use App\Services\DownloadFilename;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What a member downloads (portal design §1, §5): always an attachment, never
 * sniffed, never cached (PortalNoStore), and audited with the member as the
 * actor (AuditLogService reads the signed-in PortalUser). Viewing pages is
 * not audited; downloading is.
 */
final class PortalDownload
{
    public function __construct(private readonly PortalPdf $pdf) {}

    /**
     * A server-made statement or certificate as a PDF.
     *
     * @param  array<string, mixed>  $details  what was printed (kind and dates), for the audit row
     */
    public function statement(PortalDocument $document, Model $record, array $details): Response
    {
        $bytes = $this->pdf->render($document);

        AuditLogService::log(
            'portal_statement_downloaded',
            $record,
            null,
            $details,
            "Member downloaded the {$document->title} ({$document->subtitle})",
        );

        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $document->fileName, $document->fileName),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** A stored file staff shared with the member, streamed from the private disk; 404 when the file is gone. */
    public function file(Document $document): StreamedResponse
    {
        $disk = Storage::disk('private');
        abort_if(blank($document->file_path) || ! $disk->exists($document->file_path), 404);

        AuditLogService::log(
            'portal_document_downloaded',
            $document,
            null,
            ['document_type' => $document->type],
            'Member downloaded a shared document ('.($document->label ?: $document->type).')',
        );

        // The staff download's cleaned name, as an attachment instead of inline.
        $inline = DownloadFilename::inlineDisposition($document->original_filename, $document->file_path);

        return $disk->response($document->file_path, null, [
            'Content-Disposition' => HeaderUtils::DISPOSITION_ATTACHMENT.substr($inline, strlen(HeaderUtils::DISPOSITION_INLINE)),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
