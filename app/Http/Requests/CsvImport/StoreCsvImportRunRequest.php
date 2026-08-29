<?php

namespace App\Http\Requests\CsvImport;

use App\Services\CsvImport\CsvImportUploadService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Opening a CSV migration run: what the client is about to send, declared up
 * front so the server can size the chunks, reject an impossible file before a
 * byte of it lands, and know what the finished upload must hash to.
 */
class StoreCsvImportRunRequest extends FormRequest
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
        $files = $this->input('files');

        if (! is_array($files)) {
            return;
        }

        // Digests are compared with hash_equals against hash_file() output,
        // which is lowercase. A client that uppercases its hex would otherwise
        // fail every comparison for a reason nothing in the error message
        // explains.
        foreach ($files as $kind => $file) {
            if (is_array($file) && isset($file['sha256']) && is_string($file['sha256'])) {
                $files[$kind]['sha256'] = strtolower(trim($file['sha256']));
            }
        }

        $this->merge(['files' => $files]);
    }

    public function rules(): array
    {
        $maxBytes = app(CsvImportUploadService::class)->maxFileBytes();

        return [
            /**
             * REQUIRED, and the one field here with no safe default.
             *
             * `loans.branch_id` is NOT NULL and is copied from the borrower the
             * loan belongs to, while `borrowers.branch_id` is nullable. A run
             * opened without a branch therefore imports members happily and
             * then kills every single one of their loans on a raw SQL
             * integrity error, thousands of rows into a job that has already
             * created the members. There is no per-row answer available either:
             * a legacy export has no concept of this system's branches, so the
             * admin answers once, here, before anything is written.
             */
            'branch_id' => ['required', 'integer', 'exists:branches,id'],

            /**
             * The date the extract represents, not the date it was uploaded.
             * Balances, accrued interest and past-due status in the file are all
             * true as of this date. Defaults to today (Asia/Manila) when the
             * client omits it; a future date is refused because "as of" a date
             * that has not happened cannot describe a balance.
             */
            'as_of_date' => ['nullable', 'date_format:Y-m-d', 'after:1999-12-31', 'before_or_equal:today'],

            // `array:customers,loans` rejects unknown keys outright rather than
            // ignoring them, so a client that sends `members` learns it named
            // the file wrong here instead of at assemble time.
            'files' => ['required', 'array:customers,loans'],
            'files.customers' => ['required', 'array:filename,size_bytes,sha256'],
            'files.loans' => ['required', 'array:filename,size_bytes,sha256'],

            'files.*.filename' => ['required', 'string', 'max:255'],
            'files.*.size_bytes' => ['required', 'integer', 'min:1', 'max:'.$maxBytes],
            'files.*.sha256' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ];
    }

    public function messages(): array
    {
        $maxMb = (int) round(app(CsvImportUploadService::class)->maxFileBytes() / 1048576);

        return [
            'branch_id.required' => 'Choose the branch these members and loans belong to. Every imported loan needs one, and the file cannot supply it.',
            'files.*.size_bytes.max' => "That file is larger than the {$maxMb} MB import limit.",
            'files.*.sha256.regex' => 'Each file needs a SHA-256 digest as 64 hexadecimal characters.',
            'as_of_date.before_or_equal' => 'The as-of date is the date the extract was taken; it cannot be in the future.',
        ];
    }
}
