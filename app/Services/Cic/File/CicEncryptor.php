<?php

namespace App\Services\Cic\File;

use App\Models\CicPublicKey;
use FilesystemIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Zips a CSDF TXT and encrypts the ZIP to CIC's active public key with GnuPG
 * (prompt §13.3, CIC's `gpg --encrypt …zip` example): encryption only, never
 * signed, one recipient.
 *
 * - Everything happens in a folder of its own (0700) under the app's private
 *   `storage/app/cic-tmp`, never the shared /tmp: the TXT, the ZIP, the key
 *   and a temporary `GNUPGHOME` holding only that key. The folder is removed
 *   in a `finally`, and folders an interrupted run left there for over an hour
 *   are removed before each run (never following a symlink).
 * - The ZIP holds exactly the TXT under its filename, and is read back to
 *   prove it.
 * - The imported key must be one key, with the stored fingerprint, able to
 *   encrypt, and neither expired nor revoked.
 * - gpg runs through Symfony Process with an argument list (never a shell
 *   string), a timeout and `--no-autostart`: importing and encrypting to a
 *   public key need no gpg-agent or keyboxd, so none is left running and no
 *   socket path depends on the server's storage path. A missing gpg refuses;
 *   there is no fallback.
 * - Messages read in words and never hold the file, the key or gpg's output.
 */
final class CicEncryptor
{
    public const FOLDER_PREFIX = 'lendyph-cic-';

    public const STALE_AFTER_SECONDS = 3600;

    private const TIMEOUT_SECONDS = 120;

    private const FAILED = 'GnuPG could not encrypt the file with the active CIC key. Nothing was saved.';

    public function binary(): string
    {
        return (string) config('cic.gpg_binary', '/usr/bin/gpg');
    }

    public function available(): bool
    {
        $binary = $this->binary();

        if ($binary === '') {
            return false;
        }

        if (str_contains($binary, '/')) {
            return is_file($binary) && is_executable($binary);
        }

        return (new ExecutableFinder)->find($binary) !== null;
    }

    /** @throws CicEncryptionFailed */
    public function encrypt(CicFile $file, CicPublicKey $key): CicEncryptedFile
    {
        if (! $this->available()) {
            throw CicEncryptionFailed::gpgMissing();
        }

        $root = $this->privateRoot();
        $this->clearStaleFolders($root);

        $folder = $root.'/'.self::FOLDER_PREFIX.bin2hex(random_bytes(8));
        $home = $folder.'/g';

        if (! @mkdir($folder, 0700) || ! @mkdir($home, 0700)) {
            @rmdir($folder);

            throw new CicEncryptionFailed('The temporary folder for encryption could not be made. Nothing was saved.');
        }

        try {
            $txtPath = $folder.'/'.$file->filename;
            $zipPath = $folder.'/'.$file->zipName();
            $gpgPath = $folder.'/'.$file->encryptedName();
            $keyPath = $folder.'/key.asc';

            $this->write($txtPath, $file->contents);
            $zipSha = $this->zip($file, $txtPath, $zipPath);

            $this->write($home.'/common.conf', '');
            $this->write($keyPath, $key->armored);
            $this->run(['--batch', '--import', $keyPath], $home, $folder);
            $this->assertImportedKey($key, $home, $folder);

            $this->run([
                '--batch', '--yes', '--trust-model', 'always',
                '--recipient', $key->fingerprint,
                '--output', $gpgPath,
                '--encrypt', $zipPath,
            ], $home, $folder);

            clearstatcache(true, $gpgPath);

            if (! is_file($gpgPath) || filesize($gpgPath) === 0) {
                throw new CicEncryptionFailed(self::FAILED);
            }

            return new CicEncryptedFile($zipSha, (string) file_get_contents($gpgPath));
        } finally {
            $this->stopDaemons($home);
            $this->remove($folder);
        }
    }

    /** ZIP the TXT under its filename, read it back, and give the ZIP's SHA-256. */
    private function zip(CicFile $file, string $txtPath, string $zipPath): string
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true
            || ! $zip->addFile($txtPath, $file->filename)
            || ! $zip->close()) {
            throw new CicEncryptionFailed('The file could not be zipped. Nothing was saved.');
        }

        $check = new ZipArchive;

        if ($check->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new CicEncryptionFailed('The ZIP could not be opened again to check it. Nothing was saved.');
        }

        try {
            $inside = $check->numFiles === 1 && $check->getNameIndex(0) === $file->filename
                ? $check->getFromIndex(0)
                : false;
        } finally {
            $check->close();
        }

        if (! is_string($inside) || hash('sha256', $inside) !== $file->sha256()) {
            throw new CicEncryptionFailed('The ZIP doesn\'t hold exactly the file. Nothing was saved.');
        }

        return (string) hash_file('sha256', $zipPath);
    }

    /** The imported key is one key, the stored fingerprint, able to encrypt, not expired or revoked. */
    private function assertImportedKey(CicPublicKey $key, string $home, string $folder): void
    {
        $listing = $this->run(['--batch', '--with-colons', '--fingerprint', '--list-keys'], $home, $folder);
        $primaries = [];
        $current = null;

        foreach (preg_split('/\R/', $listing) ?: [] as $line) {
            $columns = explode(':', $line);

            if ($columns[0] === 'pub') {
                $current = count($primaries);
                $primaries[] = ['validity' => $columns[1] ?? '', 'capabilities' => $columns[11] ?? '', 'fingerprint' => null];
            } elseif ($columns[0] === 'fpr' && $current !== null && $primaries[$current]['fingerprint'] === null) {
                $primaries[$current]['fingerprint'] = strtoupper($columns[9] ?? '');
            } elseif ($columns[0] === 'sub') {
                $current = null;
            }
        }

        if (count($primaries) !== 1 || $primaries[0]['fingerprint'] !== strtoupper($key->fingerprint)) {
            throw new CicEncryptionFailed('The active CIC key doesn\'t match its recorded fingerprint, so nothing was encrypted. Upload and verify the key again.');
        }

        if (in_array($primaries[0]['validity'], ['e', 'r', 'd', 'i'], true) || ! str_contains($primaries[0]['capabilities'], 'E')) {
            throw new CicEncryptionFailed('The active CIC key can\'t be used to encrypt (expired, revoked or without an encryption key). Nothing was saved.');
        }
    }

    /** @param  list<string>  $arguments */
    private function run(array $arguments, string $home, string $folder): string
    {
        try {
            $process = new Process([$this->binary(), '--no-autostart', ...$arguments], $folder, ['GNUPGHOME' => $home, 'LC_ALL' => 'C']);
            $process->setTimeout(self::TIMEOUT_SECONDS);
            $process->run();
        } catch (ProcessException|RuntimeException) {
            throw new CicEncryptionFailed(self::FAILED);
        }

        if (! $process->isSuccessful()) {
            throw new CicEncryptionFailed(self::FAILED);
        }

        return $process->getOutput();
    }

    /** Stop any GnuPG helper the run started for its temporary home. */
    private function stopDaemons(string $home): void
    {
        $gpgconf = dirname($this->binary()).'/gpgconf';

        if (! is_dir($home) || ! str_contains($this->binary(), '/') || ! is_file($gpgconf) || ! is_executable($gpgconf)) {
            return;
        }

        try {
            $process = new Process([$gpgconf, '--kill', 'all'], null, ['GNUPGHOME' => $home]);
            $process->setTimeout(10);
            $process->run();
        } catch (ProcessException|RuntimeException) {
            // The folder is removed next either way.
        }
    }

    private function write(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) !== strlen($contents)) {
            throw new CicEncryptionFailed('A temporary file for encryption could not be written. Nothing was saved.');
        }

        @chmod($path, 0600);
    }

    private function privateRoot(): string
    {
        $root = storage_path('app/cic-tmp');

        if (! is_dir($root) && ! @mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new CicEncryptionFailed('The temporary folder for encryption could not be made. Nothing was saved.');
        }

        @chmod($root, 0700);

        return $root;
    }

    private function clearStaleFolders(string $root): void
    {
        foreach (new FilesystemIterator($root) as $entry) {
            /** @var SplFileInfo $entry */
            if ($entry->isLink() || ! $entry->isDir() || ! str_starts_with($entry->getFilename(), self::FOLDER_PREFIX)) {
                continue;
            }

            if ($entry->getMTime() < time() - self::STALE_AFTER_SECONDS) {
                $this->remove($entry->getPathname());
            }
        }
    }

    /** Remove a folder and everything in it, never following a symlink. */
    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (new FilesystemIterator($path) as $entry) {
            /** @var SplFileInfo $entry */
            $this->remove($entry->getPathname());
        }

        @rmdir($path);
    }
}
