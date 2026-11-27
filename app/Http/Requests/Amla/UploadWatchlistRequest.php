<?php

namespace App\Http\Requests\Amla;

use App\Models\AmlaWatchlistVersion;
use App\Services\Amla\Foundation\AmlaAccess;

/**
 * POST /amla/screening/lists/{source}/versions: a new version of a
 * watchlist, uploaded (never fetched). Needs `amla:screening` and head-office
 * access. The upload checks are the documents' (size, contents and the file
 * name's extension): the UN list is its XML, AMLC designations and internal
 * lists the CSV template.
 */
class UploadWatchlistRequest extends AmlaFormRequest
{
    public const MAX_KILOBYTES = 20480;

    protected function permissions(): array
    {
        return [AmlaAccess::SCREENING];
    }

    protected function headOffice(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $xml = (AmlaWatchlistVersion::SOURCES[(string) $this->route('source')]['format'] ?? 'csv') === 'xml';

        return [
            'file' => ['bail', 'required', 'file', 'max:'.self::MAX_KILOBYTES, $xml ? 'mimes:xml,txt' : 'mimes:csv,txt', $xml ? 'extensions:xml' : 'extensions:csv'],
            'version_label' => ['nullable', 'string', 'max:150'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $xml = (AmlaWatchlistVersion::SOURCES[(string) $this->route('source')]['format'] ?? 'csv') === 'xml';
        $kind = $xml ? 'the UN list\'s XML file (.xml)' : 'a CSV file in the template (.csv)';

        return [
            'file.required' => 'Choose the list\'s file.',
            'file.mimes' => "Upload {$kind}.",
            'file.extensions' => "Upload {$kind}.",
            'file.max' => 'The file can be at most 20 MB.',
        ];
    }
}
