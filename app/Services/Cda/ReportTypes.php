<?php

namespace App\Services\Cda;

use InvalidArgumentException;

/**
 * The CDA report types of v1 (CDA design §3, renames from #510): their
 * titles, parts, deadline basis, whether they need a Certificate of Tax
 * Exemption, and the view permission that restricts them.
 *
 * - Mediation is one instance per half (`h1`, `h2`); every other type has
 *   one `annual` instance.
 * - ATIR and ABR apply only with a valid CTE (design §6).
 * - Restricted types need their view permission on top of `cda:view`.
 */
final class ReportTypes
{
    public const ANNUAL = 'annual';

    public const H1 = 'h1';

    public const H2 = 'h2';

    public const PARTS = [self::ANNUAL, self::H1, self::H2];

    public const PART_TITLES = [
        self::ANNUAL => 'Annual',
        self::H1 => 'First half (January–June)',
        self::H2 => 'Second half (July–December)',
    ];

    /**
     * @var array<string, array{title: string, parts: list<string>, basis: string, days_setting: string, requires_cte: bool, restricted: string|null}>
     */
    public const TYPES = [
        'capr' => ['title' => 'Cooperative Annual Progress Report (CAPR)', 'parts' => [self::ANNUAL], 'basis' => 'year_end', 'days_setting' => 'annual_deadline_days', 'requires_cte' => false, 'restricted' => null],
        'financial_statements' => ['title' => 'Audited Financial Statements', 'parts' => [self::ANNUAL], 'basis' => 'year_end', 'days_setting' => 'annual_deadline_days', 'requires_cte' => false, 'restricted' => CdaAccess::VIEW_AUDIT],
        'performance_audit' => ['title' => 'Performance Audit Report', 'parts' => [self::ANNUAL], 'basis' => 'year_end', 'days_setting' => 'annual_deadline_days', 'requires_cte' => false, 'restricted' => CdaAccess::VIEW_AUDIT],
        'social_audit' => ['title' => 'Social Audit Report', 'parts' => [self::ANNUAL], 'basis' => 'year_end', 'days_setting' => 'annual_deadline_days', 'requires_cte' => false, 'restricted' => null],
        'pesos' => ['title' => 'Enhanced PESOS', 'parts' => [self::ANNUAL], 'basis' => 'year_end', 'days_setting' => 'annual_deadline_days', 'requires_cte' => false, 'restricted' => null],
        'officers_trainings' => ['title' => 'List of Officers and Trainings', 'parts' => [self::ANNUAL], 'basis' => 'year_end', 'days_setting' => 'annual_deadline_days', 'requires_cte' => false, 'restricted' => null],
        'mediation' => ['title' => 'Mediation and Conciliation Report', 'parts' => [self::H1, self::H2], 'basis' => 'half_end', 'days_setting' => 'mediation_deadline_days', 'requires_cte' => false, 'restricted' => CdaAccess::VIEW_MEDIATION],
        'sworn_statement' => ['title' => 'Sworn Statement of Compliance', 'parts' => [self::ANNUAL], 'basis' => 'year_end', 'days_setting' => 'annual_deadline_days', 'requires_cte' => false, 'restricted' => null],
        'atir' => ['title' => 'Annual Tax Incentives Report (ATIR)', 'parts' => [self::ANNUAL], 'basis' => 'year_end', 'days_setting' => 'annual_deadline_days', 'requires_cte' => true, 'restricted' => CdaAccess::VIEW_TAX],
        'abr' => ['title' => 'Annual Benefits Report (ABR)', 'parts' => [self::ANNUAL], 'basis' => 'year_end', 'days_setting' => 'annual_deadline_days', 'requires_cte' => true, 'restricted' => CdaAccess::VIEW_TAX],
    ];

    /** @var list<string> */
    public const ALL = ['capr', 'financial_statements', 'performance_audit', 'social_audit', 'pesos', 'officers_trainings', 'mediation', 'sworn_statement', 'atir', 'abr'];

    public const NOT_APPLICABLE_WITHOUT_CTE = 'Applies only to a cooperative holding a valid Certificate of Tax Exemption (CTE) for the period.';

    /** @return array{title: string, parts: list<string>, basis: string, days_setting: string, requires_cte: bool, restricted: string|null} */
    public static function get(string $type): array
    {
        return self::TYPES[$type] ?? throw new InvalidArgumentException("Unknown CDA report type: {$type}.");
    }

    public static function exists(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public static function title(string $type): string
    {
        return self::get($type)['title'];
    }

    /** @return list<string> */
    public static function parts(string $type): array
    {
        return self::get($type)['parts'];
    }

    /** The view permission a restricted type needs, or null. */
    public static function restriction(string $type): ?string
    {
        return self::get($type)['restricted'];
    }

    public static function requiresCte(string $type): bool
    {
        return self::get($type)['requires_cte'];
    }
}
