<?php

namespace App\Services\Cic\File;

/**
 * What an encryption made: the ZIP's checksum (the ZIP itself is gone) and the
 * encrypted `.zip.gpg`, the only file that is kept.
 */
final class CicEncryptedFile
{
    public function __construct(
        public readonly string $zipSha256,
        public readonly string $contents,
    ) {}

    public function sha256(): string
    {
        return hash('sha256', $this->contents);
    }
}
