<?php

namespace App\Http\Requests\CsvImport;

use App\Rules\BranchTakesNew;
use App\Services\BranchAccess;
use App\Services\Branches\BranchWriteGuard;
use App\Services\CsvImport\CsvImportUploadService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Opening a CSV migration run: what the client is about to send, declared up
 * front so the server can size the chunks, reject an impossible file before a
 * byte of it lands, and know what the finished upload must hash to.
 */
class StoreCsvImportRunRequest extends FormRequest
{
    public const CHOOSE_A_FILE = 'Choose the members file or the share capital file to import.';

    public const SHARE_CAPITAL_NEEDS_PERMISSION = 'Importing share capital needs permission to record share capital entries, and your account doesn\'t have it. Leave the share capital file out, or have someone who can record share capital start the import.';

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
             *
             * One of the caller's own branches (Phase 6 FX-1, D8), like every
             * other field that places members or loans in a branch; any branch
             * with `branches:view_all`.
             */
            'branch_id' => [
                'bail',
                'required',
                'integer',
                app(BranchAccess::class)->constrainBranchExists(Rule::exists('branches', 'id'), $this->user()),
                BranchTakesNew::activeOnly(BranchWriteGuard::IMPORTS, $this->user()),
            ],

            /**
             * The date the extract represents, not the date it was uploaded.
             * Balances, accrued interest and past-due status in the file are all
             * true as of this date. Defaults to today (Asia/Manila) when the
             * client omits it; a future date is refused because "as of" a date
             * that has not happened cannot describe a balance.
             */
            'as_of_date' => ['nullable', 'date_format:Y-m-d', 'after:1999-12-31', 'before_or_equal:today'],

            // `array:customers,loans,share_capital` rejects unknown keys
            // outright rather than ignoring them, so a client that sends
            // `members` learns it named the file wrong here instead of at
            // assemble time.
            //
            // A run carries the members file, the share capital file, or both
            // (finish run round 2, decision 13): a cooperative may have no loans
            // to carry over, be adding a batch of new members, or bring only
            // share capital for members it already has. A loans file needs the
            // members file, since its rows find their members through the
            // members file of the same import.
            'files' => ['required', 'array:customers,loans,share_capital'],
            'files.customers' => [
                Rule::requiredIf(fn (): bool => $this->has('files.loans') || ! $this->has('files.share_capital')),
                'array:filename,size_bytes,sha256',
            ],
            'files.loans' => ['sometimes', 'array:filename,size_bytes,sha256'],
            // Writing share capital entries is `share_capital:create`'s, on top
            // of the `imports:process` every import needs. Asked again when the
            // rows are written (ShareCapitalRowImporter), since a run can wait
            // days and its starter can lose the access meanwhile.
            'files.share_capital' => [
                'sometimes',
                'array:filename,size_bytes,sha256',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! ($this->user()?->can('share_capital:create') ?? false)) {
                        $fail(self::SHARE_CAPITAL_NEEDS_PERMISSION);
                    }
                },
            ],

            'files.*.filename' => ['required', 'string', 'max:255'],
            'files.*.size_bytes' => ['required', 'integer', 'min:1', 'max:'.$maxBytes],
            'files.*.sha256' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ];
    }

    public function messages(): array
    {
        $maxMb = (int) round(app(CsvImportUploadService::class)->maxFileBytes() / 1048576);

        return [
            'files.required' => self::CHOOSE_A_FILE,
            'files.customers.required' => $this->has('files.loans')
                ? 'A loans file needs its members file in the same import.'
                : self::CHOOSE_A_FILE,
            'branch_id.required' => 'Choose the branch these members and loans belong to. Every imported loan needs one, and the file cannot supply it.',
            'branch_id.exists' => 'Choose one of your own branches for this import.',
            'files.*.size_bytes.max' => "That file is larger than the {$maxMb} MB import limit.",
            'files.*.sha256.regex' => 'Each file needs a SHA-256 digest as 64 hexadecimal characters.',
            'as_of_date.before_or_equal' => 'The as-of date is the date the extract was taken; it cannot be in the future.',
        ];
    }
}
