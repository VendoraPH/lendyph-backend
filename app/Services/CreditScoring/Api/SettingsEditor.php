<?php

namespace App\Services\CreditScoring\Api;

use App\Models\CreditScoringSetting;
use App\Models\User;
use App\Services\CreditScoring\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Credit Scoring Settings screen: the privacy notice and the six policy
 * figures. The model version is the scorecard revision in use, read only;
 * the confidence and flag definitions are the engine's own and never saved.
 *
 * A changed privacy notice takes the next notice version, so an application
 * acknowledged under the old text reads as not acknowledged for the new one.
 * A save that changes nothing writes nothing; one that does is audited by the
 * settings model.
 */
final class SettingsEditor
{
    /** Rates, stored with four decimals and never rounded. */
    public const RATES = ['dsr_manageable_max', 'dsr_caution_max', 'capacity_share'];

    public const WHOLE_NUMBERS = ['max_active_loans', 'hard_past_due_days', 'decline_alert_points'];

    private const RATE_PLACES = 4;

    /**
     * The `CreditScoringSettings` shape, plus `notice_version` and the six
     * policy figures.
     *
     * @return array<string, mixed>
     */
    public function present(?CreditScoringSetting $settings = null): array
    {
        $settings ??= CreditScoringSetting::current();
        $hardDays = (int) $settings->hard_past_due_days;

        return array_merge([
            'privacy_notice' => $settings->privacy_notice,
            'score_model_version' => $settings->currentRevision()->value('version'),
            'notice_version' => $settings->notice_version,
            'confidence_definitions' => [
                ['level' => 'high', 'label' => 'High', 'description' => 'At least 12 matured loan periods in Lendy, with income and expenses declared. Capped at medium when most periods rely on payment records from before 1 October 2026.'],
                ['level' => 'medium', 'label' => 'Medium', 'description' => 'At least 3 matured loan periods in Lendy, or verified income with declared expenses.'],
                ['level' => 'low', 'label' => 'Low', 'description' => 'Fewer than 3 matured loan periods, without verified income and expenses. Under 3 matured periods the profile reads Limited History.'],
            ],
            'hard_flag_definitions' => [
                ['type' => 'hard_flag', 'label' => 'Hard flag', 'description' => "Needs manual review: a loan more than {$hardDays} days past due, a defaulted loan, a previous write-off, or income that could not be verified. A flag never changes the score."],
                ['type' => 'soft_flag', 'label' => 'Soft flag', 'description' => "A warning to review: a loan 1 to {$hardDays} days past due, active loans above the policy limit, a possible duplicate member record, a debt service ratio above the caution level, or a missing notice acknowledgement. A flag never changes the score."],
            ],
        ], $this->figures($settings));
    }

    /**
     * Save the validated fields that differ from the stored ones. The
     * manageable level is checked against the caution level again under the
     * row lock, so two saves can't leave it above.
     *
     * @param  array<string, mixed>  $values
     */
    public function save(User $user, array $values): CreditScoringSetting
    {
        return DB::transaction(function () use ($user, $values) {
            $settings = CreditScoringSetting::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $changes = [];

            foreach (self::RATES as $field) {
                if (array_key_exists($field, $values)) {
                    $sent = Decimal::scaled($values[$field], self::RATE_PLACES);

                    if ($sent !== Decimal::scaled((string) $settings->{$field}, self::RATE_PLACES)) {
                        $changes[$field] = Decimal::text($sent, self::RATE_PLACES);
                    }
                }
            }

            foreach (self::WHOLE_NUMBERS as $field) {
                if (array_key_exists($field, $values) && (int) $values[$field] !== (int) $settings->{$field}) {
                    $changes[$field] = (int) $values[$field];
                }
            }

            if (array_key_exists('privacy_notice', $values) && $values['privacy_notice'] !== $settings->privacy_notice) {
                $changes['privacy_notice'] = $values['privacy_notice'];
                $changes['notice_version'] = self::nextMinor((string) $settings->notice_version);
            }

            $manageable = Decimal::scaled($changes['dsr_manageable_max'] ?? (string) $settings->dsr_manageable_max, self::RATE_PLACES);
            $caution = Decimal::scaled($changes['dsr_caution_max'] ?? (string) $settings->dsr_caution_max, self::RATE_PLACES);

            if ($manageable > $caution) {
                throw ValidationException::withMessages(['dsr_manageable_max' => 'The manageable debt service level can\'t be above the caution level.']);
            }

            if ($changes !== []) {
                $settings->update($changes + ['updated_by' => $user->id]);
            }

            return $settings;
        });
    }

    /**
     * A stored rate in ten-thousandths.
     */
    public static function rate(CreditScoringSetting $settings, string $field): int
    {
        return Decimal::scaled((string) $settings->{$field}, self::RATE_PLACES);
    }

    /**
     * @return array<string, int|float>
     */
    private function figures(CreditScoringSetting $settings): array
    {
        $figures = [];

        foreach (self::RATES as $field) {
            $figures[$field] = Decimal::unscaled(self::rate($settings, $field), self::RATE_PLACES);
        }

        foreach (self::WHOLE_NUMBERS as $field) {
            $figures[$field] = (int) $settings->{$field};
        }

        return $figures;
    }

    private static function nextMinor(string $version): string
    {
        $parts = explode('.', $version);

        return ($parts[0] ?? '1').'.'.((int) ($parts[1] ?? 0) + 1);
    }
}
