<?php

namespace App\Services\LoanOrigination\Files;

use RuntimeException;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs qpdf on an uploaded PDF (PR #332 review). Pattern matching can't parse
 * a PDF the way a reader does, so qpdf rewrites it with its object streams
 * written out as plain objects (`--object-streams=disable`): every dictionary
 * key is then plain text, and an object qpdf can't read isn't in its copy at
 * all. Streams keep their own compression, so a scan's pictures stay the size
 * they were sent. The copy is what the store checks and keeps.
 *
 * - qpdf runs through Symfony Process with an argument list (never a shell
 *   string) and a timeout, and under prlimit (CPU seconds and address space,
 *   both from the config) when prlimit is on the path, as on the Linux
 *   servers.
 * - Input and output sit in a folder of their own under the app's private
 *   `storage/app/qpdf-tmp` (0700), never the shared /tmp, and are always
 *   removed. Before each run, folders an interrupted run left there for over
 *   an hour are removed; the sweep looks only in that folder and never
 *   follows a symlink.
 * - Output above $maxBytes stops qpdf at once.
 * - Exit codes 0 and 3 (written, with warnings) are success; anything else is
 *   a failure.
 */
final class QpdfNormaliser
{
    public const FOLDER_PREFIX = 'lendyph-qpdf-';

    public const STALE_AFTER_SECONDS = 3600;

    private const OPTIONS = ['--object-streams=disable'];

    private readonly string $binary;

    private readonly float $timeout;

    private readonly ?string $prlimit;

    /**
     * @param  string|false|null  $prlimit  prlimit's path; null finds it on the PATH, false runs without it
     */
    public function __construct(?string $binary = null, ?float $timeout = null, string|false|null $prlimit = null)
    {
        $this->binary = $binary ?? (string) config('loan_origination.qpdf.binary', 'qpdf');
        $this->timeout = $timeout ?? (float) config('loan_origination.qpdf.timeout', 15);
        $this->prlimit = match (true) {
            $prlimit === false => null,
            $prlimit === null => (new ExecutableFinder)->find('prlimit'),
            default => $prlimit,
        };
    }

    public function binary(): string
    {
        return $this->binary;
    }

    public function available(): bool
    {
        if ($this->binary === '') {
            return false;
        }

        if (str_contains($this->binary, '/')) {
            return is_file($this->binary) && is_executable($this->binary);
        }

        return (new ExecutableFinder)->find($this->binary) !== null;
    }

    /**
     * qpdf's copy of $pdf, object streams written out.
     *
     * @throws PdfNormaliseException
     */
    public function normalised(string $pdf, int $maxBytes): string
    {
        $root = $this->privateRoot();
        $this->clearStaleFolders($root);

        $folder = $root.'/'.self::FOLDER_PREFIX.bin2hex(random_bytes(16));

        if (! @mkdir($folder, 0700)) {
            throw new PdfNormaliseException('The temporary folder could not be made.');
        }

        $input = $folder.'/in.pdf';
        $output = $folder.'/out.pdf';

        try {
            if (file_put_contents($input, $pdf) !== strlen($pdf)) {
                throw new PdfNormaliseException('The PDF could not be written for checking.');
            }

            $process = new Process($this->command(self::OPTIONS, $input, $output));
            $process->setTimeout($this->timeout);
            $process->start();

            while ($process->isRunning()) {
                $process->checkTimeout();
                clearstatcache(true, $output);

                if (is_file($output) && filesize($output) > $maxBytes) {
                    $process->stop(0);

                    throw PdfNormaliseException::tooLarge();
                }

                usleep(10_000);
            }

            clearstatcache(true, $output);

            if (! in_array($process->getExitCode(), [0, 3], true) || ! is_file($output)) {
                throw new PdfNormaliseException('qpdf could not read the PDF.');
            }

            if (filesize($output) > $maxBytes) {
                throw PdfNormaliseException::tooLarge();
            }

            return (string) file_get_contents($output);
        } catch (ProcessException|RuntimeException $e) {
            throw $e instanceof PdfNormaliseException ? $e : new PdfNormaliseException('qpdf could not be run.', previous: $e);
        } finally {
            @unlink($input);
            @unlink($output);
            @rmdir($folder);
        }
    }

    /**
     * The argument list qpdf runs with: under prlimit when there is one.
     *
     * @param  list<string>  $options
     * @return list<string>
     */
    public function command(array $options, string $input, string $output): array
    {
        $qpdf = [$this->binary, ...$options, $input, $output];

        if ($this->prlimit === null) {
            return $qpdf;
        }

        $cpu = max(1, (int) config('loan_origination.qpdf.cpu_seconds', 10));
        $memory = max(1, (int) config('loan_origination.qpdf.memory_mb', 512)) * 1024 * 1024;

        return [$this->prlimit, "--cpu={$cpu}", "--as={$memory}", ...$qpdf];
    }

    /** The private folder qpdf's runs work in. */
    public static function temporaryRoot(): string
    {
        return storage_path('app/qpdf-tmp');
    }

    /**
     * The private folder, made 0700 if it is missing; refused if it is a
     * symlink or anything but a folder.
     *
     * @throws PdfNormaliseException
     */
    private function privateRoot(): string
    {
        $root = self::temporaryRoot();

        if (! file_exists($root) && ! is_link($root)) {
            @mkdir($root, 0700, true);
        }

        clearstatcache(true, $root);

        if (is_link($root) || ! is_dir($root)) {
            throw new PdfNormaliseException('The private temporary folder is missing or not a folder.');
        }

        @chmod($root, 0700);

        return $root;
    }

    /**
     * Remove run folders a killed run left behind for over an hour, so no
     * applicant's PDF stays on disk. Only entries of the private folder are
     * looked at, by lstat: a symlink is never followed (at most the link
     * itself is removed), and inside a run folder only plain files and links
     * are unlinked, which never touches what a link points at.
     */
    private function clearStaleFolders(string $root): void
    {
        $cutoff = time() - self::STALE_AFTER_SECONDS;

        foreach (@scandir($root) ?: [] as $entry) {
            if (! str_starts_with($entry, self::FOLDER_PREFIX)) {
                continue;
            }

            $folder = $root.'/'.$entry;
            $stat = @lstat($folder);

            if ($stat === false || $stat['mtime'] >= $cutoff) {
                continue;
            }

            if (is_link($folder) || ($stat['mode'] & 0170000) !== 0040000) {
                @unlink($folder);

                continue;
            }

            foreach (@scandir($folder) ?: [] as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                $path = $folder.'/'.$file;
                $fileStat = @lstat($path);

                if ($fileStat !== false && ($fileStat['mode'] & 0170000) !== 0040000) {
                    @unlink($path);
                }
            }

            @rmdir($folder);
        }
    }
}
