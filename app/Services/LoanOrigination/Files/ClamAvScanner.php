<?php

namespace App\Services\LoanOrigination\Files;

use Closure;
use Throwable;

/**
 * ClamAV's clamd, over a unix socket or TCP, with the INSTREAM command: the
 * file goes in length-prefixed chunks (4-byte big-endian length, then the
 * bytes), ended by a zero length, and clamd answers `stream: OK`,
 * `stream: <signature> FOUND` or an `... ERROR` line, each ended by a NUL.
 *
 * Anything but OK or FOUND, a connection that can't be opened, a timeout or a
 * short write is Failed, never Clean: the store then refuses the file.
 */
final class ClamAvScanner implements FileScanner
{
    /** @var Closure(): (resource|false) */
    private Closure $connect;

    /**
     * @param  (Closure(): (resource|false))|null  $connect  opens the connection; from the config by default
     */
    public function __construct(?Closure $connect = null, private readonly int $chunkBytes = 8192, private readonly float $timeout = 10.0)
    {
        $this->connect = $connect ?? function () {
            $socket = config('loan_origination.scanner.clamav.socket');
            $address = is_string($socket) && $socket !== ''
                ? 'unix://'.$socket
                : sprintf('tcp://%s:%d', config('loan_origination.scanner.clamav.host'), config('loan_origination.scanner.clamav.port'));

            return @stream_socket_client($address, $errno, $error, $this->timeout);
        };
    }

    public static function fromConfig(): self
    {
        return new self(timeout: (float) config('loan_origination.scanner.clamav.timeout', 10));
    }

    public function name(): string
    {
        return 'clamav';
    }

    public function scan(string $path): ScanResult
    {
        $connection = null;
        $file = null;

        try {
            $connection = ($this->connect)();
            $file = @fopen($path, 'rb');

            if (! is_resource($connection) || ! is_resource($file)) {
                return ScanResult::Failed;
            }

            stream_set_timeout($connection, (int) ceil($this->timeout));

            if (! $this->write($connection, "zINSTREAM\0")) {
                return ScanResult::Failed;
            }

            while (! feof($file)) {
                $chunk = fread($file, $this->chunkBytes);

                if ($chunk === false) {
                    return ScanResult::Failed;
                }

                if ($chunk !== '' && ! $this->write($connection, pack('N', strlen($chunk)).$chunk)) {
                    return ScanResult::Failed;
                }
            }

            if (! $this->write($connection, pack('N', 0))) {
                return ScanResult::Failed;
            }

            return $this->verdict($this->readAnswer($connection));
        } catch (Throwable) {
            return ScanResult::Failed;
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }

            if (is_resource($connection)) {
                fclose($connection);
            }
        }
    }

    /** @param  resource  $connection */
    private function write($connection, string $bytes): bool
    {
        $length = strlen($bytes);
        $written = 0;

        while ($written < $length) {
            $sent = fwrite($connection, substr($bytes, $written));

            if ($sent === false || $sent === 0) {
                return false;
            }

            $written += $sent;
        }

        return true;
    }

    /** @param  resource  $connection */
    private function readAnswer($connection): string
    {
        $answer = '';

        while (! str_contains($answer, "\0") && strlen($answer) < 1024) {
            $byte = fread($connection, 1);

            if ($byte === false || $byte === '') {
                break;
            }

            $answer .= $byte;
        }

        return trim(str_replace("\0", '', $answer));
    }

    private function verdict(string $answer): ScanResult
    {
        return match (true) {
            str_ends_with($answer, ' FOUND') => ScanResult::Infected,
            $answer === 'stream: OK' => ScanResult::Clean,
            default => ScanResult::Failed,
        };
    }
}
