<?php

namespace App\Services\LoanOrigination\Files;

use App\Models\OriginationApplication;
use App\Models\OriginationDocument;
use App\Models\OriginationDocumentVersion;
use App\Models\OriginationSetting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Where an application's files go (origination decision 4). The public
 * uploads (LO-B2) and staff replacements (LO-B3) both store through here, so
 * every file meets the same rules:
 *
 * - JPEG, PNG or PDF only, judged twice: the file name's extension and what
 *   its bytes read as must agree, and both must be among the settings'
 *   accepted types (never wider than these three);
 * - at most the settings' size (never above 10 MB) and 20 files per
 *   application;
 * - scanned by the FileScanner seam: an infected file is refused; while a
 *   configured scanner can't be reached every file is refused (503); with no
 *   scanner a file is kept as "Not scanned";
 * - a PDF is rewritten by qpdf (object streams opened up, streams
 *   uncompressed) and checked on qpdf's copy, failing closed: one carrying
 *   JavaScript, an action run on opening (/OpenAction, /AA), a launch action,
 *   embedded files or an XFA form is refused, and so is an encrypted one, one
 *   qpdf can't read or that opens up past 50 MB, and any check that fails.
 *   qpdf's compressed copy of the checked file is stored, never the PDF as
 *   sent. Without a working qpdf, PDFs are refused;
 * - an image is decoded and re-encoded (GD), so only the picture is kept;
 *   while GD is missing pictures are refused, never kept as sent;
 * - stored on the private disk under a random name, opened only through
 *   short-lived signed links (LO-B3), never the public disk.
 *
 * Each file for a document type is a new version. The same file (by SHA-256)
 * sent again for the same type returns the version already kept.
 */
final class OriginationFileStore
{
    public const DISK = 'private';

    /** The most any setting allows; Settings can only lower it. */
    public const MAX_MB = 10;

    public const MAX_FILES_PER_APPLICATION = 20;

    /** Larger images aren't decoded, so a small file can't expand into gigabytes. */
    public const MAX_IMAGE_PIXELS = 24_000_000;

    /** File name extensions each kind of content may carry. */
    public const KINDS = [
        'image/jpeg' => ['ext' => 'jpg', 'names' => ['jpg', 'jpeg', 'jpe', 'jfif']],
        'image/png' => ['ext' => 'png', 'names' => ['png']],
        'application/pdf' => ['ext' => 'pdf', 'names' => ['pdf']],
    ];

    public const WRONG_TYPE = 'Upload a JPEG, PNG or PDF file.';

    public const INFECTED = 'This file failed the virus check, so it wasn\'t saved. Upload a different file.';

    public const ACTIVE_PDF = 'This PDF contains scripts, actions or attached files, so it wasn\'t saved. Save it again as a plain PDF and upload that.';

    public const UNREADABLE_IMAGE = 'This picture couldn\'t be read. Upload a different file.';

    public const IMAGE_TOO_LARGE = 'This picture is too large to read. Upload a smaller one.';

    public const TOO_MANY = 'An application can hold at most 20 files.';

    public const UNCHECKABLE_PDF = 'This PDF is saved in a way that can\'t be checked, so it wasn\'t saved. Save it again as a plain PDF (for example with Print to PDF) and upload that.';

    public const ENCRYPTED_PDF = 'This PDF is password-protected or encrypted, so it can\'t be checked. Upload an unprotected copy.';

    public const PDF_TOO_LARGE = 'This PDF holds too much to check. Upload a smaller copy, or fewer pages.';

    public const PDFS_UNAVAILABLE = 'PDFs can\'t be accepted on this server right now. Upload clear photos of the document instead.';

    public const PICTURES_UNAVAILABLE = 'Pictures can\'t be accepted on this server right now. Upload the document as a PDF instead.';

    /** PDF names that run something or carry other files (decoded from `#xx` escapes first). */
    private const ACTIVE_PDF_NAMES = '~/(?:JavaScript|JS|OpenAction|AA|Launch|EmbeddedFiles?|XFA)(?![A-Za-z0-9])~';

    private const ENCRYPT_NAME = '~/Encrypt(?![A-Za-z0-9])~';

    /** The most decompressed PDF stream bytes read while looking for active content. */
    /** The most a PDF may open up to once qpdf has uncompressed it. */
    public const MAX_NORMALISED_BYTES = 50 * 1024 * 1024;

    private readonly bool $gdAvailable;

    private readonly QpdfNormaliser $pdf;

