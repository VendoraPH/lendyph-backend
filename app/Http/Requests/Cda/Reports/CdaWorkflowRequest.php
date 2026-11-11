<?php

namespace App\Http\Requests\Cda\Reports;

use App\Http\Requests\Cda\CdaAttributeWords;
use App\Models\CdaReport;
use App\Models\CdaSubmission;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Reports\ReportStages;
use App\Services\Cda\Reports\ReportWorkflow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /cda/reports/{report}/workflow {action, reason?, confirm_warnings?,
 * stage?, submission?} (design §5). The caller needs `branches:view_all`,
 * the action's own permission (ReportWorkflow::ACTIONS) and the report's
 * restricted view, checked before validation so they get 403, never field
 * errors. An unknown action needs only `cda:view` to be told it's unknown.
 */
class CdaWorkflowRequest extends FormRequest
{
    use CdaAttributeWords;

    public function authorize(): bool
    {
        $access = app(CdaAccess::class);
        $action = $this->input('action');
        $access->authorize($this->user(), is_string($action) && isset(ReportWorkflow::ACTIONS[$action]) ? ReportWorkflow::ACTIONS[$action]['permission'] : CdaAccess::VIEW);

        $report = $this->route('report');

        if ($report instanceof CdaReport) {
            $access->authorizeType($this->user(), $report->report_type);
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $report = $this->route('report');
        $stages = $report instanceof CdaReport ? (ReportStages::STAGES[$report->report_type] ?? []) : [];

        return [
            'action' => ['required', 'string', Rule::in(array_keys(ReportWorkflow::ACTIONS))],
            'reason' => [Rule::requiredIf(in_array($this->input('action'), ReportWorkflow::NEED_REASON, true)), 'nullable', 'string', 'max:500'],
            'confirm_warnings' => ['sometimes', 'boolean'],
            'stage' => [Rule::requiredIf($this->input('action') === 'set_stage'), 'nullable', 'string', Rule::in($stages)],
            'submission' => [Rule::requiredIf($this->input('action') === 'mark_submitted'), 'nullable', 'array:method,submitted_on,submitted_by_name,cda_reference_no,acknowledgment_no,remarks'],
            'submission.method' => [Rule::requiredIf($this->input('action') === 'mark_submitted'), 'nullable', 'string', Rule::in(CdaSubmission::METHODS)],
            'submission.submitted_on' => [Rule::requiredIf($this->input('action') === 'mark_submitted'), 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'submission.submitted_by_name' => ['nullable', 'string', 'max:150'],
            'submission.cda_reference_no' => ['nullable', 'string', 'max:80'],
            'submission.acknowledgment_no' => ['nullable', 'string', 'max:80'],
            'submission.remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'action.required' => 'Choose a workflow action.',
            'action.in' => 'That isn\'t a workflow action. Use one of: '.implode(', ', array_keys(ReportWorkflow::ACTIONS)).'.',
            'reason.required' => 'Give a reason for this step.',
            'reason.max' => 'A reason takes up to 500 characters.',
            'confirm_warnings.boolean' => 'confirm_warnings is true or false.',
            'stage.required' => 'Choose the stage to move to.',
            'stage.in' => 'That isn\'t a stage of this report.',
            'submission.required' => 'Record how and when the report was filed.',
            'submission.array' => 'The filing holds method, submitted_on, submitted_by_name, cda_reference_no, acknowledgment_no and remarks.',
            'submission.method.required' => 'Choose how the report was filed.',
            'submission.method.in' => 'The filing method is CAIS, e-CoopHub, email, physical or other.',
            'submission.submitted_on.required' => 'Give the date the report was filed.',
            'submission.submitted_on.date_format' => 'The filing date is a date (YYYY-MM-DD).',
            'submission.submitted_on.before_or_equal' => 'The filing date can\'t be in the future.',
            'submission.submitted_by_name.max' => 'The name takes up to 150 characters.',
            'submission.cda_reference_no.max' => 'The CDA reference number takes up to 80 characters.',
            'submission.acknowledgment_no.max' => 'The acknowledgment number takes up to 80 characters.',
            'submission.remarks.max' => 'Remarks take up to 2,000 characters.',
        ];
    }
}
