<?php

namespace App\Http\Resources\Amla;

use App\Models\AmlaIdVerification;
use App\Models\AmlaProfile;
use App\Models\AmlaProfileChange;
use App\Models\AmlaReview;
use App\Models\AmlaRiskAssessment;
use App\Models\AmlaRiskRuleVersion;
use App\Models\Borrower;
use App\Models\GCashNonMember;
use App\Models\SavingsAccount;
use App\Services\Accounting\Money;
use App\Services\Amla\Cdd\CddState;
use App\Services\Amla\Cdd\IdTypes;

/**
 * How the AMLA CDD endpoints present a subject (AMLA-B4): who it is (type,
 * id, name, code, branch, and the KYC backlog markers), never the member
 * record's personal data, which stays on the member screens. Then the AMLA
 * profile's own answers, the completeness check, the CDD status and the
 * history, each with words beside its keys, and money as two-decimal strings
 * with a ₱ `..._words`.
 */
final class AmlaCddPresenter
{
    /** @return array<string, mixed> */
    public static function subject(Borrower|GCashNonMember $subject): array
    {
        if ($subject instanceof Borrower) {
            $subject->loadMissing('branch');

            return [
                'type' => AmlaProfile::MEMBER,
                'id' => (int) $subject->id,
                'name' => (string) $subject->full_name,
                'code' => $subject->borrower_code,
                'branch' => $subject->branch === null ? null : ['id' => (int) $subject->branch->id, 'name' => $subject->branch->name],
                'member_status' => $subject->status,
                'is_imported' => $subject->external_account_no !== null,
                'has_valid_id' => CddState::memberHasValidId($subject),
            ];
        }

        return [
            'type' => AmlaProfile::WALK_IN,
            'id' => (int) $subject->id,
            'name' => (string) $subject->full_name,
            'code' => null,
            'branch' => null,
            'member_status' => null,
            'is_imported' => false,
            'has_valid_id' => filled($subject->id_type) && filled($subject->id_number),
        ];
    }

