<?php

namespace App\Services\Portal\Admin;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\PortalCode;
use App\Models\PortalOtpChallenge;
use App\Models\PortalUser;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\Portal\Auth\MemberNumber;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * What staff read about members' portal access (portal design §3, staff):
 * the counts, the accounts list, one member's access card and the
 * communications log. Branch-scoped through the member's branch; contact
 * details only with `borrowers:view` (round fz). Never a code.
 */
final class AccessDesk
{
    public const STATE_NOT_INVITED = 'not_invited';

    /** Every state the accounts list filters on. */
    public const STATES = [self::STATE_NOT_INVITED, PortalUser::STATUS_INVITED, PortalUser::STATUS_ACTIVE, PortalUser::STATUS_LOCKED, PortalUser::STATUS_DISABLED];

    /** Audit actions the communications log shows, in words. */
    private const COMMUNICATION_ACTIONS = [
        'portal_code_issued' => 'Code issued',
        'portal_activated' => 'Portal activated',
        'portal_password_reset' => 'Password reset with a code',
        'portal_password_changed' => 'Password changed',
        'portal_access_disabled' => 'Access turned off',
        'portal_access_enabled' => 'Access turned back on',
        'portal_unlocked' => 'Unlocked by staff',
        'portal_signed_out' => 'Signed out by staff',
        'portal_locked_out' => 'Locked after wrong passwords',
        'portal_hard_locked' => 'Locked until staff unlock',
        'portal_login_reported' => 'Sign-in reported by the member',
        'portal_statement_downloaded' => 'Statement downloaded',
        'portal_document_downloaded' => 'Document downloaded',
    ];

    public function __construct(private readonly BranchAccess $branches) {}

    /** A member's portal state in one word. */
    public static function state(?PortalUser $portalUser): string
    {
        if ($portalUser === null) {
            return self::STATE_NOT_INVITED;
        }

        if ($portalUser->status === PortalUser::STATUS_ACTIVE && $portalUser->isTemporarilyLocked()) {
            return PortalUser::STATUS_LOCKED;
        }

        return $portalUser->status;
    }

    /** @return array<string, mixed> */
    public function card(Borrower $member): array
    {
        $portalUser = PortalUser::query()->where('borrower_id', $member->getKey())->first();
        $code = $portalUser === null ? null : PortalCode::query()->where('portal_user_id', $portalUser->getKey())->orderByDesc('id')->first();

        return [
            'member' => [
                'id' => (int) $member->getKey(),
                'name' => $member->full_name,
                'member_number' => MemberNumber::of($member),
                'status' => $member->status,
                'has_birthdate' => $member->birthdate !== null,
            ],
            'state' => self::state($portalUser),
            'invited_at' => $portalUser?->invited_at,
            'activated_at' => $portalUser?->activated_at,
            'last_login_at' => $portalUser?->last_login_at,
            'password_changed_at' => $portalUser?->password_changed_at,
            'locked_until' => $portalUser?->isTemporarilyLocked() ? $portalUser->locked_until : null,
            'disabled_reason' => $portalUser?->disabled_reason,
            'terms_version' => $portalUser?->terms_version,
            'code' => $code === null ? null : [
                'kind' => $code->kind,
                'expires_at' => $code->expires_at,
                'status' => $code->effectiveStatus(),
            ],
        ];
    }

    /** @return array<string, int> */
    public function stats(User $viewer, ?int $branchId): array
    {
        $members = fn (): Builder => Borrower::query()->visibleTo($viewer)->when($branchId !== null, fn ($q) => $q->where('borrowers.branch_id', $branchId));
        $accounts = fn (): Builder => PortalUser::query()->whereIn('borrower_id', $members()->select('borrowers.id'));
        $monthStart = Carbon::now('Asia/Manila')->startOfMonth()->timezone(config('app.timezone'));

        return [
            'eligible' => $members()->where('borrowers.status', 'active')->count(),
            'invited' => $accounts()->where('status', PortalUser::STATUS_INVITED)->count(),
            'codes_expired' => $accounts()->where('status', PortalUser::STATUS_INVITED)
                ->whereDoesntHave('codes', fn ($codes) => $codes->where('status', PortalCode::STATUS_OPEN)->where('expires_at', '>', now()))
                ->count(),
            'active' => $accounts()->where('status', PortalUser::STATUS_ACTIVE)->count(),
            'active_this_month' => $accounts()->where('status', PortalUser::STATUS_ACTIVE)->where('last_login_at', '>=', $monthStart)->count(),
            'never_signed_in' => $accounts()->whereNull('last_login_at')->count(),
            'locked' => $accounts()->where(fn ($q) => $q->where('status', PortalUser::STATUS_LOCKED)
                ->orWhere(fn ($temporary) => $temporary->where('status', PortalUser::STATUS_ACTIVE)->where('locked_until', '>', now())))
                ->count(),
            'disabled' => $accounts()->where('status', PortalUser::STATUS_DISABLED)->count(),
        ];
    }

