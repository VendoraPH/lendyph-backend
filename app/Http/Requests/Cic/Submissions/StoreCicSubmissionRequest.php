<?php

namespace App\Http\Requests\Cic\Submissions;

use App\Models\CicSubmission;
use App\Services\Cic\CicAccess;
use App\Support\Sentence;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /cic/submissions {capture_id, submission_type 0|1, comments?,
 * confirm_warnings?, corrects_submission_id?, correction_reason?}. Needs
 * `cic:prepare` and `branches:view_all`, checked before validation so a
 * caller without them gets 403, never field errors. Comments refuse a "|"
 * and any control, invisible format, line or paragraph separator character,
 * as the file builder does (review S-L4). A correction (type 1)
 * names the file it corrects and why; a standard file names neither.
 */
class StoreCicSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(CicAccess::class)->authorize($this->user(), CicAccess::PREPARE);

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $correction = (string) CicSubmission::TYPE_CORRECTION;

        return [
            'capture_id' => ['required', 'integer', 'exists:cic_captures,id'],
            'submission_type' => ['required', 'integer', 'in:0,1'],
            'comments' => ['nullable', 'string', 'max:100', 'not_regex:/[|\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u'],
            'confirm_warnings' => ['sometimes', 'boolean'],
            'corrects_submission_id' => ["required_if:submission_type,{$correction}", "prohibited_unless:submission_type,{$correction}", 'nullable', 'integer', 'exists:cic_submissions,id'],
            'correction_reason' => ["required_if:submission_type,{$correction}", "prohibited_unless:submission_type,{$correction}", 'nullable', 'bail', 'string', 'max:500', 'regex:'.Sentence::HAS_WORDS],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'capture_id.required' => 'Choose the capture to make the file from.',
            'capture_id.integer' => 'Choose the capture to make the file from.',
            'capture_id.exists' => 'There is no such capture.',
            'submission_type.required' => 'Choose Standard reporting or Correction.',
            'submission_type.integer' => 'The submission type is 0 (standard reporting) or 1 (correction).',
            'submission_type.in' => 'The submission type is 0 (standard reporting) or 1 (correction).',
            'comments.string' => 'The comments are text.',
            'comments.max' => 'The comments take up to 100 characters.',
            'comments.not_regex' => 'The comments can\'t hold a "|", a line break, or a control or invisible character.',
            'confirm_warnings.boolean' => 'Say whether to go ahead despite the warnings.',
            'corrects_submission_id.required_if' => 'Choose the file this correction corrects.',
            'corrects_submission_id.prohibited_unless' => 'Only a correction names a file it corrects.',
            'corrects_submission_id.integer' => 'Choose the file this correction corrects.',
            'corrects_submission_id.exists' => 'There is no such CIC file.',
            'correction_reason.required_if' => 'Give the reason for the correction.',
            'correction_reason.prohibited_unless' => 'Only a correction takes a correction reason.',
            'correction_reason.string' => 'The correction reason is text.',
            'correction_reason.max' => 'The correction reason takes up to 500 characters.',
            'correction_reason.regex' => Sentence::NO_WORDS,
        ];
    }
}
