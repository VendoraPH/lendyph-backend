<?php

namespace App\Http\Requests\Cda\Registers;

use App\Http\Requests\Document\UploadFileTypes;

/**
 * The CDA repository's upload limits (CDA design §3): the member document
 * types (UploadFileTypes) plus Excel workbooks (.xls, .xlsx), up to 10 MB,
 * judged by the file's contents and by its name's extension.
 */
final class CdaUploadRules
{
    public const MIMES = UploadFileTypes::DOCUMENT_MIMES.',xls,xlsx';

    public const EXTENSIONS = UploadFileTypes::DOCUMENT_EXTENSIONS.',xls,xlsx';

    public const MAX_KILOBYTES = 10240;

    /** @return list<string> */
    public static function file(bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'file', 'max:'.self::MAX_KILOBYTES, 'mimes:'.self::MIMES, 'extensions:'.self::EXTENSIONS];
    }

    /** @return array<string, string> every refusal of the file field, in words */
    public static function messages(string $field): array
    {
        return [
            "{$field}.required" => 'Choose a file to upload.',
            "{$field}.file" => 'The file didn\'t upload. Try again with a file of at most 10 MB.',
            "{$field}.uploaded" => 'The file didn\'t upload. Try again with a file of at most 10 MB.',
            "{$field}.max" => 'The file can be at most 10 MB.',
            "{$field}.mimes" => 'The file must be a picture (JPG or PNG), a PDF, a Word document or an Excel workbook.',
            "{$field}.extensions" => 'The file\'s name must end in '.UploadFileTypes::inWords(self::EXTENSIONS).'.',
        ];
    }
}
