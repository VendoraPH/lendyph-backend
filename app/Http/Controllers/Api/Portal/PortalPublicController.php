<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Services\Portal\Auth\PasswordRules;
use App\Services\Portal\PortalSettings;
use App\Services\Portal\PortalTerms;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * The portal's public reads (portal design §3): its configuration and the
 * current terms. No token, nothing secret.
 */
class PortalPublicController extends Controller
{
    public function __construct(
        private readonly PortalSettings $settings,
        private readonly PortalTerms $terms,
        private readonly PasswordRules $passwords,
    ) {}

    #[OA\Get(
        path: '/api/portal/config',
        summary: 'Member Portal configuration',
        description: 'Public. Answers while the portal is off, with `enabled: false`. `password_rules` describes the password rule; `terms` is the current version (null when none is published); `features` maps each part of the portal to on or off. No secrets.',
        tags: ['Member Portal'],
        responses: [new OA\Response(response: 200, description: '{enabled, portal_name, logo_url, welcome_message, support_contact, password_rules, otp_required, session_minutes, idle_minutes, terms, features, dashboard_cards}')],
    )]
    public function config(): JsonResponse
    {
        $settings = $this->settings->current();
        $terms = $this->terms->current();

        return response()->json(['success' => true, 'data' => [
            'enabled' => $settings->enabled,
            'portal_name' => $settings->portal_name,
            'logo_url' => '/branding/logo',
            'welcome_message' => $settings->welcome_message,
            'support_contact' => $settings->support_contact,
            'password_rules' => $this->passwords->describe(),
            'otp_required' => $settings->otp_required,
            'session_minutes' => $settings->session_minutes,
            'idle_minutes' => $settings->idle_minutes,
            'terms' => $terms === null ? null : ['version' => $terms->version, 'title' => $terms->title],
            'features' => $this->settings->features($settings),
            'dashboard_cards' => array_values($settings->dashboard_cards ?? []),
        ]]);
    }

    #[OA\Get(
        path: '/api/portal/terms',
        summary: 'The current Terms of Use, Privacy Notice and e-communication consent',
        description: 'Public; 503 `portal_off` while the portal is off; 404 when no version is published.',
        tags: ['Member Portal'],
        responses: [
            new OA\Response(response: 200, description: '{version, title, terms_body, privacy_body, consent_body, requires_reacceptance, published_at}'),
            new OA\Response(response: 404, description: 'No terms published yet'),
            new OA\Response(response: 503, description: 'The portal is off (`code: portal_off`)'),
        ],
    )]
    public function terms(): JsonResponse
    {
        $terms = $this->terms->current();

        abort_if($terms === null, 404, 'No portal terms are published yet.');

        return response()->json(['success' => true, 'data' => [
            'version' => $terms->version,
            'title' => $terms->title,
            'terms_body' => $terms->terms_body,
            'privacy_body' => $terms->privacy_body,
            'consent_body' => $terms->consent_body,
            'requires_reacceptance' => $terms->requires_reacceptance,
            'published_at' => $terms->published_at,
        ]]);
    }
}
