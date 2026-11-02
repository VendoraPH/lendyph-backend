<?php

namespace App\Http\Controllers\Api\Traits;

use Symfony\Component\HttpFoundation\StreamedResponse;

trait CsvExportTrait
{
    protected function streamCsv(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                $sanitized = array_map(self::sanitizeCsvCell(...), is_array($row) ? $row : iterator_to_array($row));

                fputcsv($handle, $sanitized);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Neutralise CSV formula injection: a cell starting with =, +, -, @, a tab
     * or a carriage return is prefixed so a spreadsheet shows it as text.
     */
    protected static function sanitizeCsvCell(mixed $value): mixed
    {
        if (is_string($value) && isset($value[0]) && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'\t".$value;
        }

        return $value;
    }
}
