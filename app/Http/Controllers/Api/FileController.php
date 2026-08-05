<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Borrower;
use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves borrower KYC documents and photos off the private disk.
 *
 * These files used to sit on the public disk, which nginx serves directly with
 * no authentication — anyone holding the URL could fetch a borrower's valid ID.
 * They now live on `private`, which has no symlink into public/, and are only
 * reachable through the temporary signed URLs minted below.
 *
 * The routes are gated by `signed` rather than `auth:sanctum` on purpose: these
 * URLs end up in `<img src>`, which cannot send an Authorization header. The
 * signature is what authenticates the request, and it expires — see
 * self::LINK_TTL_MINUTES. Links are only ever minted while serialising a
 * response for a caller who already passed the API's own authorisation, so an
 * unauthorised user never receives one to begin with.
 */
class FileController extends Controller
{
    /**
     * How long a minted file link stays valid. Matches the default API token
     * lifetime, so a link cannot outlive the session that produced it.
     */
    public const LINK_TTL_MINUTES = 30;

    public function document(Document $document): StreamedResponse
    {
        return $this->stream($document->file_path, $document->original_filename);
    }

    public function borrowerPhoto(Borrower $borrower): StreamedResponse
    {
        abort_if(! $borrower->photo_path, 404);

        return $this->stream($borrower->photo_path);
    }

    private function stream(?string $path, ?string $downloadName = null): StreamedResponse
    {
        $disk = Storage::disk('private');

        abort_if(! $path || ! $disk->exists($path), 404);

        // Inline so the browser renders it in an <img> or preview pane rather
        // than downloading it.
        return $disk->response($path, $downloadName, [
            // These are personal identity documents — never let a shared cache
            // hold on to one.
            'Cache-Control' => 'private, max-age=0, no-store',
        ]);
    }
}
