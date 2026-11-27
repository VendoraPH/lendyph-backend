<?php

namespace App\Http\Requests\Document;

/**
 * The file types an upload takes, in one place, so the uploads that share
 * limits share one list: member, co-maker and loan documents
 * (StoreDocumentRequest) and accounting attachments (StoreAttachmentRequest,
 * "member document limits").
 *
 * Each upload is judged twice: `mimes` reads the bytes and `extensions` the
 * file's own name, so a PDF named receipt.bat is refused. `jpe` and `jfif`
 * are JPEG names Windows often saves; the stored file takes the extension its
 * bytes read as.
 */
final class UploadFileTypes
{
    /**
     * What a document's bytes may read as.
     */
    public const DOCUMENT_MIMES = 'jpg,jpeg,png,pdf,doc,docx';

    /**
     * What a document's name may end in.
     */
    public const DOCUMENT_EXTENSIONS = 'jpg,jpeg,jpe,jfif,png,pdf,doc,docx';

    /**
     * What a member photo's name may end in: the types Laravel's `image` rule
     * reads the bytes as (SVG left out), plus the two JPEG names.
     */
    public const PHOTO_EXTENSIONS = 'jpg,jpeg,jpe,jfif,png,gif,bmp,webp,avif,heic,heif';

    /**
     * A list read in words: ".jpg, .jpeg, .png or .pdf".
     */
    public static function inWords(string $extensions): string
    {
        $names = array_map(fn (string $extension) => ".{$extension}", explode(',', $extensions));
        $last = array_pop($names);

        return $names === [] ? $last : implode(', ', $names)." or {$last}";
    }
}
