<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\PortalLoginEvent;
use App\Services\AuditLogService;
use App\Services\Portal\Auth\PortalSessions;
use App\Services\Portal\MemberScope;
use App\Services\Portal\PortalNotices;
use App\Services\Portal\PortalStaffAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use OpenApi\Attributes as OA;

/**
 * The member's own sessions and sign-in history (portal design §3). Every
 * record is found through the signed-in member, so another member's id
 * answers 404.
 */
class PortalSecurityController extends Controller
{
    public function __construct(
        private readonly MemberScope $scope,
        private readonly PortalSessions $sessions,
        private readonly PortalNotices $notices,
        private readonly PortalStaffAlerts $staffAlerts,
    ) {}

    #[OA\Get(
        path: '/api/portal/security/sessions',
        summary: 'The member\'s signed-in devices',
        description: 'Signed-in portal token. [{id, device, ip_address, created_at, last_active_at, expires_at, current}], newest first.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The sessions')],
    )]
    public function sessions(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->sessions->list($this->scope->portalUser(), $this->currentTokenId())]);
    }

    #[OA\Delete(
        path: '/api/portal/security/sessions/{id}',
        summary: 'Sign out one other device',
        description: 'Signed-in portal token. 404 for another member\'s session; 422 for the current one (use sign out). Audited.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Signed out'), new OA\Response(response: 404, description: 'Not found'), new OA\Response(response: 422, description: 'The current session')],
    )]
    public function destroySession(int $id): JsonResponse
    {
        $portalUser = $this->scope->portalUser();
        $token = $portalUser->tokens()->whereKey($id)->firstOrFail();

        abort_if((int) $token->getKey() === $this->currentTokenId(), 422, 'This is the device you are using. Use Sign out instead.');

        $token->delete();

        AuditLogService::log('portal_sessions_signed_out', $portalUser, null, ['sessions_ended' => 1], "Member signed out a device ({$token->name})");

        return response()->json(['success' => true, 'message' => 'That device was signed out.']);
    }

    #[OA\Post(
        path: '/api/portal/security/sessions/logout-others',
        summary: 'Sign out every other device',
        description: 'Signed-in portal token. Keeps the current session. Audited.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: '{sessions_ended}')],
    )]
    public function logoutOthers(): JsonResponse
    {
        $portalUser = $this->scope->portalUser();
        $count = $this->sessions->revoke($portalUser, $this->currentTokenId());

        AuditLogService::log('portal_sessions_signed_out', $portalUser, null, ['sessions_ended' => $count], 'Member signed out every other device');

        return response()->json(['success' => true, 'data' => ['sessions_ended' => $count]]);
    }

    #[OA\Get(
        path: '/api/portal/security/logins',
        summary: 'The member\'s last 20 sign-in attempts',
        description: 'Signed-in portal token. [{id, outcome, outcome_label, device, ip_address, created_at, reported_at}]. The IP address is the one the API saw.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The sign-ins')],
    )]
    public function logins(): JsonResponse
    {
        $rows = PortalLoginEvent::query()
            ->where('portal_user_id', $this->scope->portalUser()->getKey())
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (PortalLoginEvent $event) => [
                'id' => (int) $event->getKey(),
                'outcome' => $event->outcome,
                'outcome_label' => PortalLoginEvent::OUTCOME_LABELS[$event->outcome] ?? 'Other',
                'device' => $event->device,
                'ip_address' => $event->ip_address,
                'created_at' => $event->created_at,
                'reported_at' => $event->reported_at,
            ]);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    #[OA\Post(
        path: '/api/portal/security/logins/{id}/report',
        summary: 'Report a sign-in the member doesn\'t recognise',
        description: 'Signed-in portal token. Marks it reported, signs out every other device, tells branch staff who hold member_portal:manage, and answers `password_change_required: true` so the screen asks for a new password. 404 for another member\'s sign-in. Audited.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: '{reported_at, sessions_ended, password_change_required}'), new OA\Response(response: 404, description: 'Not found')],
    )]
    public function report(int $id): JsonResponse
    {
        $portalUser = $this->scope->portalUser();

        $result = DB::transaction(function () use ($portalUser, $id): array {
            $event = PortalLoginEvent::query()->where('portal_user_id', $portalUser->getKey())->lockForUpdate()->findOrFail($id);

            if ($event->reported_at === null) {
                $event->forceFill(['reported_at' => now()])->save();
            }

            $count = $this->sessions->revoke($portalUser, $this->currentTokenId());

            AuditLogService::log(
                'portal_login_reported',
                $portalUser,
                null,
                ['login_event_id' => (int) $event->getKey(), 'sessions_ended' => $count],
                sprintf('Member reported a sign-in they don\'t recognise (%s, %s)', $event->device ?? 'unknown device', $event->created_at?->timezone('Asia/Manila')->format('j M Y, g:i A')),
            );

            $this->notices->loginReported($portalUser);
            $this->staffAlerts->loginReported($portalUser);

            return ['reported_at' => $event->reported_at, 'sessions_ended' => $count, 'password_change_required' => true];
        });

        return response()->json(['success' => true, 'data' => $result]);
    }

    private function currentTokenId(): ?int
    {
        $token = $this->scope->portalUser()->currentAccessToken();

        return $token instanceof PersonalAccessToken ? (int) $token->getKey() : null;
    }
}
