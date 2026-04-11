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
                // Sanitize CSV formula injection (=, +, -, @, \t, \r)
                $sanitized = array_map(function ($value) {
                    if (is_string($value) && isset($value[0]) && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"])) {
                        return "'\t".$value;
                    }

                    return $value;
                }, is_array($row) ? $row : iterator_to_array($row));

                fputcsv($handle, $sanitized);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