    /**
     * The whole CDD view of one subject.
     *
     * @param  array<string, mixed>  $state  CddState::of()
     * @return array<string, mixed>
     */
    public static function show(Borrower|GCashNonMember $subject, ?AmlaProfile $profile, array $state): array
    {
        return [
            'subject' => self::subject($subject),
            'profile' => self::profile($profile, $state),
            'cdd_status' => $state['status'],
            'cdd_status_words' => CddState::STATUSES[$state['status']],
            'id_state' => $state['id_state'],
            'id_state_words' => CddState::ID_STATES[$state['id_state']],
            'completeness' => $state['completeness'],
            'risk' => $state['latest_assessment'] === null ? null : self::assessment($state['latest_assessment']),
            'id_verifications' => $profile === null ? [] : $profile->idVerifications()->with('verifier')->latest('id')->limit(20)->get()
                ->map(fn (AmlaIdVerification $verification) => self::verification($verification))->values()->all(),
            'risk_assessments' => $profile === null ? [] : $profile->riskAssessments()->with('assessor', 'ruleVersion')->latest('id')->limit(20)->get()
                ->map(fn (AmlaRiskAssessment $assessment) => self::assessment($assessment))->values()->all(),
            'reviews' => $profile === null ? [] : $profile->reviews()->with('reviewer')->latest('id')->limit(20)->get()
                ->map(fn (AmlaReview $review) => self::review($review))->values()->all(),
            'changes' => $profile === null ? [] : $profile->profileChanges()->with('user')->latest('id')->limit(20)->get()
                ->map(fn (AmlaProfileChange $change) => self::change($change))->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function profile(?AmlaProfile $profile, array $state): array
    {
        $centavos = $profile?->expected_monthly_centavos;

        return [
            'exists' => $profile !== null,
            'id' => $profile?->id,
            'nationality' => $profile?->nationality,
            'occupation' => $profile?->occupation,
            'source_of_funds' => $profile?->source_of_funds,
            'source_of_funds_words' => $profile?->source_of_funds === null ? null : (SavingsAccount::SOURCES_OF_FUNDS[$profile->source_of_funds] ?? $profile->source_of_funds),
            'source_of_funds_other' => $profile?->source_of_funds_other,
            'purpose' => $profile?->purpose,
            'expected_monthly_amount' => $centavos === null ? null : sprintf('%d.%02d', intdiv($centavos, 100), $centavos % 100),
            'expected_monthly_amount_words' => $centavos === null ? null : Money::format($centavos),
            'expected_monthly_count' => $profile?->expected_monthly_count,
            'pep_status' => $profile?->pep_status,
            'pep_status_words' => $profile?->pep_status === null ? null : AmlaProfile::PEP_STATUSES[$profile->pep_status],
            'pep_details' => $profile?->pep_details,
            'adverse_info' => $profile?->adverse_info,
            'adverse_info_note' => $profile?->adverse_info_note,
            'last_review_on' => $profile?->last_review_on?->toDateString(),
            'next_review_on' => $profile?->next_review_on?->toDateString(),
            'edd_required' => $state['edd_required'],
            'edd_required_since' => $profile?->edd_required_since,
            'edd_completed' => $state['edd_completed'],
            'updated_at' => $profile?->updated_at,
        ];
    }

    /** @return array<string, mixed> */
    public static function verification(AmlaIdVerification $verification): array
    {
        return [
            'id' => (int) $verification->id,
            'document_id' => $verification->document_id,
            'id_type' => $verification->id_type,
            'id_type_words' => IdTypes::words($verification->id_type),
            'id_number_masked' => '****'.($verification->id_number_last4 ?? ''),
            'method' => $verification->method,
            'method_words' => AmlaIdVerification::METHODS[$verification->method] ?? $verification->method,
            'result' => $verification->result,
            'result_words' => AmlaIdVerification::RESULTS[$verification->result] ?? $verification->result,
            'expires_on' => $verification->expires_on?->toDateString(),
            'notes' => $verification->notes,
            'verified_by' => AmlaPresenter::user($verification->verifier),
            'verified_at' => $verification->created_at,
        ];
    }

    /** @return array<string, mixed> */
    public static function assessment(AmlaRiskAssessment $assessment): array
    {
        return [
            'id' => (int) $assessment->id,
            'level' => $assessment->level,
            'level_words' => AmlaRiskAssessment::LEVELS[$assessment->level],
            'computed_level' => $assessment->computed_level,
            'computed_level_words' => AmlaRiskAssessment::LEVELS[$assessment->computed_level],
            'score' => $assessment->score,
            'overridden' => $assessment->overridden,
            'override_carried' => $assessment->override_carried,
            'override_reason' => $assessment->override_reason,
            'factors' => array_values($assessment->factors),
            'trigger' => $assessment->trigger,
            'trigger_words' => AmlaRiskAssessment::TRIGGERS[$assessment->trigger] ?? $assessment->trigger,
            'rule_version' => $assessment->ruleVersion?->version,
            'rules_to_verify' => (bool) $assessment->ruleVersion?->to_verify,
            'assessed_by' => AmlaPresenter::user($assessment->assessor),
            'assessed_at' => $assessment->created_at,
        ];
    }

    /** @return array<string, mixed> */
    public static function review(AmlaReview $review): array
    {
        return [
            'id' => (int) $review->id,
            'kind' => $review->kind,
            'kind_words' => AmlaReview::KINDS[$review->kind] ?? $review->kind,
            'outcome' => $review->outcome,
            'outcome_words' => AmlaReview::OUTCOMES[$review->outcome] ?? $review->outcome,
            'notes' => $review->notes,
            'reviewed_on' => $review->reviewed_on->toDateString(),
            'next_review_on' => $review->next_review_on->toDateString(),
            'level_at_review' => $review->level_at_review,
            'reviewed_by' => AmlaPresenter::user($review->reviewer),
        ];
    }

    /** @return array<string, mixed> */
    public static function change(AmlaProfileChange $change): array
    {
        return [
            'id' => (int) $change->id,
            'fields' => array_keys($change->changes),
            'changes' => $change->changes,
            'changed_by' => AmlaPresenter::user($change->user),
            'changed_at' => $change->created_at,
        ];
    }

    /** @return array<string, mixed> */
    public static function ruleVersion(AmlaRiskRuleVersion $version): array
    {
        return [
            'id' => (int) $version->id,
            'version' => $version->version,
            'to_verify' => $version->to_verify,
            'definition' => $version->definition,
            'effective_at' => $version->effective_at,
            'source' => $version->request_id === null ? 'system_default' : 'approved_change',
            'request_id' => $version->request_id,
            'approved_by' => AmlaPresenter::user($version->approver),
        ];
    }

    /**
     * One worklist row.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function row(Borrower|GCashNonMember $subject, ?AmlaProfile $profile, array $state): array
    {
        $assessment = $state['latest_assessment'];

        return [
            ...array_intersect_key(self::subject($subject), array_flip(['type', 'id', 'name', 'code', 'branch', 'is_imported'])),
            'cdd_status' => $state['status'],
            'cdd_status_words' => CddState::STATUSES[$state['status']],
            'risk_level' => $assessment?->level,
            'risk_level_words' => $assessment === null ? null : AmlaRiskAssessment::LEVELS[$assessment->level],
            'next_review_on' => $profile?->next_review_on?->toDateString(),
            'missing_count' => count($state['completeness']['missing']),
        ];
    }
}
