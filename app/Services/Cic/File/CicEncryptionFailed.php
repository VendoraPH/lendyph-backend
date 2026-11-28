<?php

namespace App\Services\Cic\File;

use RuntimeException;

/**
 * The file couldn't be zipped or encrypted. The message reads in words and
 * holds no file contents, key material or command output.
 */
final class CicEncryptionFailed extends RuntimeException
{
    public static function gpgMissing(): self
    {
        return new self('GnuPG isn\'t installed on this server, so the file can\'t be encrypted. Nothing was saved.');
    }
}
