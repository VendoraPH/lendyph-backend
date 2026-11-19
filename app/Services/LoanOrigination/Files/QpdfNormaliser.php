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
 * - Input and output sit in a private temporary folder that is always
 *   removed; folders an interrupted run left behind for over an hour are
 *   removed before each run.
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
        $this->clearStaleFolders();

        $folder = sys_get_temp_dir().'/'.self::FOLDER_PREFIX.bin2hex(random_bytes(16));

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

    /** Remove folders a killed run left behind, so no applicant's PDF stays in the temporary folder. */
    private function clearStaleFolders(): void
    {
        $cutoff = time() - self::STALE_AFTER_SECONDS;

        foreach (glob(sys_get_temp_dir().'/'.self::FOLDER_PREFIX.'*', GLOB_ONLYDIR) ?: [] as $folder) {
            if (is_link($folder) || @filemtime($folder) >= $cutoff) {
                continue;
            }

            foreach (glob($folder.'/*') ?: [] as $file) {
                if (is_file($file) || is_link($file)) {
                    @unlink($file);
                }
            }

            @rmdir($folder);
        }
    }
}
