<?php

namespace App\Services\Cda\Registers;

/**
 * The registers' keys in words, and the two-decimal figures they show
 * (hours, percents), computed in whole hundredths so nothing rounds through a
 * float.
 */
final class RegisterWords
{
    public const POSITIONS = [
        'chairperson' => 'Chairperson',
        'vice_chairperson' => 'Vice Chairperson',
        'board_member' => 'Board Member',
        'secretary' => 'Secretary',
        'treasurer' => 'Treasurer',
        'general_manager' => 'General Manager',
        'committee_chairperson' => 'Committee Chairperson',
        'committee_member' => 'Committee Member',
        'manager' => 'Manager',
        'bookkeeper' => 'Bookkeeper',
        'other' => 'Other',
    ];

    public const SOCIAL_CATEGORIES = [
        'education' => 'Education',
        'health' => 'Health',
        'environment' => 'Environment',
        'community' => 'Community',
        'youth' => 'Youth',
        'livelihood' => 'Livelihood',
        'disaster_response' => 'Disaster response',
        'other' => 'Other',
    ];

    public const FUNDING_SOURCES = [
        'cdf' => 'Community Development Fund',
        'general_fund' => 'General fund',
        'cetf' => 'Cooperative Education and Training Fund',
        'external' => 'External',
        'other' => 'Other',
    ];

    public const MEDIATION_STATUSES = [
        'pending' => 'Pending',
        'in_mediation' => 'In mediation',
        'resolved' => 'Resolved',
        'escalated' => 'Escalated',
        'withdrawn' => 'Withdrawn',
    ];

    public static function position(string $key): string
    {
        return self::POSITIONS[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    public static function socialCategory(string $key): string
    {
        return self::SOCIAL_CATEGORIES[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    public static function fundingSource(?string $key): ?string
    {
        return $key === null ? null : (self::FUNDING_SOURCES[$key] ?? ucfirst(str_replace('_', ' ', $key)));
    }

    public static function mediationStatus(string $key): string
    {
        return self::MEDIATION_STATUSES[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    /** Whole hundredths as a two-decimal string: 450 → "4.50". */
    public static function hundredths(int $hundredths): string
    {
        return sprintf('%s%d.%02d', $hundredths < 0 ? '-' : '', intdiv(abs($hundredths), 100), abs($hundredths) % 100);
    }

    /** part ÷ whole as a percent with two decimals, half up: 1 of 3 → "33.33". */
    public static function percent(int $part, int $whole): string
    {
        return self::hundredths(intdiv(20000 * $part + $whole, 2 * $whole));
    }
}
