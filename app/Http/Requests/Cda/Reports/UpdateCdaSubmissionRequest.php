<?php

namespace App\Http\Requests\Cda\Reports;

use App\Http\Requests\Cda\CdaFormRequest;
use App\Models\CdaSubmission;
use App\Services\Cda\CdaAccess;

/**
 * PUT /cda/submissions/{submission}: the filing's references and remarks
 * only. Its method, date, status and snapshot are set by the workflow
 * (mark_submitted, mark_accepted, mark_for_correction). Needs `cda:submit`,
 * `branches:view_all` and the report's restricted view.
 */
class UpdateCdaSubmissionRequest extends CdaFormRequest
{
    protected function permission(): ?string
    {
        return CdaAccess::SUBMIT;
    }

    public function authorize(): bool
    {
        parent::authorize();

        $submission = $this->route('submission');

        if ($submission instanceof CdaSubmission) {
            app(CdaAccess::class)->authorizeType($this->user(), (string) $submission->report()->value('report_type'));
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cda_reference_no' => ['sometimes', 'nullable', 'string', 'max:100'],
            'acknowledgment_no' => ['sometimes', 'nullable', 'string', 'max:100'],
            'submitted_by_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'method' => ['prohibited'],
            'submitted_on' => ['prohibited'],
            'status' => ['prohibited'],
            'snapshot_id' => ['prohibited'],
            'report_id' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cda_reference_no.max' => 'The CDA reference number takes up to 100 characters.',
            'acknowledgment_no.max' => 'The acknowledgment number takes up to 100 characters.',
            'submitted_by_name.max' => 'The name takes up to 150 characters.',
            'remarks.max' => 'Remarks take up to 2,000 characters.',
            'method.prohibited' => 'How and when the report was filed is recorded by "mark as filed"; reopen the report to file it again.',
            'submitted_on.prohibited' => 'How and when the report was filed is recorded by "mark as filed"; reopen the report to file it again.',
            'status.prohibited' => 'The filing\'s status follows the report: use mark accepted or mark for correction.',
            'snapshot_id.prohibited' => 'A filing stays linked to the version that was filed.',
            'report_id.prohibited' => 'A filing stays linked to its report.',
        ];
    }
}
