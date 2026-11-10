<?php

namespace App\Http\Requests\Cda\Registers;

use App\Http\Requests\Cda\CdaFormRequest;
use App\Models\CdaReport;
use App\Models\CdaReportingPeriod;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Registers\DocumentCategories;
use App\Services\Cda\Registers\DocumentRepository;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /cda/periods/{year}/documents (`cda:upload`), multipart: a new
 * document and its first version. The report, when named, is one of the
 * period's; the subject, when named, is a training, program or case that
 * exists. A restricted category or report also needs its view permission
 * (checked by the controller, 403).
 */
class CdaDocumentRequest extends CdaFormRequest
{
    public const SUBJECT_TYPES = ['training', 'program', 'case'];

    protected function permission(): ?string
    {
        return CdaAccess::UPLOAD;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => CdaUploadRules::file(),
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(DocumentCategories::uploadable())],
            'report_id' => ['nullable', 'integer'],
            'subject_type' => ['nullable', Rule::in(self::SUBJECT_TYPES)],
            'subject_id' => ['nullable', 'required_with:subject_type', 'integer'],
            'description' => ['nullable', 'string', 'max:5000'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            ...CdaVersionRequest::versionRules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...CdaUploadRules::messages('file'),
            'title.required' => 'Give the document a title.',
            'title.max' => 'The title can be at most 255 characters.',
            'category.required' => 'Choose a category from the list.',
            'category.in' => 'Choose a category from the list.',
            'report_id.integer' => 'Choose a report from the list.',
            'subject_type.in' => 'A document can be about a training, a program or a case.',
            'subject_id.required_with' => 'Choose the training, program or case the document is about.',
            'subject_id.integer' => 'Choose the training, program or case the document is about.',
            'description.max' => 'The description can be at most 5,000 characters.',
            'expires_on.date_format' => 'Give the expiry as a date (YYYY-MM-DD).',
            ...CdaVersionRequest::versionMessages(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $period = CdaReportingPeriod::query()->where('year', (int) $this->route('year'))->first();
            $reportId = $this->input('report_id');

            if ($period !== null && $reportId !== null && ! $validator->errors()->has('report_id')
                && ! CdaReport::query()->whereKey((int) $reportId)->where('period_id', $period->id)->exists()) {
                $validator->errors()->add('report_id', "Choose a report of the {$period->year} period.");
            }

            $type = $this->input('subject_type');

            if (is_string($type) && ! $validator->errors()->hasAny(['subject_type', 'subject_id'])
                && ! app(DocumentRepository::class)->subjectExists($type, (int) $this->input('subject_id'))) {
                $validator->errors()->add('subject_id', 'Choose the training, program or case the document is about.');
            }
        });
    }
}
