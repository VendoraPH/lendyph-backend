<?php

namespace App\Services\LoanOrigination\Staff;

use App\Models\OriginationApplicantMessage;
use App\Models\OriginationApplication;
use App\Models\OriginationConsent;
use App\Models\OriginationDocument;
use App\Models\OriginationNote;
use App\Models\OriginationProductSetting;
use App\Models\OriginationStatusHistory;
use App\Models\User;
use App\Services\LoanOrigination\ApplicationTransitions;
use App\Services\LoanOrigination\Assessment\AssessmentPresenter;
use App\Services\LoanOrigination\DraftRules;
use App\Services\Portal\Read\ProfileReader;
use App\Support\PhilippineMobile;

/**
 * An online application as staff read it: the list row (summary()) and the
 * detail (detail()).
 *
 * Personal data (decision 16): the mobile, the email and the address lines
 * of the draft (street, barangay, postal code, employer and business
 * addresses) need `borrowers:view` and the application's branch; otherwise
 * the mobile and email are masked and the address lines read "On file".
 * `contacts_hidden` says which. Member matches need `borrowers:view`
 * (`member_matches_hidden`).
 *
 * `requested_term` is a length in `term_unit`, the loan product's own unit
 * (months or days), so the screens write the term without reading the
 * products; the unit reads null while no product is chosen.
 *
 * Money comes from the server: `declared_totals` is DraftRules'. The
 * assessment is LO-B4's: the list row carries the current revision's result,
 * and the detail the revision itself as the assessment screen shows it to
 * the viewer (AssessmentPresenter, with its rule on a linked member's loan
 * figures); both read null before the first run.
 */
final class ApplicationPresenter
{
    /** Draft lines that hold an address, masked without contact access. */
    private const ADDRESS_LINES = [
        'personal' => ['street_address', 'barangay', 'postal_code'],
        'employment' => ['employer_address', 'business_address'],
    ];

