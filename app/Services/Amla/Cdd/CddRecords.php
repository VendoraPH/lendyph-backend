<?php

namespace App\Services\Amla\Cdd;

use App\Models\AmlaIdVerification;
use App\Models\AmlaProfile;
use App\Models\AmlaProfileChange;
use App\Models\AmlaReview;
use App\Models\AmlaRiskAssessment;
use App\Models\Borrower;
use App\Models\Document;
use App\Models\GCashNonMember;
use App\Models\User;
use App\Rules\UniqueWalkInIdNumber;
use App\Services\Amla\Foundation\AmlaLog;
use App\Services\Amla\Foundation\AmlaSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every CDD write (AMLA design §4.8–10; task §9–10): profile answers, ID
 * verifications, risk assessments and reviews. Each runs in a transaction
 * under the profile's row lock, writes only AMLA tables, and logs to the AMLA
 * log by field name and record id, never by value.
 *
 * - A profile change keeps its old and new values in AmlaProfileChange and
 *   reassesses the risk when a risk factor changed (a change of
 *   circumstances, task §9).
 * - An ID verification reassesses too.
 * - An override stands until the computed level changes; then the computed
 *   level is back in force.
 * - EDD is required from the moment the level is High or the person is a
 *   PEP, until an EDD review is recorded after that moment.
 * - The next review falls the settings' cycle for the level after the last
 *   review (or the profile's start); a higher level can bring it forward,
 *   never push it back. A review sets it from the review's own date.
 */
final class CddRecords
{
    /** The KYC answers that feed a risk factor: changing one reassesses. */
    private const RISK_FIELDS = [
        'nationality', 'occupation', 'source_of_funds', 'expected_monthly_centavos', 'expected_monthly_count',
        'pep_status', 'adverse_info',
    ];

    public function __construct(
        private readonly CddSubjects $subjects,
        private readonly CddState $state,
        private readonly RiskRules $rules,
        private readonly RiskScorer $scorer,
        private readonly AmlaSettings $settings,
        private readonly AmlaLog $log,
    ) {}

    /**
     * Saves KYC answers (partial). An unchanged save writes nothing.
     *
     * @param  array<string, mixed>  $answers  validated, `expected_monthly_centavos` already in centavos
     */
    public function update(User $user, Borrower|GCashNonMember $subject, array $answers): AmlaProfile
    {
        return DB::transaction(function () use ($user, $subject, $answers) {
            [$profile, $created] = $this->lockedProfile($user, $subject);
            $changes = [];

            foreach (array_intersect_key($answers, array_flip(AmlaProfile::KYC_FIELDS)) as $field => $value) {
                $before = $profile->{$field};

                if ($before !== $value) {
                    $changes[$field] = ['from' => $before, 'to' => $value];
                    $profile->{$field} = $value;
                }
            }

            if ($changes === []) {
                if ($created) {
                    $this->assess($user, $subject, $profile, 'profile_change');
                }

                return $profile;
            }

            $profile->forceFill(['updated_by' => $user->id])->save();
            $change = AmlaProfileChange::query()->create(['profile_id' => $profile->id, 'user_id' => $user->id, 'changes' => $changes]);

            $this->log->record('cdd_profile_updated', $user, CddSubjects::type($subject), $subject->getKey(), [
                'profile_id' => $profile->id,
                'change_id' => $change->id,
                'fields' => array_keys($changes),
            ]);

            if ($created || array_intersect(array_keys($changes), self::RISK_FIELDS) !== []) {
                $this->assess($user, $subject, $profile, 'profile_change');
            }

            return $profile;
        });
    }

    /**
     * Records an ID check. A member's points at one of their own valid IDs; a
     * walk-in's reads the ID on its record.
     *
     * @param  array{document_id?: int|null, method: string, result: string, expires_on?: string|null, notes?: string|null}  $input
     *
     * @throws ValidationException 422 in words
     */
    public function verifyId(User $user, Borrower|GCashNonMember $subject, array $input): AmlaIdVerification
    {
        [$idType, $idNumber, $documentId] = $this->idUnderCheck($subject, $input['document_id'] ?? null);

        return DB::transaction(function () use ($user, $subject, $input, $idType, $idNumber, $documentId) {
            [$profile] = $this->lockedProfile($user, $subject);
            $normalised = UniqueWalkInIdNumber::normalise((string) $idNumber);

            $verification = AmlaIdVerification::query()->create([
                'profile_id' => $profile->id,
                'document_id' => $documentId,
                'id_type' => mb_substr($idType, 0, 100),
                'id_number_last4' => $normalised === '' ? null : mb_substr($normalised, -4),
                'method' => $input['method'],
                'result' => $input['result'],
                'expires_on' => $input['expires_on'] ?? null,
                'notes' => filled($input['notes'] ?? null) ? trim((string) $input['notes']) : null,
                'verified_by' => $user->id,
            ]);

            $this->log->record('cdd_id_verified', $user, CddSubjects::type($subject), $subject->getKey(), [
                'profile_id' => $profile->id,
                'verification_id' => $verification->id,
                'result' => $verification->result,
            ]);

            $this->assess($user, $subject, $profile, 'id_verification');

            return $verification;
        });
    }

    /**
     * Assesses now, by staff, optionally overriding the level with a reason.
     *
     * @throws ValidationException 422 when CDD hasn't started
     */
    public function assessNow(User $user, Borrower|GCashNonMember $subject, ?string $overrideLevel, ?string $overrideReason): AmlaRiskAssessment
    {
        return DB::transaction(function () use ($user, $subject, $overrideLevel, $overrideReason) {
            $profile = $this->existingLockedProfile($subject, 'Start the AMLA profile before assessing risk.');

            return $this->assess($user, $subject, $profile, $overrideLevel === null ? 'manual' : 'override', $overrideLevel, $overrideReason);
        });
    }

    /**
     * Records a review and sets the next one.
     *
     * @param  array{kind: string, outcome: string, notes?: string|null}  $input
     *
     * @throws ValidationException 422 when CDD hasn't started
     */
    public function review(User $user, Borrower|GCashNonMember $subject, array $input): AmlaReview
    {
        return DB::transaction(function () use ($user, $subject, $input) {
            $profile = $this->existingLockedProfile($subject, 'Start the AMLA profile before recording a review.');
            $level = $profile->riskAssessments()->latest('id')->value('level') ?? 'low';
            $today = CarbonImmutable::now(CddState::TIMEZONE)->startOfDay();
            $next = $today->addYears($this->reviewYears($level));

            $review = AmlaReview::query()->create([
                'profile_id' => $profile->id,
                'kind' => $input['kind'],
                'outcome' => $input['outcome'],
                'notes' => filled($input['notes'] ?? null) ? trim((string) $input['notes']) : null,
                'reviewed_on' => $today->toDateString(),
                'next_review_on' => $next->toDateString(),
                'level_at_review' => $level,
                'reviewed_by' => $user->id,
            ]);

            $profile->forceFill([
                'last_review_on' => $today->toDateString(),
                'next_review_on' => $next->toDateString(),
                'updated_by' => $user->id,
            ])->save();

            $this->log->record('cdd_review_recorded', $user, CddSubjects::type($subject), $subject->getKey(), [
                'profile_id' => $profile->id,
                'review_id' => $review->id,
                'kind' => $review->kind,
                'outcome' => $review->outcome,
            ]);

            return $review;
        });
    }

    /**
     * One assessment row under the current rules, then the profile's EDD date
     * and next review. Runs inside the caller's transaction and lock.
     */
    private function assess(
        User $user,
        Borrower|GCashNonMember $subject,
        AmlaProfile $profile,
        string $trigger,
        ?string $overrideLevel = null,
        ?string $overrideReason = null,
    ): AmlaRiskAssessment {
        $rules = $this->rules->current();
        $today = CarbonImmutable::now(CddState::TIMEZONE)->startOfDay();
        $idState = CddState::idState($profile->idVerifications()->latest('id')->first(), $today);
        $scored = $this->scorer->score($subject, $profile, $idState, $rules);
        $previous = $profile->riskAssessments()->latest('id')->first();

        $level = $scored['level'];
        $overridden = false;
        $carried = false;
        $reason = null;

        if ($overrideLevel !== null) {
            [$level, $overridden, $reason] = [$overrideLevel, true, trim((string) $overrideReason)];
        } elseif ($previous !== null && $previous->overridden && $previous->computed_level === $scored['level']) {
            [$level, $overridden, $carried, $reason] = [$previous->level, true, true, $previous->override_reason];
        }

        $assessment = AmlaRiskAssessment::query()->create([
            'profile_id' => $profile->id,
            'rule_version_id' => $rules->id,
            'score' => $scored['score'],
            'computed_level' => $scored['level'],
            'level' => $level,
            'overridden' => $overridden,
            'override_carried' => $carried,
            'override_reason' => $reason,
            'factors' => $scored['factors'],
            'trigger' => $trigger,
            'assessed_by' => $user->id,
        ]);

        $eddApplies = CddState::eddApplies($level, $profile->pep_status);
        $updates = ['edd_required_since' => $eddApplies ? ($profile->edd_required_since ?? now()) : null];
        $base = $profile->last_review_on !== null
            ? CarbonImmutable::parse($profile->last_review_on->toDateString(), CddState::TIMEZONE)
            : CarbonImmutable::parse($profile->created_at, CddState::TIMEZONE)->startOfDay();
        $candidate = $base->addYears($this->reviewYears($level))->toDateString();
        $current = $profile->next_review_on?->toDateString();
        $updates['next_review_on'] = $current === null ? $candidate : min($current, $candidate);

        $profile->forceFill($updates)->save();

        $this->log->record('cdd_risk_assessed', $user, CddSubjects::type($subject), $subject->getKey(), [
            'profile_id' => $profile->id,
            'assessment_id' => $assessment->id,
            'rule_version' => $rules->version,
            'trigger' => $trigger,
            'level' => $level,
            'overridden' => $overridden,
        ]);

        return $assessment;
    }

    private function reviewYears(string $level): int
    {
        return (int) $this->settings->current()->values['review_years_'.$level];
    }

    /**
     * The subject's profile under a row lock, created (empty) when CDD hasn't
     * started. Two first saves at once: the second finds the first's row.
     *
     * @return array{0: AmlaProfile, 1: bool} the profile, and whether this call created it
     */
    private function lockedProfile(User $user, Borrower|GCashNonMember $subject): array
    {
        $column = $subject instanceof Borrower ? 'borrower_id' : 'gcash_non_member_id';
        $existing = AmlaProfile::query()->where($column, $subject->getKey())->lockForUpdate()->first();

        if ($existing !== null) {
            return [$existing, false];
        }

        try {
            $profile = DB::transaction(fn () => AmlaProfile::query()->create([
                'subject_type' => CddSubjects::type($subject),
                $column => $subject->getKey(),
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]));
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) !== 1062) {
                throw $exception;
            }

            return [AmlaProfile::query()->where($column, $subject->getKey())->lockForUpdate()->firstOrFail(), false];
        }

        return [$profile->refresh(), true];
    }

    /** @throws ValidationException 422 with the message when CDD hasn't started */
    private function existingLockedProfile(Borrower|GCashNonMember $subject, string $message): AmlaProfile
    {
        $column = $subject instanceof Borrower ? 'borrower_id' : 'gcash_non_member_id';
        $profile = AmlaProfile::query()->where($column, $subject->getKey())->lockForUpdate()->first();

        if ($profile === null) {
            throw ValidationException::withMessages(['profile' => $message]);
        }

        return $profile;
    }

    /**
     * The ID being checked: its type, number and document.
     *
     * @return array{0: string, 1: string|null, 2: int|null}
     *
     * @throws ValidationException 422 in words
     */
    private function idUnderCheck(Borrower|GCashNonMember $subject, mixed $documentId): array
    {
        if ($subject instanceof GCashNonMember) {
            if (blank($subject->id_type) || blank($subject->id_number)) {
                throw ValidationException::withMessages(['id' => 'This walk-in has no ID on record, so there is nothing to verify.']);
            }

            return [(string) $subject->id_type, (string) $subject->id_number, null];
        }

        $document = $documentId === null ? null : Document::query()
            ->where('documentable_type', Borrower::class)
            ->where('documentable_id', $subject->getKey())
            ->where('type', 'valid_id')
            ->whereKey((int) $documentId)
            ->first();

        if ($document === null) {
            throw ValidationException::withMessages(['document_id' => 'Pick one of this member\'s own valid IDs.']);
        }

        return [(string) ($document->label ?: $document->custom_type_name ?: 'Valid ID'), $document->id_number, (int) $document->id];
    }
}
