<?php

namespace App\Http\Requests\Cda\Reports;

use App\Http\Requests\Cda\CdaFormRequest;
use App\Models\CdaReport;
use App\Services\Cda\CdaAccess;

/**
 * PUT /cda/reports/{report} {fields: [{field_key, value?, remarks?,
 * override_reason?, evidence?, revert?}]}: what staff type on a draft. Needs
 * `cda:prepare`, `branches:view_all` and the report's restricted view where
 * it has one. Whether each field may take a value is the editor's check
 * (ReportEditor), with its messages in words.
 */
class UpdateCdaReportRequest extends CdaFormRequest
{
    protected function permission(): ?string
    {
        return CdaAccess::PREPARE;
    }

    public function authorize(): bool
    {
        parent::authorize();

        $report = $this->route('report');

        if ($report instanceof CdaReport) {
            app(CdaAccess::class)->authorizeType($this->user(), $report->report_type);
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fields' => ['required', 'array', 'min:1', 'max:200'],
            'fields.*' => ['required', 'array:field_key,value,remarks,override_reason,evidence,revert'],
            'fields.*.field_key' => ['required', 'string', 'max:121', 'distinct'],
            'fields.*.remarks' => ['nullable', 'string', 'max:2000'],
            'fields.*.override_reason' => ['nullable', 'string', 'max:500'],
            'fields.*.evidence' => ['nullable', 'array', 'max:20'],
            'fields.*.evidence.*' => ['array:document_id,label,note'],
            'fields.*.evidence.*.document_id' => ['nullable', 'integer', 'exists:cda_documents,id'],
            'fields.*.evidence.*.label' => ['nullable', 'string', 'max:200'],
            'fields.*.evidence.*.note' => ['nullable', 'string', 'max:500'],
            'fields.*.revert' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'fields.required' => 'Send at least one field to save.',
            'fields.max' => 'Save up to 200 fields at a time.',
            'fields.*.array' => 'Each field holds only field_key, value, remarks, override_reason, evidence and revert.',
            'fields.*.field_key.required' => 'Each field needs its field_key, such as membership.members_total.',
            'fields.*.field_key.distinct' => 'A field is sent twice.',
            'fields.*.remarks.max' => 'Remarks take up to 2,000 characters.',
            'fields.*.override_reason.max' => 'An override reason takes up to 500 characters.',
            'fields.*.evidence.max' => 'A field takes up to 20 pieces of evidence.',
            'fields.*.evidence.*.array' => 'Evidence holds a document_id, a label and a note.',
            'fields.*.evidence.*.document_id.exists' => 'That evidence document doesn\'t exist.',
            'fields.*.revert.boolean' => 'revert is true or false.',
        ];
    }
}
