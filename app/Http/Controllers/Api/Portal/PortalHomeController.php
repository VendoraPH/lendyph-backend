<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Services\Portal\MemberScope;
use App\Services\Portal\Read\DashboardReader;
use App\Services\Portal\Read\MembershipReader;
use App\Services\Portal\Read\ProfileReader;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * The member's dashboard, membership card and masked profile (portal design
 * §1, §3). The member comes only from the token.
 */
class PortalHomeController extends Controller
{
    public function __construct(private readonly MemberScope $scope) {}

    #[OA\Get(
        path: '/api/portal/dashboard',
        summary: 'The member\'s dashboard',
        description: 'Signed-in portal token. {member{first_name, member_number, member_since, status, branch_name}, share_capital{paid_up}, savings{total, available, accounts}, time_deposits{total, count}, loans{total_outstanding, active_count, overdue_amount}, next_payment{due_date, amount, loans_count}, pending_requests, unread{notifications, announcements}, documents (shared files), announcements[3]{id, title, publish_at, pinned}, recent_activity[5]{date, kind (loan_payment, savings, share_capital), description, amount}}. Money is a two-decimal peso string; money out of savings or share capital is negative. A block is null when its feature is off or the cooperative left its card out. Every figure comes from the same source as the detail page.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The dashboard')],
    )]
    public function dashboard(DashboardReader $dashboard): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $dashboard->dashboard($this->scope->portalUser())]);
    }

    #[OA\Get(
        path: '/api/portal/membership',
        summary: 'The member\'s membership card',
        description: 'Signed-in portal token. {member_number, member_type, status, membership_date, branch{name, address, contact_number}, standing[]{key (loans, savings, share_capital), label, status, amount}, support_contact}. Standing is factual only: loans defaulted, past_due, current or none; savings active, dormant or none; share capital paid_up (with its amount) or none. No overall label.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The membership card')],
    )]
    public function membership(MembershipReader $membership): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $membership->membership($this->scope->portalUser())]);
    }

    #[OA\Get(
        path: '/api/portal/profile',
        summary: 'The member\'s profile, masked',
        description: 'Signed-in portal token. {fields[]{key, label, value, masked}, editable_fields[]}. The mobile number and email are masked, the birthdate is the year only (`birth_year`), the street address reads "On file", and each government ID is its type and last 4 ("UMID •••• 4821", key `government_id`). Income, spouse fields, the photo, co-makers and ID images are never sent. `editable_fields` lists what a profile change request may change, empty when those requests are switched off.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The masked profile')],
    )]
    public function profile(ProfileReader $profile): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $profile->profile($this->scope->portalUser())]);
    }
}
