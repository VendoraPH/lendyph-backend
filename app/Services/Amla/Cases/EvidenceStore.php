<?php

namespace App\Services\Amla\Cases;

use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Puts AMLA evidence on the private disk under a random name with the
 * extension its bytes read as, in AMLA's own folder, and never under the
 * member's documents (AMLA design §4.14). The upload checks (type by contents
 * and by the name's extension, size) are the request's, as for member
 * documents.
 */
final class EvidenceStore
{
    public const DISK = 'private';

    /** @return array{path: string, name: string, mime: string|null, size: int, sha256: string} */
    public function store(UploadedFile $file, string $folder): array
    {
        $format = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension()));
        $sha256 = (string) hash_file('sha256', $file->getRealPath());
        $path = $file->storeAs($folder, Str::uuid()->toString().'.'.$format, self::DISK);

        return [
            'path' => (string) $path,
            'name' => Document::cleanOriginalFilename($file->getClientOriginalName()),
            'mime' => $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'sha256' => $sha256,
        ];
    }

    public function forget(string $path): void
    {
        Storage::disk(self::DISK)->delete($path);
    }
}
