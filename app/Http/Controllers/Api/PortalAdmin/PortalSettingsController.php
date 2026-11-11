<?php

namespace App\Http\Controllers\Api\PortalAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PortalAdmin\UpdatePortalSettingsRequest;
use App\Models\PortalSetting;
use App\Models\PortalTermsVersion;
use App\Services\AuditLogService;
use App\Services\BranchAccess;
use App\Services\Portal\PortalSettings;
use App\Services\Portal\PortalTerms;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Member Portal settings and terms (portal design §3, staff). Company-wide:
 * reading and changing them needs `member_portal:settings` and
 * `branches:view_all`. Audited.
 */
class PortalSettingsController extends Controller
{
    public function __construct(
        private readonly PortalSettings $settings,
        private readonly PortalTerms $terms,
    ) {}

    #[OA\Get(
        path: '/api/portal-admin/settings',
        summary: 'Member Portal settings',
        description: 'Needs member_portal:settings and branches:view_all. Every setting, plus `otp_blocked_reason` (why OTP can\'t be switched on now, in words, or null) and `terms` (the current version, or null).',
        tags: ['Member Portal (staff)'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The settings'), new OA\Response(response: 403, description: 'Missing member_portal:settings or branches:view_all')],
    )]
    public function show(Request $request): JsonResponse
    {
        $this->authorizeCompanyWide($request);

        return response()->json(['success' => true, 'data' => $this->present($this->settings->current())]);
    }

    #[OA\Put(
        path: '/api/portal-admin/settings',
        summary: 'Save Member Portal settings',
        description: 'Partial. Ranges: session 15–240 minutes, idle 5–60 (not above the session), sign-in tries 3–10, lockout 5–1440 minutes, hard lock after 2–10 lockouts, password minimum 10–128, activation codes 24–168 hours, reset codes 1–72 hours, hold 1–30 days, request expiry 1–90 days. `features` is merged into the stored switches. `otp_required: true` is 422 while text messages are only logged; `enabled: true` is 422 until terms are published. Audited (old and new values).',
        tags: ['Member Portal (staff)'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'enabled', type: 'boolean'),
            new OA\Property(property: 'session_minutes', type: 'integer', example: 60),
            new OA\Property(property: 'idle_minutes', type: 'integer', example: 15),
            new OA\Property(property: 'otp_required', type: 'boolean'),
            new OA\Property(property: 'features', type: 'object'),
            new OA\Property(property: 'withdrawal_methods', type: 'array', items: new OA\Items(type: 'string', enum: PortalSetting::WITHDRAWAL_METHODS)),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The saved settings'),
            new OA\Response(response: 403, description: 'Missing member_portal:settings or branches:view_all'),
            new OA\Response(response: 422, description: 'In words, by field'),
        ],
    )]
    public function update(UpdatePortalSettingsRequest $request): JsonResponse
    {
        $settings = $this->settings->current();
        $changes = $request->validated();

        if (array_key_exists('features', $changes)) {
            $changes['features'] = [...$this->settings->features($settings), ...array_map(fn ($on) => (bool) $on, $changes['features'])];
        }

        foreach (['dashboard_cards', 'withdrawal_methods'] as $list) {
            if (array_key_exists($list, $changes)) {
                $changes[$list] = array_values($changes[$list]);
            }
        }

        $settings->fill($changes);

        if ($settings->isDirty(array_keys($changes))) {
            $settings->forceFill(['updated_by' => $request->user()->getKey()])->save();
        }

        return response()->json(['success' => true, 'message' => 'Member Portal settings saved.', 'data' => $this->present($settings->refresh())]);
    }

    #[OA\Post(
        path: '/api/portal-admin/terms',
        summary: 'Publish a new version of the portal terms',
        description: 'Needs member_portal:settings and branches:view_all. Published at once and never edited (append-only). With `requires_reacceptance`, every member must accept it before using the portal again. 422 for a version that already exists. Audited.',
        tags: ['Member Portal (staff)'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['version', 'title', 'terms_body', 'privacy_body'], properties: [
            new OA\Property(property: 'version', type: 'string', example: '2026-11'),
            new OA\Property(property: 'title', type: 'string'),
            new OA\Property(property: 'terms_body', type: 'string'),
            new OA\Property(property: 'privacy_body', type: 'string'),
            new OA\Property(property: 'consent_body', type: 'string'),
            new OA\Property(property: 'requires_reacceptance', type: 'boolean'),
        ])),
        responses: [new OA\Response(response: 201, description: 'The published version'), new OA\Response(response: 422, description: 'In words, by field')],
    )]
    public function publishTerms(Request $request): JsonResponse
    {
        $this->authorizeCompanyWide($request);

        $validated = $request->validate([
            'version' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9.\-]*$/', 'unique:portal_terms_versions,version'],
            'title' => ['required', 'string', 'max:150'],
            'terms_body' => ['required', 'string', 'max:200000'],
            'privacy_body' => ['required', 'string', 'max:200000'],
            'consent_body' => ['nullable', 'string', 'max:20000'],
            'requires_reacceptance' => ['sometimes', 'boolean'],
        ], [
            'version.unique' => 'That terms version already exists. Use a new version name.',
            'version.regex' => 'Use letters, numbers, dots and dashes for the version (for example 2026-11).',
        ]);

        $version = PortalTermsVersion::query()->create([
            ...$validated,
            'requires_reacceptance' => (bool) ($validated['requires_reacceptance'] ?? false),
            'is_draft' => false,
            'published_at' => now(),
            'published_by' => $request->user()->getKey(),
        ]);

        AuditLogService::log(
            'portal_terms_published',
            $version,
            null,
            ['version' => $version->version, 'requires_reacceptance' => $version->requires_reacceptance],
            "Portal terms version {$version->version} published".($version->requires_reacceptance ? ' (members must accept again)' : ''),
        );

        return response()->json(['success' => true, 'data' => $this->presentTerms($version)], 201);
    }

    /** @return array<string, mixed> */
    private function present(PortalSetting $settings): array
    {
        $settings->loadMissing('updater:id,first_name,last_name');
        $terms = $this->terms->current();

        return [
            'enabled' => $settings->enabled,
            'portal_name' => $settings->portal_name,
            'welcome_message' => $settings->welcome_message,
            'support_contact' => $settings->support_contact,
            'payment_instructions' => $settings->payment_instructions,
            'session_minutes' => $settings->session_minutes,
            'idle_minutes' => $settings->idle_minutes,
            'max_login_attempts' => $settings->max_login_attempts,
            'lockout_minutes' => $settings->lockout_minutes,
            'hard_lock_after' => $settings->hard_lock_after,
            'password_min_length' => $settings->password_min_length,
            'activation_code_hours' => $settings->activation_code_hours,
            'reset_code_hours' => $settings->reset_code_hours,
            'otp_required' => $settings->otp_required,
            'otp_blocked_reason' => $this->settings->otpBlockedReason(),
            'features' => $this->settings->features($settings),
            'dashboard_cards' => array_values($settings->dashboard_cards ?? []),
            'withdrawal_methods' => array_values($settings->withdrawal_methods ?? []),
            'withdrawal_hold_days' => $settings->withdrawal_hold_days,
            'request_expiry_days' => $settings->request_expiry_days,
            'terms' => $terms === null ? null : $this->presentTerms($terms),
            'updated_by' => $settings->updater === null ? null : ['id' => (int) $settings->updater->id, 'name' => $settings->updater->full_name],
            'updated_at' => $settings->updated_at,
        ];
    }

    /** @return array<string, mixed> */
    private function presentTerms(PortalTermsVersion $terms): array
    {
        return [
            'version' => $terms->version,
            'title' => $terms->title,
            'requires_reacceptance' => $terms->requires_reacceptance,
            'published_at' => $terms->published_at,
        ];
    }

    private function authorizeCompanyWide(Request $request): void
    {
        $user = $request->user();

        if (! $user->can('member_portal:settings') || ! $user->can(BranchAccess::VIEW_ALL)) {
            throw new AuthorizationException(UpdatePortalSettingsRequest::COMPANY_WIDE_REFUSAL);
        }
    }
}
