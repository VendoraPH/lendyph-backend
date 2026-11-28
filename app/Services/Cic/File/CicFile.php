<?php

namespace App\Services\Cic\File;

/**
 * A built CSDF TXT, held in memory only: the plain file is never stored.
 */
final class CicFile
{
    /**
     * @param  array<string, int>  $countsByType  data records per type (ID, CI, CN, NE)
     */
    public function __construct(
        public readonly string $filename,
        public readonly string $contents,
        public readonly int $recordCount,
        public readonly array $countsByType,
    ) {}

    public function sha256(): string
    {
        return hash('sha256', $this->contents);
    }

    /** The filename without `.txt`: the ZIP is `<base>.zip`, the encrypted file `<base>.zip.gpg`. */
    public function baseName(): string
    {
        return self::baseOf($this->filename);
    }

    public function zipName(): string
    {
        return $this->baseName().'.zip';
    }

    public function encryptedName(): string
    {
        return $this->baseName().'.zip.gpg';
    }

    public static function baseOf(string $filename): string
    {
        return str_ends_with($filename, '.txt') ? substr($filename, 0, -4) : $filename;
    }
}
