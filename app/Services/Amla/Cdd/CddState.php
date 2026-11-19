<?php

namespace App\Services\Amla\Cdd;

use App\Models\AmlaIdVerification;
use App\Models\AmlaProfile;
use App\Models\AmlaRiskAssessment;
use App\Models\Borrower;
use App\Models\GCashNonMember;
use Carbon\CarbonImmutable;

/**
 * Where a subject's CDD stands (task §10), worked out on every read from the
 * member or walk-in record and the AMLA profile, so it is never stale:
 * - the ID's state: verified, not verified, expired or failed;
 * - the completeness check: what is missing, expired or expiring, in words;
 * - whether enhanced due diligence is required and done;
 * - the CDD status, the first that applies of: Not Started, Review Overdue,
 *   Enhanced Due Diligence Required, Additional Information Required,
 *   Pending Verification, Verified.
 *
 * It reads only what CDD needs to know is present, never a member's personal
 * data into the result.
 */
final class CddState
{
    public const TIMEZONE = 'Asia/Manila';

    /** An ID that expires within this many days is listed as expiring. */
    public const EXPIRING_WITHIN_DAYS = 30;

    /** @var array<string, string> */
    public const STATUSES = [
        'not_started' => 'Not Started',
        'pending_verification' => 'Pending Verification',
        'verified' => 'Verified',
        'additional_info_required' => 'Additional Information Required',
        'edd_required' => 'Enhanced Due Diligence Required',
        'review_overdue' => 'Review Overdue',
    ];

    /** @var array<string, string> */
    public const ID_STATES = [
        'verified' => 'Verified',
        'not_verified' => 'Not verified',
        'expired' => 'Expired',
        'failed' => 'Could not be verified',
    ];

    /**
     * @return array{
     *     status: string,
     *     id_state: string,
     *     latest_verification: AmlaIdVerification|null,
     *     latest_assessment: AmlaRiskAssessment|null,
     *     edd_required: bool,
     *     edd_completed: bool,
     *     completeness: array{complete: bool, missing: list<array{key: string, words: string}>, expired: list<array{key: string, words: string}>, expiring_soon: list<array{key: string, words: string}>}
     * }
     */
    public function of(Borrower|GCashNonMember $subject, ?AmlaProfile $profile): array
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $verification = $profile?->idVerifications()->latest('id')->first();
        $assessment = $profile?->riskAssessments()->latest('id')->first();
        $idState = self::idState($verification, $today);
        $eddRequired = $profile?->edd_required_since !== null;
        $eddCompleted = $eddRequired && $profile->reviews()
            ->where('kind', 'edd')
            ->where('created_at', '>=', $profile->edd_required_since)
            ->exists();

        $completeness = $this->completeness($subject, $profile, $idState, $verification, $assessment, $today);

