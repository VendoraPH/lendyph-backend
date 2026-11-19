<?php

namespace App\Services\LoanOrigination\Files;

use RuntimeException;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs qpdf on an uploaded PDF (PR #332 review, round 2). Pattern matching
 * can't parse a PDF the way a reader does, so qpdf rewrites it first:
 * - uncompressed(): object streams written out as plain objects and every
 *   stream qpdf can decode written uncompressed, so nothing is hidden from
 *   the checks;
 * - compressed(): the checked copy written again compressed, for storage.
 *
 * qpdf runs through Symfony Process with an argument list (never a shell
 * string), a timeout, and its input and output in a private temporary
 * folder that is always removed. Output above $maxBytes stops it at once.
 * Exit codes 0 and 3 (written, with warnings) are success; anything else is
 * a failure.
 */
final class QpdfNormaliser
{
    private readonly string $binary;

    private readonly float $timeout;

    public function __construct(?string $binary = null, ?float $timeout = null)
    {
        $this->binary = $binary ?? (string) config('loan_origination.qpdf.binary', 'qpdf');
        $this->timeout = $timeout ?? (float) config('loan_origination.qpdf.timeout', 15);
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

    /** @throws PdfNormaliseException */
    public function uncompressed(string $pdf, int $maxBytes): string
    {
        return $this->run(['--object-streams=disable', '--stream-data=uncompress', '--decode-level=generalized'], $pdf, $maxBytes);
    }

    /** @throws PdfNormaliseException */
    public function compressed(string $pdf, int $maxBytes): string
    {
        return $this->run(['--object-streams=disable', '--stream-data=compress'], $pdf, $maxBytes);
    }

    /**
     * @param  list<string>  $options
     *
     * @throws PdfNormaliseException
     */
    private function run(array $options, string $pdf, int $maxBytes): string
    {
        $folder = sys_get_temp_dir().'/lendyph-qpdf-'.bin2hex(random_bytes(16));

        if (! @mkdir($folder, 0700)) {
            throw new PdfNormaliseException('The temporary folder could not be made.');
        }

        $input = $folder.'/in.pdf';
        $output = $folder.'/out.pdf';

        try {
            if (file_put_contents($input, $pdf) !== strlen($pdf)) {
                throw new PdfNormaliseException('The PDF could not be written for checking.');
            }

            $process = new Process([$this->binary, ...$options, $input, $output]);
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
}
