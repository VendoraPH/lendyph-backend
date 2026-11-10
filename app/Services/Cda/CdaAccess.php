<?php

namespace App\Services\Cda;

use App\Models\User;
use App\Services\BranchAccess;

/**
 * Who may do what in CDA Reports (CDA design §3).
 *
 * - CDA reports cover the whole cooperative, so every `/cda` endpoint needs
 *   `branches:view_all` as well as its own permission.
 * - Mediation, audit (financial statements, performance audit, auditor
 *   documents) and tax (ATIR, ABR) reports also need their view permission.
 * - The template manager is a super_admin role check, not a permission, so
 *   admin's `Permission::all()` never grants it.
 *
 * A refusal is a 403 with a message in words.
 */
final class CdaAccess
{
    public const VIEW = 'cda:view';

    public const PREPARE = 'cda:prepare';

    public const UPLOAD = 'cda:upload';

    public const REVIEW = 'cda:review';

    public const APPROVE = 'cda:approve';

    public const FINALIZE = 'cda:finalize';

    public const REOPEN = 'cda:reopen';

    public const SUBMIT = 'cda:submit';

    public const EXPORT = 'cda:export';

    public const SETTINGS = 'cda:settings';

    public const MANAGE_OFFICERS = 'cda:manage_officers';

    public const MANAGE_SOCIAL = 'cda:manage_social';

    public const MANAGE_MEDIATION = 'cda:manage_mediation';

    public const VIEW_MEDIATION = 'cda:view_mediation';

    public const VIEW_AUDIT = 'cda:view_audit';

    public const VIEW_TAX = 'cda:view_tax';

    /** @var list<string> the sixteen, as seeded */
    public const PERMISSIONS = [
        self::VIEW, self::PREPARE, self::UPLOAD, self::REVIEW, self::APPROVE, self::FINALIZE, self::REOPEN,
        self::SUBMIT, self::EXPORT, self::SETTINGS, self::MANAGE_OFFICERS, self::MANAGE_SOCIAL, self::MANAGE_MEDIATION,
        self::VIEW_MEDIATION, self::VIEW_AUDIT, self::VIEW_TAX,
    ];

    /** @var list<string> */
    public const RESTRICTED_VIEWS = [self::VIEW_MEDIATION, self::VIEW_AUDIT, self::VIEW_TAX];

    public const TEMPLATE_MANAGER_ROLE = 'super_admin';

    /** Whether the user holds `branches:view_all` and the permission. */
    public function allows(User $user, string $permission): bool
    {
        return $user->can(BranchAccess::VIEW_ALL) && $user->can($permission);
    }

    /**
     * 403 unless the user holds `branches:view_all` and at least one of the
     * permissions.
     *
     * @param  string|list<string>  $permissions
     */
    public function authorize(User $user, string|array $permissions): void
    {
        $permissions = (array) $permissions;

        abort_unless($user->can(BranchAccess::VIEW_ALL), 403, 'CDA reports cover the whole cooperative, so they need access to every branch (branches:view_all).');
        abort_unless($user->canAny($permissions), 403, 'This needs the '.implode(' or ', $permissions).' permission.');
    }

    /** Whether the user may see a report type: always, unless it's restricted. */
    public function canSeeType(User $user, string $reportType): bool
    {
        $restriction = ReportTypes::restriction($reportType);

        return $restriction === null || $user->can($restriction);
    }

    /** 403 unless the user may see the report type. */
    public function authorizeType(User $user, string $reportType): void
    {
        $restriction = ReportTypes::restriction($reportType);

        abort_unless($this->canSeeType($user, $reportType), 403, ReportTypes::title($reportType)." is restricted: it needs the {$restriction} permission.");
    }

    public function isTemplateManager(User $user): bool
    {
        return $user->hasRole(self::TEMPLATE_MANAGER_ROLE);
    }

    /** 403 unless the user holds the super_admin role. */
    public function authorizeTemplateManager(User $user): void
    {
        abort_unless($this->isTemplateManager($user), 403, 'Only a super admin manages CDA report templates.');
    }
}