    /**
     * Members with their portal state, paged. Members only (no pending or
     * rejected applicants).
     *
     * @param  array{status?: ?string, branch_id?: ?int, search?: ?string, per_page?: ?int}  $filters
     */
    public function accounts(User $viewer, array $filters): LengthAwarePaginator
    {
        $status = $filters['status'] ?? null;
        $search = trim((string) ($filters['search'] ?? ''));

        $accounts = fn (callable $where) => PortalUser::query()->where($where)->select('borrower_id');
        $openNow = fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<=', now());

        $page = Borrower::query()
            ->members()
            ->visibleTo($viewer)
            ->with(['branch:id,name'])
            ->when(($filters['branch_id'] ?? null) !== null, fn ($q) => $q->where('borrowers.branch_id', $filters['branch_id']))
            ->when($status === self::STATE_NOT_INVITED, fn ($q) => $q->whereNotIn('borrowers.id', PortalUser::query()->select('borrower_id')))
            ->when($status === PortalUser::STATUS_LOCKED, fn ($q) => $q->whereIn('borrowers.id', $accounts(fn ($locked) => $locked
                ->where('status', PortalUser::STATUS_LOCKED)
                ->orWhere(fn ($temporary) => $temporary->where('status', PortalUser::STATUS_ACTIVE)->where('locked_until', '>', now())))))
            ->when(in_array($status, [PortalUser::STATUS_INVITED, PortalUser::STATUS_DISABLED], true), fn ($q) => $q->whereIn('borrowers.id', $accounts(fn ($rows) => $rows->where('status', $status))))
            ->when($status === PortalUser::STATUS_ACTIVE, fn ($q) => $q->whereIn('borrowers.id', $accounts(fn ($rows) => $rows->where('status', PortalUser::STATUS_ACTIVE)->where($openNow))))
            ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where('borrowers.first_name', 'like', $like)
                    ->orWhere('borrowers.last_name', 'like', $like)
                    ->orWhereRaw("CONCAT_WS(' ', borrowers.first_name, borrowers.last_name) like ?", [$like])
                    ->orWhere('borrowers.borrower_code', 'like', $like)
                    ->orWhere('borrowers.membership_number', 'like', $like);
            }))
            ->orderBy('borrowers.last_name')
            ->orderBy('borrowers.first_name')
            ->orderBy('borrowers.id')
            ->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));

        $portalUsers = PortalUser::query()->whereIn('borrower_id', $page->getCollection()->modelKeys())->get()->keyBy('borrower_id');
        $showContacts = $viewer->can('borrowers:view');

        $page->setCollection($page->getCollection()->map(function (Borrower $member) use ($portalUsers, $showContacts): array {
            $portalUser = $portalUsers[$member->getKey()] ?? null;

            return [
                'borrower_id' => (int) $member->getKey(),
                'member_number' => MemberNumber::of($member),
                'full_name' => $member->full_name,
                'branch' => $member->branch === null ? null : ['id' => (int) $member->branch->id, 'name' => $member->branch->name],
                'member_status' => $member->status,
                'state' => self::state($portalUser),
                'invited_at' => $portalUser?->invited_at,
                'activated_at' => $portalUser?->activated_at,
                'last_login_at' => $portalUser?->last_login_at,
                'contact_number' => $showContacts && filled($member->contact_number) ? $member->contact_number : null,
                'email' => $showContacts && filled($member->email) ? $member->email : null,
            ];
        }));

        return $page;
    }

    /**
     * Codes issued, OTPs sent (no values), notices created (titles) and the
     * member's downloads, newest first. Built from audit rows, OTP challenges
     * and notifications; never a code or a notice body.
     *
     * @return list<array{date: mixed, kind: string, description: string}>
     */
    public function communications(Borrower $member): array
    {
        $portalUser = PortalUser::query()->where('borrower_id', $member->getKey())->first();

        if ($portalUser === null) {
            return [];
        }

        $rows = AuditLog::query()
            ->where(fn ($q) => $q
                ->where(fn ($about) => $about->where('auditable_type', PortalUser::class)->where('auditable_id', $portalUser->getKey()))
                ->orWhere('portal_user_id', $portalUser->getKey()))
            ->whereIn('action', array_keys(self::COMMUNICATION_ACTIONS))
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (AuditLog $log) => [
                'date' => $log->created_at,
                'kind' => $log->action,
                'description' => self::COMMUNICATION_ACTIONS[$log->action].$this->codeDetail($log),
            ]);

        $otps = PortalOtpChallenge::query()
            ->where('portal_user_id', $portalUser->getKey())
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (PortalOtpChallenge $otp) => [
                'date' => $otp->created_at,
                'kind' => 'portal_otp_sent',
                'description' => 'One-time password sent to '.($otp->masked_recipient ?? 'the member\'s mobile'),
            ]);

        $notices = DatabaseNotification::query()
            ->where('notifiable_type', PortalUser::class)
            ->where('notifiable_id', $portalUser->getKey())
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (DatabaseNotification $notice) => [
                'date' => $notice->created_at,
                'kind' => 'portal_notice',
                'description' => 'Notice: '.(string) ($notice->data['title'] ?? 'Notice'),
            ]);

        return $rows->concat($otps)->concat($notices)
            ->sortByDesc(fn (array $row) => $row['date']?->getTimestamp() ?? 0)
            ->values()
            ->all();
    }

    private function codeDetail(AuditLog $log): string
    {
        if ($log->action !== 'portal_code_issued') {
            return '';
        }

        $kind = ($log->new_values['kind'] ?? null) === PortalCode::KIND_RESET ? 'password reset' : 'activation';

        return " ({$kind})";
    }
}
