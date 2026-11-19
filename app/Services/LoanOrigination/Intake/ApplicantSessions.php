<?php

namespace App\Services\LoanOrigination\Intake;

use App\Models\OriginationApplication;
use App\Models\OriginationLink;
use App\Models\OriginationSession;
use App\Services\AuditLogService;
use Illuminate\Support\Str;

/**
 * Applicant sessions (origination decision 8): a random 64-character token,
 * kept only as its SHA-256, tied to one link and one application. A session
 * ends 30 minutes after its last use or 4 hours after it began; a newer code
 * verified for the same application ends the earlier ones, and so do submit
 * and sign-out.
 */
final class ApplicantSessions
{
    public const HEADER = 'X-Application-Session';

    public const REQUIRED = 'Ask for a code to continue your application.';

    public const EXPIRED = 'Your session has ended. Ask for a new code to continue.';

    public const WRONG_PURPOSE = 'This session can\'t do that. Ask for a new code to continue.';

    /** @return array{token: string, session: OriginationSession} */
    public function start(OriginationApplication $application, OriginationLink $link, string $purpose, ?string $ip, ?string $userAgent): array
    {
        OriginationSession::query()->where('application_id', $application->id)->whereNull('ended_at')->update(['ended_at' => now()]);

        $token = Str::random(64);
        $session = OriginationSession::query()->create([
            'application_id' => $application->id,
            'link_id' => $link->id,
            'purpose' => $purpose,
            'token_hash' => hash('sha256', $token),
            'ip_address' => $ip,
            'user_agent' => mb_substr((string) AuditLogService::cleanHeader($userAgent), 0, 255),
            'last_used_at' => now(),
            'expires_at' => now()->addMinutes((int) config('loan_origination_intake.sessions.max_minutes', 240)),
        ]);

        return ['token' => $token, 'session' => $session];
    }

    public function end(OriginationSession $session): void
    {
        OriginationSession::query()->whereKey($session->id)->whereNull('ended_at')->update(['ended_at' => now()]);
    }

    /**
     * The live session $token stands for on $link, touched, or null when
     * there is none: unknown, another link's, ended, idle too long or too old.
     */
    public function resume(OriginationLink $link, string $token): ?OriginationSession
    {
        /** @var ?OriginationSession $session */
        $session = OriginationSession::query()->where('token_hash', hash('sha256', $token))->first();

        if ($session === null || $session->link_id !== $link->id || $session->ended_at !== null) {
            return null;
        }

        $idle = (int) config('loan_origination_intake.sessions.idle_minutes', 30);

        if ($session->expires_at->isPast() || $session->last_used_at->copy()->addMinutes($idle)->isPast()) {
            $session->forceFill(['ended_at' => now()])->save();

            return null;
        }

        $session->forceFill(['last_used_at' => now()])->save();

        return $session;
    }
}