    public function __construct(
        private readonly StaffAccess $access,
        private readonly DraftRules $rules,
        private readonly ApplicationTransitions $transitions,
        private readonly DocumentPresenter $documents,
        private readonly MemberMatches $matches,
        private readonly AssessmentPresenter $assessments,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(OriginationApplication $application, User $viewer): array
    {
        $contacts = $this->access->canSeeContacts($viewer, $application);

        return [
            'id' => $application->id,
            'reference_number' => $application->reference_number,
            'applicant_name' => (string) ($application->applicant_name ?? ''),
            'mobile' => $this->mobile($application->mobile, $contacts),
            'loan_product_id' => $application->loan_product_id,
            'loan_product_name' => $application->loanProduct?->name,
            'requested_amount' => $application->requested_amount,
            'requested_term' => $application->requested_term,
            'term_unit' => $application->loanProduct?->term_unit?->value,
            'branch_id' => $application->branch_id,
            'branch_name' => $application->branch?->name,
            'status' => $application->status,
            'status_label' => ApplicationTransitions::label($application->status),
            'is_test' => (bool) $application->is_test,
            'assessment_result' => $application->latestAssessment?->result,
            'assigned_officer_id' => $application->assigned_officer_id,
            'assigned_officer_name' => $application->assignedOfficer?->full_name,
            'completion_percent' => (int) $application->completion_percent,
            'last_completed_step' => $application->last_completed_step,
            'loan_id' => $application->loan_id,
            'started_at' => $application->started_at?->toIso8601String(),
            'submitted_at' => $application->submitted_at?->toIso8601String(),
            'decided_at' => $application->decided_at?->toIso8601String(),
            'updated_at' => $application->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(OriginationApplication $application, User $viewer): array
    {
        $application->loadMissing([
            'branch', 'loanProduct', 'assignedOfficer', 'latestAssessment',
            'documents.currentVersion', 'documents.versions', 'documents.verifier',
            'notes.author', 'history.user', 'consents',
        ]);

        $contacts = $this->access->canSeeContacts($viewer, $application);
        $draft = array_replace_recursive($this->rules->empty(), (array) ($application->draft ?? []));
        $required = $this->documents->requiredTypes($application->loan_product_id);
        $handedIn = $application->documents
            ->filter(fn (OriginationDocument $d) => $d->current_version_id !== null && ! in_array($d->status, ['rejected', 'replacement_requested'], true))
            ->pluck('type')->all();
        $seesMembers = StaffAccess::canSeeMembers($viewer);
        $canUpdate = StaffAccess::canUpdate($viewer);

        $messages = OriginationApplicantMessage::query()->with('author')
            ->where('application_id', $application->id)->orderByDesc('id')->get();

        return [
            ...$this->summary($application, $viewer),
            'version' => (int) $application->version,
            'email' => $this->email($application->email, $contacts),
            'contacts_hidden' => ! $contacts,
            'draft' => $contacts ? $draft : $this->maskAddresses($draft),
            'declared_totals' => $this->rules->declaredTotals($draft),
            'missing_fields' => array_keys($this->rules->missingFields($draft, $application->loanProduct, null, $this->requiredFields($application->loan_product_id))),
            'missing_documents' => array_values(array_map(
                fn (string $type) => $this->documents->label($type),
                array_values(array_diff($required, $handedIn)),
            )),
            'documents' => $application->documents->sortBy('id')
                ->map(fn (OriginationDocument $d) => $this->documents->present($d, $required, $contacts))->values()->all(),
            'assessment' => $application->latestAssessment === null ? null : $this->assessments->present($application->latestAssessment, $application, $viewer),
            'notes' => $application->notes->sortBy('id')->map(fn (OriginationNote $note) => [
                'id' => $note->id,
                'body' => $note->body,
                'author_name' => $note->author?->full_name,
                'created_at' => $note->created_at?->toIso8601String(),
            ])->values()->all(),
            'history' => $application->history->sortByDesc('id')->map(fn (OriginationStatusHistory $row) => [
                'id' => $row->id,
                'from_status' => $row->from_status,
                'from_label' => $row->from_status === null ? null : ApplicationTransitions::label($row->from_status),
                'to_status' => $row->to_status,
                'to_label' => ApplicationTransitions::label($row->to_status),
                'note' => $row->note,
                'changed_by_name' => $this->actorName($row),
                'changed_at' => $row->created_at?->toIso8601String(),
            ])->values()->all(),
            'consents' => $application->consents->sortBy('id')->map(fn (OriginationConsent $consent) => [
                'type' => $consent->type,
                'version' => $consent->version,
                'accepted' => (bool) $consent->accepted,
                'accepted_at' => $consent->accepted_at?->toIso8601String(),
            ])->values()->all(),
            'applicant_messages' => $messages->map(fn (OriginationApplicantMessage $m) => ApplicantMessages::present($m))->values()->all(),
            'linked_borrower_id' => $application->borrower_id,
            'possible_member_matches' => $seesMembers ? $this->matches->for($application, $viewer) : [],
            'member_matches_hidden' => ! $seesMembers,
            'allowed_transitions' => $canUpdate ? $this->allowedTransitions($application, $viewer) : [],
        ];
    }

    /**
     * The staff moves from the current status that $viewer holds every
     * permission for.
     *
     * @return list<string>
     */
    public function allowedTransitions(OriginationApplication $application, User $viewer): array
    {
        return array_values(array_filter(
            $this->transitions->staffTargets($application->status),
            fn (string $to) => collect($this->transitions->permissionsFor($to))->every(fn (string $p) => $viewer->can($p)),
        ));
    }

    /**
     * The product's own required fields from its online settings.
     *
     * @return list<string>
     */
    private function requiredFields(?int $productId): array
    {
        if ($productId === null) {
            return [];
        }

        $fields = OriginationProductSetting::query()->where('loan_product_id', $productId)->first()?->required_fields;

        return array_values(array_map('strval', (array) ($fields ?? [])));
    }

    private function actorName(OriginationStatusHistory $row): string
    {
        return match ($row->actor_type) {
            ApplicationTransitions::ACTOR_APPLICANT => 'Applicant',
            ApplicationTransitions::ACTOR_SYSTEM => 'System',
            ApplicationTransitions::ACTOR_LOAN => 'Loan approval',
            default => $row->user?->full_name ?? 'Staff',
        };
    }

    private function mobile(?string $mobile, bool $contacts): ?string
    {
        if ($mobile === null || $mobile === '' || $contacts) {
            return $mobile === '' ? null : $mobile;
        }

        return ProfileReader::maskedMobile($mobile) ?? PhilippineMobile::mask($mobile);
    }

    private function email(?string $email, bool $contacts): ?string
    {
        if ($email === null || $email === '' || $contacts) {
            return $email === '' ? null : $email;
        }

        return ProfileReader::maskedEmail($email);
    }

    /**
     * @param  array<string, array<string, mixed>>  $draft
     * @return array<string, array<string, mixed>>
     */
    private function maskAddresses(array $draft): array
    {
        foreach (self::ADDRESS_LINES as $section => $fields) {
            foreach ($fields as $field) {
                if (filled($draft[$section][$field] ?? null)) {
                    $draft[$section][$field] = 'On file';
                }
            }
        }

        return $draft;
    }
}