    /**
     * @param  bool|null  $gdAvailable  whether pictures can be re-encoded; read from the server by default
     * @param  QpdfNormaliser|null  $pdf  the qpdf runner; from the config by default
     */
    public function __construct(private readonly FileScanner $scanner, ?bool $gdAvailable = null, ?QpdfNormaliser $pdf = null)
    {
        $this->gdAvailable = $gdAvailable ?? function_exists('imagecreatefromstring');
        $this->pdf = $pdf ?? new QpdfNormaliser;
    }

    /**
     * Keep $file as the next version of the application's $type document.
     *
     * @throws UploadRefusedException 422 in words
     * @throws ScannerUnavailableException 503 while a scanner can't be reached
     */
    public function store(OriginationApplication $application, string $type, UploadedFile $file): OriginationDocumentVersion
    {
        $settings = OriginationSetting::current();
        $maxMb = min(self::MAX_MB, max(1, (int) $settings->max_upload_mb));
        $path = (string) $file->getRealPath();

        if (! $file->isValid() || $path === '') {
            throw UploadRefusedException::because('The file didn\'t arrive whole. Upload it again.');
        }

        if ((int) $file->getSize() > $maxMb * 1024 * 1024) {
            throw UploadRefusedException::because("A file can be at most {$maxMb} MB.");
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $extension = strtolower((string) pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        $accepted = array_map('strtolower', (array) $settings->accepted_file_types);

        if (! isset(self::KINDS[$mime]) || ! in_array($extension, self::KINDS[$mime]['names'], true) || ! in_array($extension, $accepted, true)) {
            throw UploadRefusedException::because(self::WRONG_TYPE);
        }

        $sha256 = (string) hash_file('sha256', $path);

        if ($existing = $this->existingVersion($application, $type, $sha256)) {
            return $existing;
        }

        if ($this->fileCount($application) >= self::MAX_FILES_PER_APPLICATION) {
            throw UploadRefusedException::because(self::TOO_MANY);
        }

        $scan = $this->scanner->scan($path);

        if ($scan === ScanResult::Infected) {
            throw UploadRefusedException::because(self::INFECTED);
        }

        if ($scan === ScanResult::Failed) {
            throw $this->scanner instanceof MisconfiguredScanner ? ScannerUnavailableException::misconfigured() : new ScannerUnavailableException;
        }

        $bytes = (string) file_get_contents($path);
        $bytes = $mime === 'application/pdf' ? $this->checkedPdf($bytes) : $this->reencoded($bytes, $mime);

        return $this->persist($application, $type, [
            'file_name' => $this->cleanName($file->getClientOriginalName(), $type, self::KINDS[$mime]['ext']),
            'mime_type' => $mime,
            'sha256' => $sha256,
            'scan_status' => $scan === ScanResult::Clean ? OriginationDocumentVersion::SCAN_CLEAN : OriginationDocumentVersion::SCAN_NOT_SCANNED,
            'scanner' => $scan === ScanResult::Clean ? $this->scanner->name() : null,
            'scanned_at' => $scan === ScanResult::Clean ? now() : null,
        ], $bytes, self::KINDS[$mime]['ext']);
    }

    /**
     * Remove a stored file and its version row (a draft's own upload removed
     * by the applicant, or the retention erasure).
     */
    public function delete(OriginationDocumentVersion $version): void
    {
        Storage::disk($version->disk)->delete($version->path);
        $version->delete();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persist(OriginationApplication $application, string $type, array $attributes, string $bytes, string $extension): OriginationDocumentVersion
    {
        $path = sprintf('%s/%s.%s', OriginationApplication::filesFolder($application->id), Str::lower((string) Str::ulid()), $extension);
        Storage::disk(self::DISK)->put($path, $bytes);

        try {
            return DB::transaction(function () use ($application, $type, $attributes, $bytes, $path): OriginationDocumentVersion {
                $document = OriginationDocument::query()
                    ->where('application_id', $application->id)->where('type', $type)->lockForUpdate()->first()
                    ?? OriginationDocument::query()->create([
                        'application_id' => $application->id,
                        'type' => $type,
                        'public_id' => Str::lower((string) Str::ulid()),
                        'status' => 'uploaded',
                    ]);

                $version = OriginationDocumentVersion::query()->create([
                    ...$attributes,
                    'document_id' => $document->id,
                    'version' => (int) OriginationDocumentVersion::query()->where('document_id', $document->id)->max('version') + 1,
                    'public_id' => Str::lower((string) Str::ulid()),
                    'disk' => self::DISK,
                    'path' => $path,
                    'size_bytes' => strlen($bytes),
                    'uploaded_at' => now(),
                ]);

                $document->forceFill(['current_version_id' => $version->id, 'status' => 'uploaded'])->save();

                // A new file changes the application: its version moves on,
                // so a staff decision taken on what was there before is
                // caught (ApplicationTransitions::move()).
                OriginationApplication::query()->whereKey($application->id)->update(['updated_at' => now()]);

                return $version;
            });
        } catch (UniqueConstraintViolationException $e) {
            Storage::disk(self::DISK)->delete($path);

            return $this->existingVersion($application, $type, (string) $attributes['sha256']) ?? throw $e;
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->delete($path);

            throw $e;
        }
    }

    private function existingVersion(OriginationApplication $application, string $type, string $sha256): ?OriginationDocumentVersion
    {
        return OriginationDocumentVersion::query()
            ->whereHas('document', fn ($q) => $q->where('application_id', $application->id)->where('type', $type))
            ->where('sha256', $sha256)
            ->first();
    }

    private function fileCount(OriginationApplication $application): int
    {
        return OriginationDocumentVersion::query()
            ->whereHas('document', fn ($q) => $q->where('application_id', $application->id))
            ->count();
    }

    /**
     * qpdf's copy of the PDF, once its fully opened-up form holds nothing
     * active. An encrypted PDF is refused before qpdf sees it.
     *
     * @throws UploadRefusedException
     */
    public function checkedPdf(string $bytes): string
    {
        if (! $this->pdf->available()) {
            throw UploadRefusedException::because(self::PDFS_UNAVAILABLE);
        }

        if ($this->matches(self::ENCRYPT_NAME, $this->decodedNames($bytes))) {
            throw UploadRefusedException::because(self::ENCRYPTED_PDF);
        }

        try {
            $plain = $this->pdf->uncompressed($bytes, self::MAX_NORMALISED_BYTES);
            $this->assertNothingActive($plain);

            return $this->pdf->compressed($plain, self::MAX_NORMALISED_BYTES);
        } catch (PdfNormaliseException $e) {
            throw UploadRefusedException::because($e->tooLarge ? self::PDF_TOO_LARGE : self::UNCHECKABLE_PDF);
        }
    }

    /**
     * Refuse text holding an encryption dictionary or an active name.
     *
     * @throws UploadRefusedException
     */
    public function assertNothingActive(string $content): void
    {
        $decoded = $this->decodedNames($content);

        if ($this->matches(self::ENCRYPT_NAME, $decoded)) {
            throw UploadRefusedException::because(self::ENCRYPTED_PDF);
        }

        if ($this->matches(self::ACTIVE_PDF_NAMES, $decoded)) {
            throw UploadRefusedException::because(self::ACTIVE_PDF);
        }
    }

    /** PDF names with their `#xx` escapes decoded (`/Java#53cript` is `/JavaScript`). */
    private function decodedNames(string $content): string
    {
        $decoded = preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn (array $m) => chr((int) hexdec($m[1])), $content);

        if ($decoded === null) {
            throw UploadRefusedException::because(self::UNCHECKABLE_PDF);
        }

        return $decoded;
    }

    /** A pattern check that refuses the file when the check itself fails. */
    private function matches(string $pattern, string $subject): bool
    {
        $found = preg_match($pattern, $subject);

        if ($found === false) {
            throw UploadRefusedException::because(self::UNCHECKABLE_PDF);
        }

        return $found === 1;
    }

    /** The picture decoded and written again, so nothing but its pixels is kept. */
    private function reencoded(string $bytes, string $mime): string
    {
        if (! $this->gdAvailable) {
            throw UploadRefusedException::because(self::PICTURES_UNAVAILABLE);
        }

        $size = @getimagesizefromstring($bytes);

        if ($size === false) {
            throw UploadRefusedException::because(self::UNREADABLE_IMAGE);
        }

        if ($size[0] * $size[1] > self::MAX_IMAGE_PIXELS) {
            throw UploadRefusedException::because(self::IMAGE_TOO_LARGE);
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            throw UploadRefusedException::because(self::UNREADABLE_IMAGE);
        }

        ob_start();
        $written = $mime === 'image/png' ? imagepng($image) : imagejpeg($image, null, 90);
        $output = (string) ob_get_clean();
        imagedestroy($image);

        if (! $written || $output === '') {
            throw UploadRefusedException::because(self::UNREADABLE_IMAGE);
        }

        return $output;
    }

    /** The name the applicant gave, without any path or control characters. */
    private function cleanName(string $original, string $type, string $extension): string
    {
        $name = basename(str_replace('\\', '/', $original));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = trim((string) preg_replace('/[\/:*?"<>|]/', '', $name));

        if ($name === '' || $name === '.' || $name === '..') {
            $name = "{$type}.{$extension}";
        }

        return mb_substr($name, -150);
    }
}
