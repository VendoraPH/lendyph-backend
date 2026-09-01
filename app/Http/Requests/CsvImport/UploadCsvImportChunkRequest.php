<?php

namespace App\Http\Requests\CsvImport;

use App\Services\CsvImport\CsvImportUploadService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One slice of one uploaded CSV, plus the digest of that slice.
 *
 * The digest may arrive either as a `sha256` form field (multipart) or as an
 * `X-Chunk-Sha256` header (raw body) — see CsvImportController::uploadChunk for
 * why both body encodings exist. It is normalised to one place here so the
 * rules below, and everything downstream, only ever see `sha256`.
 */
class UploadCsvImportChunkRequest extends FormRequest
{
    /**
     * Checked here as well as in the controller, and both are deliberate.
     *
     * The controller's `$this->authorize('imports:process')` is the house
     * convention and the gate every reader will look for. But a FormRequest
     * validates BEFORE the controller runs, so with the check only there an
     * unprivileged user gets a 422 first — enough to probe which branch ids
     * exist and what the import size limit is, before ever being told 403.
     * Authorising here puts the refusal ahead of the validator, where it
     * belongs.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('imports:process') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $digest = $this->input('sha256') ?? $this->header('X-Chunk-Sha256');

        if (is_string($digest)) {
            // hash_file() returns lowercase hex; a client that uppercases its
            // digest must not fail every comparison for a reason no error
            // message explains.
            $this->merge(['sha256' => strtolower(trim($digest))]);
        }
    }

    public function rules(): array
    {
        $ceiling = app(CsvImportUploadService::class)->maxAcceptableChunkBytes();

        return [
            'sha256' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],

            /**
             * `sometimes`, because a chunk may legitimately arrive as the raw
             * request body instead of a multipart part.
             *
             * The bound is the service's ceiling, not the currently advertised
             * chunk size: a run freezes the size it was opened with, and
             * retuning `imports.chunk_size` mid-upload must not start rejecting
             * the parts an in-flight run is still sending. The exact size for
             * this index is checked in the service against the file's own
             * frozen value. Kilobytes, as Laravel's rule requires.
             *
             * Deliberately no `mimes`/`mimetypes` rule: a slice of a CSV is
             * arbitrary bytes that generally cut a line in half, and every
             * content sniffer in existence will call it something else.
             */
            'chunk' => ['sometimes', 'file', 'max:'.(int) ceil($ceiling / 1024)],
        ];
    }

    public function messages(): array
    {
        return [
            'sha256.required' => 'Send the SHA-256 digest of this chunk, as a `sha256` field or an `X-Chunk-Sha256` header.',
            'sha256.regex' => 'The chunk digest must be 64 hexadecimal characters.',
        ];
    }
}
