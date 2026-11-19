<?php

namespace App\Services\Amla\Foundation;

use App\Models\User;
use App\Services\BranchAccess;

/**
 * Who may do what in AMLA (AMLA design §4.12). Every AMLA endpoint checks its
 * own `amla:*` key here, in its controller or FormRequest.
 *
 * - authorize(): the key alone. Branch users then see only their own
 *   branches' records (later slices scope with BranchAccess).
 * - authorizeHeadOffice(): the key plus `branches:view_all`, for what covers
 *   the whole cooperative: settings and their changes, the AMLA log, and
 *   (later) cases, STRs, screening and sanctions (HEAD_OFFICE).
 *
 * A refusal is a 403 in words, never a permission key, and is written to the
 * AMLA log (`access_denied`) with why and where.
 */
final class AmlaAccess
{
    public const VIEW = 'amla:view';

    public const ALERTS = 'amla:alerts';

    public const CASES = 'amla:cases';

    public const CDD = 'amla:cdd';

    public const SCREENING = 'amla:screening';

    public const SANCTIONS = 'amla:sanctions';

    public const CTR = 'amla:ctr';

    public const STR = 'amla:str';

    public const APPROVE = 'amla:approve';

    public const EXPORT = 'amla:export';

    public const SUBMIT = 'amla:submit';

    public const SETTINGS = 'amla:settings';

    public const AUDIT = 'amla:audit';

    /** @var list<string> the thirteen, as X0 seeded them */
    public const PERMISSIONS = [
        self::VIEW, self::ALERTS, self::CASES, self::CDD, self::SCREENING, self::SANCTIONS, self::CTR,
        self::STR, self::APPROVE, self::EXPORT, self::SUBMIT, self::SETTINGS, self::AUDIT,
    ];

    /** @var list<string> keys whose endpoints always need head-office access (design §4.12) */
    public const HEAD_OFFICE = [self::CASES, self::STR, self::SCREENING, self::SANCTIONS, self::SETTINGS, self::AUDIT];

    /** Each permission in words, for refusals: users never see a permission key. */
    private const WORDS = [
        self::VIEW => 'AMLA view', self::ALERTS => 'AMLA alerts', self::CASES => 'AMLA cases',
        self::CDD => 'AMLA customer due diligence', self::SCREENING => 'AMLA screening',
        self::SANCTIONS => 'AMLA sanctions and orders', self::CTR => 'AMLA covered transaction reports',
        self::STR => 'AMLA suspicious transaction reports', self::APPROVE => 'AMLA report approval',
        self::EXPORT => 'AMLA export', self::SUBMIT => 'AMLA filing', self::SETTINGS => 'AMLA settings',
        self::AUDIT => 'AMLA audit history',
    ];

    public const VIEW_ALL_REFUSAL = 'AMLA compliance covers the whole cooperative, so this needs head-office access to all branches.';

    public function __construct(private readonly AmlaLog $log) {}

    /**
     * Permissions in words, joined with "or": "the AMLA view or AMLA settings
     * permission".
     *
     * @param  string|list<string>  $permissions
     */
    public static function words(string|array $permissions): string
    {
        $words = array_map(
            fn (string $permission) => self::WORDS[$permission] ?? ucfirst(str_replace(['_', ':'], ' ', $permission)),
            (array) $permissions,
        );

        return 'the '.implode(' or ', $words).' permission';
    }

    /**
     * Whether the user holds at least one of the permissions.
     *
     * @param  string|list<string>  $permissions
     */
    public function allows(User $user, string|array $permissions): bool
    {
        return $user->canAny((array) $permissions);
    }

    /**
     * Whether the user holds `branches:view_all` and at least one of the permissions.
     *
     * @param  string|list<string>  $permissions
     */
    public function allowsHeadOffice(User $user, string|array $permissions): bool
    {
        return $user->can(BranchAccess::VIEW_ALL) && $this->allows($user, $permissions);
    }

    /**
     * 403 unless the user holds at least one of the permissions.
     *
     * @param  string|list<string>  $permissions
     */
    public function authorize(User $user, string|array $permissions): void
    {
        if (! $this->allows($user, $permissions)) {
            $this->refuse($user, 'missing_permission', 'This needs '.self::words($permissions).'.');
        }
    }

    /**
     * 403 unless the user holds at least one of the permissions and
     * `branches:view_all`.
     *
     * @param  string|list<string>  $permissions
     */
    public function authorizeHeadOffice(User $user, string|array $permissions): void
    {
        $this->authorize($user, $permissions);

        if (! $user->can(BranchAccess::VIEW_ALL)) {
            $this->refuse($user, 'head_office_only', self::VIEW_ALL_REFUSAL);
        }
    }

    /**
     * Writes the refusal to the AMLA log, then answers 403 with the message.
     * Call it outside any transaction, so the log row stays.
     */
    public function refuse(User $user, string $reason, string $message): never
    {
        $request = request();

        $this->log->record('access_denied', $user, details: [
            'reason' => $reason,
            'message' => $message,
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
        ]);

        abort(403, $message);
    }
}
