<?php

namespace App\Services\LoanOrigination\Intake;

use App\Models\LoanProduct;
use App\Models\OriginationApplication;
use App\Models\OriginationDocument;
use App\Models\OriginationLink;
use App\Models\OriginationProductSetting;
use App\Services\LoanOrigination\DraftRules;
use App\Support\PhilippineMobile;

/**
 * What the applicant's side reads (origination decisions 5 and 8):
 *
 * - state(): their own draft, its completion, the server's declared totals
 *   and what still blocks a submit, and their documents (never a file link);
 * - track(): a whitelist only (decision 8): the status in the applicant's
 *   words, what to do next, the documents they may replace, the decision
 *   notice staff wrote for them and the office's contact. Never notes,
 *   assessments, flags, staff names or the draft.
 */
final class PublicApplicationPresenter
{
    /** Internal status => what the applicant is told. */
    public const FACING = [
        'draft' => 'draft',
        'incomplete' => 'draft',
        'submitted' => 'received',
        'document_verification' => 'under_review',
        'additional_info_required' => 'additional_info_required',
        'under_assessment' => 'under_review',
        'pending_approval' => 'under_review',
        'approved' => 'approved',
        'converted' => 'approved',
        'rejected' => 'not_approved',
        'withdrawn' => 'withdrawn',
        'expired' => 'expired',
    ];

    public const MESSAGES = [
        'draft' => 'Your application hasn\'t been submitted yet.',
        'received' => 'We received your application and will review it soon.',
        'under_review' => 'Your application is being reviewed.',
        'additional_info_required' => 'We need more from you before we can continue. See what to do below.',
        'approved' => 'Your application was approved. The office will contact you about the next steps.',
        'not_approved' => 'Your application was not approved.',
        'withdrawn' => 'Your application was withdrawn.',
        'expired' => 'Your application expired before it was submitted.',
    ];

    /** Statuses in which staff may ask for a document again and the applicant may replace it. */
    public const REPLACEABLE_STATUSES = ['submitted', 'document_verification', 'additional_info_required'];

    /** Document statuses the applicant may replace while tracking. */
    public const REPLACEABLE_DOCUMENTS = ['rejected', 'replacement_requested'];

    private const DECISIONS = ['approved', 'converted', 'rejected', 'withdrawn'];

    public function __construct(
        private readonly DraftRules $rules,
        private readonly PublicPortal $portal,
    ) {}

    /** @return array<string, mixed> */
    public function state(OriginationApplication $application): array
    {
        $draft = $application->draft ?? $this->rules->empty();
        $product = $application->loan_product_id === null ? null : LoanProduct::query()->find($application->loan_product_id);

        return [
            'reference_number' => $application->reference_number,
            'status' => $application->status,
            'last_completed_step' => $application->last_completed_step,
            'completion_percent' => $application->completion_percent,
            'draft' => $draft,
            'documents' => $this->documents($application),
            'verified_contact' => [
                'mobile' => $application->mobile === null ? null : PhilippineMobile::mask($application->mobile),
                'email' => null,
            ],
            'declared_totals' => $this->rules->declaredTotals($draft),
            'missing_fields' => (object) $this->missingFields($draft, $product, $application->loan_product_id),
        ];
    }

    /**
     * Every listed document type, uploaded or not, in the settings' order.
     *
     * @return list<array<string, mixed>>
     */
    public function documents(OriginationApplication $application): array
    {
        $uploaded = $application->documents()->with('currentVersion')->get()->keyBy('type');

        return array_map(
            fn (array $type) => $this->document($type, $uploaded->get($type['type'])),
            $this->portal->documentTypes($application->loan_product_id),
        );
    }

    /**
     * @param  array{type: string, label: string, description: ?string, required: bool}  $type
     * @return array<string, mixed>
     */
    public function document(array $type, ?OriginationDocument $document): array
    {
        $version = $document?->currentVersion;

        return [
            'id' => $document?->public_id,
            'type' => $type['type'],
            'label' => $type['label'],
            'required' => $type['required'],
            'status' => $document?->status ?? 'not_submitted',
            'file_name' => $version?->file_name,
            'mime_type' => $version?->mime_type,
            'size_bytes' => $version?->size_bytes,
            'uploaded_at' => $version?->uploaded_at,
            'preview_url' => null,
            'verified_by_name' => null,
            'verified_at' => null,
            'rejection_reason' => null,
            'notes' => null,
            'versions' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function track(OriginationApplication $application, OriginationLink $link): array
    {
        $facing = self::FACING[$application->status] ?? 'under_review';
        $documents = collect($this->documents($application));
        $replaceable = in_array($application->status, self::REPLACEABLE_STATUSES, true)
            ? $documents->filter(fn (array $d) => in_array($d['status'], self::REPLACEABLE_DOCUMENTS, true))->values()
            : collect();
        $outstanding = in_array($application->status, self::REPLACEABLE_STATUSES, true)
            ? $documents->filter(fn (array $d) => $d['required'] && in_array($d['status'], ['not_submitted', ...self::REPLACEABLE_DOCUMENTS], true))
            : collect();

        return [
            'reference_number' => (string) $application->reference_number,
            'submitted_at' => $application->submitted_at,
            'status' => $facing,
            'status_message' => self::MESSAGES[$facing],
            'outstanding_requirements' => $outstanding->pluck('label')->values()->all(),
            'requested_actions' => $replaceable->map(fn (array $d) => "Upload a new copy of your {$d['label']}.")->values()->all(),
            'replaceable_documents' => $replaceable->all(),
            'decision_notice' => in_array($application->status, self::DECISIONS, true) ? $application->applicant_message : null,
            'contact' => [
                'phone' => $link->contact_phone ?? $this->portal->officeContact(),
                'email' => $link->contact_email,
            ],
        ];
    }

    /**
     * What blocks a submit, keyed as the draft's own errors are
     * (`draft.<section>.<field>`), with the product's required fields.
     *
     * @param  array<string, array<string, mixed>>  $draft
     * @return array<string, string>
     */
    public function missingFields(array $draft, ?LoanProduct $product, ?int $productId): array
    {
        $missing = [];

        foreach ($this->rules->missingFields($draft, $product, null, $this->requiredFields($productId)) as $key => $message) {
            $missing["draft.{$key}"] = $message;
        }

        return $missing;
    }

    /** @return list<string> */
    public function requiredFields(?int $productId): array
    {
        if ($productId === null) {
            return [];
        }

        return array_values((array) (OriginationProductSetting::query()->where('loan_product_id', $productId)->first()?->required_fields ?? []));
    }
}
