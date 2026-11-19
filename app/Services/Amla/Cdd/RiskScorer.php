<?php

namespace App\Services\Amla\Cdd;

use App\Models\AmlaProfile;
use App\Models\AmlaRiskRuleVersion;
use App\Models\Borrower;
use App\Models\GCashNonMember;
use App\Models\GCashTransaction;

/**
 * Scores a subject's AML risk under a rules version (AMLA design §4.9; task
 * §9). Every factor is listed with its value and points, so a result is
 * always explained. Factors whose data comes in later slices (actual
 * behaviour against expected activity, watchlist findings, earlier
 * compliance findings) and beneficial ownership (no juridical members) are
 * listed at 0 points as "data not available yet", never guessed.
 *
 * It reads only: customer type, ID verification state, occupation, source of
 * funds, expected activity, nationality (the country only), whether GCash is
 * used, PEP status and adverse information. Never gender, civil status, age,
 * spouse fields or any address detail.
 */
final class RiskScorer
{
    /** @var array<string, string> each factor in words */
    public const FACTOR_WORDS = [
        'customer_type' => 'Customer type',
        'id_verification' => 'Identity verification',
        'occupation' => 'Occupation or business',
        'source_of_funds' => 'Source of funds',
        'expected_activity' => 'Expected account activity',
        'geography' => 'Geographic exposure',
        'channel' => 'Transaction channel',
        'pep' => 'Politically exposed person',
        'adverse_info' => 'Adverse information to verify',
        'actual_behaviour' => 'Actual transaction behaviour',
        'watchlist' => 'Watchlist findings',
        'previous_findings' => 'Previous compliance findings',
        'beneficial_ownership' => 'Beneficial ownership',
    ];

    /** @var array<string, string> each value in words */
    public const VALUE_WORDS = [
        'member' => 'Member', 'walk_in' => 'Walk-in',
        'verified' => 'Verified', 'not_verified' => 'Not verified', 'expired' => 'Expired', 'failed' => 'Could not be verified',
        'provided' => 'Provided', 'missing' => 'Missing',
        'salary' => 'Salary', 'pension' => 'Pension', 'savings' => 'Savings', 'business' => 'Business',
        'remittance' => 'Remittance', 'other' => 'Other',
        'domestic' => 'Philippines', 'foreign' => 'Another country', 'high_risk_country' => 'A high-risk country',
        'no_gcash' => 'No GCash use', 'gcash' => 'Uses GCash',
        'not_pep' => 'Not a PEP', 'pep' => 'PEP', 'pep_family' => 'Family member of a PEP', 'pep_associate' => 'Close associate of a PEP',
        'none' => 'None', 'flagged' => 'Flagged for verification',
        'not_available' => 'Data not available yet',
    ];

    /** Factors listed with no points until their data exists (AMLA-B2, B5, B6) or applies. */
    private const NOT_YET = ['actual_behaviour', 'watchlist', 'previous_findings', 'beneficial_ownership'];

    /**
     * @return array{score: int, level: string, factors: list<array{key: string, label: string, value: string, value_words: string, points: int}>}
     */
    public function score(Borrower|GCashNonMember $subject, AmlaProfile $profile, string $idState, AmlaRiskRuleVersion $rules): array
    {
        $definition = $rules->definition;
        $values = [
            'customer_type' => $subject instanceof Borrower ? 'member' : 'walk_in',
            'id_verification' => $idState,
            'occupation' => filled($profile->occupation) || ($subject instanceof Borrower && filled($subject->employer_or_business)) ? 'provided' : 'missing',
            'source_of_funds' => $profile->source_of_funds ?? 'missing',
            'expected_activity' => $profile->expected_monthly_centavos !== null || $profile->expected_monthly_count !== null ? 'provided' : 'missing',
            'geography' => $this->geography($profile->nationality, $definition['high_risk_countries']),
            'channel' => $this->usesGcash($subject) ? 'gcash' : 'no_gcash',
            'pep' => $profile->pep_status ?? 'missing',
            'adverse_info' => $profile->adverse_info ? 'flagged' : 'none',
        ];

        $factors = [];
        $score = 0;

        foreach ($values as $factor => $value) {
            $points = (int) ($definition['factors'][$factor][$value] ?? 0);
            $score += $points;
            $factors[] = $this->factor($factor, $value, $points);
        }

        foreach (self::NOT_YET as $factor) {
            $factors[] = $this->factor($factor, 'not_available', 0);
        }

        return ['score' => $score, 'level' => self::level($score, $definition['thresholds']), 'factors' => $factors];
    }

    /** @param  array{medium: int, high: int}  $thresholds */
    public static function level(int $score, array $thresholds): string
    {
        return match (true) {
            $score >= $thresholds['high'] => 'high',
            $score >= $thresholds['medium'] => 'medium',
            default => 'low',
        };
    }

    /** @param  list<string>  $highRiskCountries */
    private function geography(?string $nationality, array $highRiskCountries): string
    {
        return match (true) {
            blank($nationality) => 'missing',
            in_array($nationality, $highRiskCountries, true) => 'high_risk_country',
            $nationality === 'PH' => 'domestic',
            default => 'foreign',
        };
    }

    private function usesGcash(Borrower|GCashNonMember $subject): bool
    {
        return $subject instanceof GCashNonMember
            || GCashTransaction::query()->where('borrower_id', $subject->getKey())->exists();
    }

    /** @return array{key: string, label: string, value: string, value_words: string, points: int} */
    private function factor(string $factor, string $value, int $points): array
    {
        return [
            'key' => $factor,
            'label' => self::FACTOR_WORDS[$factor],
            'value' => $value,
            'value_words' => self::VALUE_WORDS[$value] ?? ucfirst(str_replace('_', ' ', $value)),
            'points' => $points,
        ];
    }
}