        return [
            'status' => $this->status($profile, $completeness, $idState, $eddRequired && ! $eddCompleted, $today),
            'id_state' => $idState,
            'latest_verification' => $verification,
            'latest_assessment' => $assessment,
            'edd_required' => $eddRequired,
            'edd_completed' => $eddCompleted,
            'completeness' => $completeness,
        ];
    }

    public static function idState(?AmlaIdVerification $verification, CarbonImmutable $today): string
    {
        if ($verification === null) {
            return 'not_verified';
        }

        if ($verification->result !== 'verified') {
            return 'failed';
        }

        if ($verification->expires_on !== null && $verification->expires_on->toDateString() < $today->toDateString()) {
            return 'expired';
        }

        return 'verified';
    }

    /**
     * @param  array{missing: list<array{key: string, words: string}>}  $completeness
     */
    private function status(?AmlaProfile $profile, array $completeness, string $idState, bool $eddOutstanding, CarbonImmutable $today): string
    {
        if ($profile === null) {
            return 'not_started';
        }

        if ($profile->next_review_on !== null && $profile->next_review_on->toDateString() < $today->toDateString()) {
            return 'review_overdue';
        }

        if ($eddOutstanding) {
            return 'edd_required';
        }

        if (array_filter($completeness['missing'], fn (array $item) => $item['key'] !== 'id_verified') !== []) {
            return 'additional_info_required';
        }

        return $idState === 'verified' ? 'verified' : 'pending_verification';
    }

    /**
     * @return array{complete: bool, missing: list<array{key: string, words: string}>, expired: list<array{key: string, words: string}>, expiring_soon: list<array{key: string, words: string}>}
     */
    private function completeness(
        Borrower|GCashNonMember $subject,
        ?AmlaProfile $profile,
        string $idState,
        ?AmlaIdVerification $verification,
        ?AmlaRiskAssessment $assessment,
        CarbonImmutable $today,
    ): array {
        $missing = [];
        $add = function (string $key, string $words) use (&$missing): void {
            $missing[] = ['key' => $key, 'words' => $words];
        };

        if ($subject instanceof Borrower) {
            if (blank($subject->first_name) || blank($subject->last_name)) {
                $add('full_name', 'The full legal name is missing');
            }

            if ($subject->birthdate === null) {
                $add('birthdate', 'The date of birth is missing');
            }

            if (blank($subject->address) && blank($subject->street_address) && blank($subject->city)) {
                $add('address', 'The current address is missing');
            }

            if (blank($subject->contact_number) && blank($subject->email)) {
                $add('contact', 'The contact information is missing');
            }

            if (! self::memberHasValidId($subject)) {
                $add('id_on_file', $subject->external_account_no !== null ? 'No valid ID on file (imported member)' : 'No valid ID on file');
            }
        } else {
            if (blank($subject->full_name)) {
                $add('full_name', 'The full name is missing');
            }

            if (blank($subject->mobile_number)) {
                $add('contact', 'The mobile number is missing');
            }

            if (blank($subject->id_type) || blank($subject->id_number)) {
                $add('id_on_file', 'No ID on record');
            }
        }

        if (blank($profile?->nationality)) {
            $add('nationality', 'The nationality is missing');
        }

        if (blank($profile?->occupation) && ! ($subject instanceof Borrower && filled($subject->employer_or_business))) {
            $add('occupation', 'The occupation or business is missing');
        }

        if (blank($profile?->source_of_funds)) {
            $add('source_of_funds', 'The source of funds is missing');
        }

        if (blank($profile?->purpose)) {
            $add('purpose', 'The purpose of the relationship is missing');
        }

        if ($profile?->expected_monthly_centavos === null && $profile?->expected_monthly_count === null) {
            $add('expected_activity', 'The expected account activity is missing');
        }

        if ($profile?->pep_status === null) {
            $add('pep_status', 'The politically exposed person question hasn\'t been answered');
        }

        if ($assessment === null) {
            $add('risk_assessed', 'No AML risk assessment yet');
        }

        if ($idState !== 'verified') {
            $add('id_verified', 'The ID hasn\'t been verified');
        }

        $expired = [];
        $expiringSoon = [];

        if ($idState === 'expired') {
            $expired[] = ['key' => 'id_expired', 'words' => 'The verified ID expired on '.$verification->expires_on->format('F j, Y')];
        }

        if ($idState === 'verified' && $verification->expires_on !== null
            && $verification->expires_on->toDateString() <= $today->addDays(self::EXPIRING_WITHIN_DAYS)->toDateString()) {
            $expiringSoon[] = ['key' => 'id_expiring', 'words' => 'The verified ID expires on '.$verification->expires_on->format('F j, Y')];
        }

        return ['complete' => $missing === [], 'missing' => $missing, 'expired' => $expired, 'expiring_soon' => $expiringSoon];
    }

    /** Whether a member has a `valid_id` document with its number (the round fz rule). */
    public static function memberHasValidId(Borrower $member): bool
    {
        if (isset($member->valid_id_documents_count)) {
            return (int) $member->valid_id_documents_count > 0;
        }

        return $member->documents()->where('type', 'valid_id')->whereNotNull('id_number')->exists();
    }

    /** Whether EDD applies: a High risk level, or any politically exposed person status. */
    public static function eddApplies(string $level, ?string $pepStatus): bool
    {
        return $level === 'high' || in_array($pepStatus, ['pep', 'pep_family', 'pep_associate'], true);
    }
}
