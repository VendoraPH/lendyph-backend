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
 * exists. A restricted category or a case also needs its view permission,
 * and a register's document its write permission (403, before any lookup);
 * a restricted report is checked by the controller. A mediation report's
 * documents go in the mediation category (422). A training's
 * certificate is added from the training, never here (422).
 */
class CdaDocumentRequest extends CdaFormRequest
{
    public const SUBJECT_TYPES = ['training', 'program', 'case'];

    protected function permission(): ?string
    {
        return CdaAccess::UPLOAD;
    }

    /**
     * `cda:upload` and `branches:view_all`, then what the category and the
     * kind of subject need, judged before any record is looked up so a
     * refusal never tells whether a case exists.
     */
    public function authorize(): bool
    {
        parent::authorize();
        app(DocumentRepository::class)->authorizeUploadOf($this->user(), $this->input('category'), $this->input('subject_type'));

        return true;
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

            $report = $reportId === null || $validator->errors()->has('report_id') ? null : CdaReport::query()->find((int) $reportId);

            if ($period !== null && $reportId !== null && ! $validator->errors()->has('report_id') && $report?->period_id !== $period->id) {
                $validator->errors()->add('report_id', "Choose a report of the {$period->year} period.");
            } elseif ($report?->report_type === 'mediation' && $this->input('category') !== 'mediation') {
                $validator->errors()->add('report_id', 'A mediation report\'s documents go in the mediation category.');
            }

            $type = $this->input('subject_type');

            if ($this->input('category') === DocumentCategories::CERTIFICATES && $type === 'training' && ! $validator->errors()->has('category')) {
                $validator->errors()->add('category', 'Add a certificate from the training.');

                return;
            }

            if (is_string($type) && ! $validator->errors()->hasAny(['subject_type', 'subject_id'])
                && ! app(DocumentRepository::class)->subjectExists($type, (int) $this->input('subject_id'))) {
                $validator->errors()->add('subject_id', 'Choose the training, program or case the document is about.');
            }
        });
    }
}
