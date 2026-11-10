<?php

namespace App\Services\Cda\Registers;

use App\Services\Cda\CdaAccess;

/**
 * The compliance repository's document categories (CDA design §1, §3), in
 * words, and the view permission each restricted one needs: audited
 * financial statements and the auditors' papers need `cda:view_audit`, tax
 * filings `cda:view_tax`, mediation papers `cda:view_mediation`.
 *
 * #510's categories, plus one per report type so a template's document rule
 * (`document_category`) and an export can name its report, plus training
 * certificates, submission proofs and report exports (an export is
 * restricted by its report's type, through `report_id`).
 */
final class DocumentCategories
{
    public const CERTIFICATES = 'certificates';

    public const SUBMISSION_PROOF = 'submission_proof';

    /** A report's server export (slice C4): restricted by its report's type. */
    public const REPORT_EXPORT = 'report_export';

    /** @var array<string, string> key => words */
    public const LABELS = [
        'capr' => 'Cooperative Annual Progress Report (CAPR)',
        'financial_statements' => 'Audited financial statements',
        'audit_report' => 'Auditor\'s report',
        'performance_audit' => 'Performance audit',
        'social_audit' => 'Social audit',
        'pesos' => 'Enhanced PESOS',
        'officers_trainings' => 'Officers and trainings',
        'mediation' => 'Mediation and conciliation',
        'sworn_statement' => 'Sworn statement of compliance',
        'atir' => 'Annual Tax Incentives Report (ATIR)',
        'abr' => 'Annual Benefits Report (ABR)',
        'tax' => 'Tax documents',
        'minutes' => 'Minutes of meetings',
        self::CERTIFICATES => 'Training certificates',
        self::SUBMISSION_PROOF => 'Proof of submission',
        self::REPORT_EXPORT => 'Report export',
        'other' => 'Other',
    ];

    /** @var array<string, string> category => the view permission it needs */
    private const RESTRICTED = [
        'financial_statements' => CdaAccess::VIEW_AUDIT,
        'audit_report' => CdaAccess::VIEW_AUDIT,
        'performance_audit' => CdaAccess::VIEW_AUDIT,
        'atir' => CdaAccess::VIEW_TAX,
        'abr' => CdaAccess::VIEW_TAX,
        'tax' => CdaAccess::VIEW_TAX,
        'mediation' => CdaAccess::VIEW_MEDIATION,
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    /**
     * The categories staff pick on an upload: proofs come only from a
     * submission, exports only from the server.
     *
     * @return list<string>
     */
    public static function uploadable(): array
    {
        return array_values(array_diff(self::keys(), [self::SUBMISSION_PROOF, self::REPORT_EXPORT]));
    }

    public static function label(string $category): string
    {
        return self::LABELS[$category] ?? ucfirst(str_replace('_', ' ', $category));
    }

    public static function restriction(string $category): ?string
    {
        return self::RESTRICTED[$category] ?? null;
    }
}
