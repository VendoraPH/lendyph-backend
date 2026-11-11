<?php

namespace Tests\Feature\Portal\Admin;

use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\PortalLoginEvent;
use App\Models\PortalSetting;
use App\Models\PortalUser;
use App\Models\User;
use App\Services\Portal\Auth\PortalLogin;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\Feature\Portal\PortalTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The staff side of portal access (portal design §3, §4, §8): settings and
 * terms, counts and the accounts list, the access card, turning access off
 * and on, unlock, sign out everywhere, communications, and every route's
 * permission.
 */
class PortalAccessAdminTest extends TestCase
{
    use PortalTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_settings_are_company_wide_ranged_and_audited(): void
    {
        $this->actingAs($this->admin)->getJson('/api/portal-admin/settings')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.session_minutes', 60)
            ->assertJsonPath('data.idle_minutes', 15)
            ->assertJsonPath('data.withdrawal_methods', ['cash_pickup', 'bank_transfer', 'gcash'])
            ->assertJsonPath('data.terms', null);

        $this->putJson('/api/portal-admin/settings', ['enabled' => true])->assertStatus(422)->assertJsonValidationErrors('enabled');
        $this->putJson('/api/portal-admin/settings', ['session_minutes' => 10, 'idle_minutes' => 61, 'activation_code_hours' => 200, 'reset_code_hours' => 0, 'password_min_length' => 8])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['session_minutes', 'idle_minutes', 'activation_code_hours', 'reset_code_hours', 'password_min_length']);
        $this->putJson('/api/portal-admin/settings', ['session_minutes' => 20, 'idle_minutes' => 30])->assertStatus(422)->assertJsonValidationErrors('idle_minutes');
        $this->putJson('/api/portal-admin/settings', ['features' => ['credit_score' => true]])->assertStatus(422)->assertJsonValidationErrors('features');
        $this->putJson('/api/portal-admin/settings', ['withdrawal_methods' => []])->assertStatus(422)->assertJsonValidationErrors('withdrawal_methods');

        $this->postJson('/api/portal-admin/terms', ['version' => '2026-11', 'title' => 'Terms of Use (Draft)', 'terms_body' => 'Draft terms.', 'privacy_body' => 'Draft privacy notice.'])
            ->assertCreated()
            ->assertJsonPath('data.version', '2026-11');
        $this->postJson('/api/portal-admin/terms', ['version' => '2026-11', 'title' => 'x', 'terms_body' => 'x', 'privacy_body' => 'x'])->assertStatus(422)->assertJsonValidationErrors('version');
        $this->assertDatabaseHas('audit_logs', ['action' => 'portal_terms_published', 'user_id' => $this->admin->id]);

        $this->putJson('/api/portal-admin/settings', ['enabled' => true, 'session_minutes' => 90, 'features' => ['savings' => false]])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.session_minutes', 90)
            ->assertJsonPath('data.features.savings', false)
            ->assertJsonPath('data.features.loans', true)
            ->assertJsonPath('data.terms.version', '2026-11');

        $audit = AuditLog::query()->where('auditable_type', PortalSetting::class)->latest('id')->firstOrFail();
        $this->assertSame($this->admin->id, (int) $audit->user_id);
        $this->assertSame(60, $audit->old_values['session_minutes']);
        $this->assertSame(90, $audit->new_values['session_minutes']);

        $this->portalJson('GET', '/api/portal/config')->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.session_minutes', 90)
            ->assertJsonPath('data.features.savings', false)
            ->assertJsonPath('data.terms.version', '2026-11')
            ->assertJsonPath('data.password_rules.min_length', 10);

        $branchManager = User::factory()->inBranches($this->branch)->create();
        $branchManager->givePermissionTo('member_portal:settings');
        $this->actingAs($branchManager->fresh())->getJson('/api/portal-admin/settings')->assertStatus(403);
        $this->actingAs($branchManager->fresh())->putJson('/api/portal-admin/settings', ['session_minutes' => 30])->assertStatus(403);
    }

    public function test_staff_turn_access_off_and_back_on(): void
    {
        $this->enablePortal();
        $portalUser = $this->portalMember(['branch_id' => $this->branch->id]);
        $token = $this->portalToken($portalUser);
        $member = $portalUser->borrower;

        $this->actingAs($this->admin)->putJson("/api/portal-admin/members/{$member->id}/access", ['enabled' => false])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->putJson("/api/portal-admin/members/{$member->id}/access", ['enabled' => false, 'reason' => 'security_concern'])
            ->assertOk()
            ->assertJsonPath('data.state', 'disabled')
            ->assertJsonPath('data.disabled_reason', 'security_concern');

        $this->portalJson('GET', '/api/portal/auth/me', [], $token)->assertStatus(401);
        $this->portalJson('POST', '/api/portal/auth/login', ['member_number' => $this->memberNumber($portalUser), 'password' => $this->portalPassword()])
            ->assertStatus(403)->assertJsonPath('message', PortalLogin::DISABLED);
        $this->assertDatabaseHas('audit_logs', ['action' => 'portal_access_disabled', 'user_id' => $this->admin->id]);

        $this->actingAs($this->admin)->putJson("/api/portal-admin/members/{$member->id}/access", ['enabled' => true])
            ->assertOk()->assertJsonPath('data.state', 'active');
        $this->portalJson('POST', '/api/portal/auth/login', ['member_number' => $this->memberNumber($portalUser), 'password' => $this->portalPassword()])->assertOk();

        $member->forceFill(['status' => 'withdrawn', 'membership_ended_on' => now()->toDateString()])->save();
        $this->actingAs($this->admin)->putJson("/api/portal-admin/members/{$member->id}/access", ['enabled' => true])->assertStatus(422);

        $notInvited = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $this->putJson("/api/portal-admin/members/{$notInvited->id}/access", ['enabled' => false, 'reason' => 'administrative'])->assertStatus(422);
    }

    public function test_staff_unlock_and_sign_out_everywhere(): void
    {
        $this->enablePortal();
        $portalUser = $this->portalMember(['branch_id' => $this->branch->id], ['status' => PortalUser::STATUS_LOCKED, 'failed_attempts' => 2]);
        $member = $portalUser->borrower;

        $this->actingAs($this->admin)->getJson("/api/portal-admin/members/{$member->id}/access")->assertJsonPath('data.state', 'locked');
        $this->postJson("/api/portal-admin/members/{$member->id}/unlock")->assertOk()->assertJsonPath('data.unlocked', true)->assertJsonPath('data.state', 'active');
        $this->postJson("/api/portal-admin/members/{$member->id}/unlock")->assertOk()->assertJsonPath('data.unlocked', false);
        $this->assertSame(1, AuditLog::query()->where('action', 'portal_unlocked')->count());
        $this->assertSame(0, $portalUser->fresh()->failed_attempts);

        $this->portalToken($portalUser);
        $this->portalToken($portalUser);
        $this->actingAs($this->admin)->postJson("/api/portal-admin/members/{$member->id}/sign-out")->assertOk()->assertJsonPath('data.sessions_ended', 2);
        $this->assertSame(0, $portalUser->tokens()->count());
        $this->assertSame(1, $portalUser->notifications()->count());
    }

    public function test_counts_accounts_and_communications(): void
    {
        $this->enablePortal();
        $active = $this->portalMember(['branch_id' => $this->branch->id, 'first_name' => 'Zenaida', 'contact_number' => '09170000001'], ['last_login_at' => now()]);
        $invited = $this->portalMember(['branch_id' => $this->branch->id], ['password' => null, 'status' => PortalUser::STATUS_INVITED]);
        $this->portalMember(['branch_id' => $this->branch->id], ['status' => PortalUser::STATUS_DISABLED]);
        Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);

        $stats = $this->actingAs($this->admin)->getJson('/api/portal-admin/stats?branch_id='.$this->branch->id)->assertOk()->json('data');
        $this->assertSame(1, $stats['active']);
        $this->assertSame(1, $stats['active_this_month']);
        $this->assertSame(1, $stats['invited']);
        $this->assertSame(1, $stats['codes_expired'], 'The invited member has no live code.');
        $this->assertSame(1, $stats['disabled']);
        $this->assertGreaterThanOrEqual(3, $stats['eligible']);

        $rows = $this->getJson('/api/portal-admin/accounts?status=not_invited&branch_id='.$this->branch->id)->assertOk()->json('data');
        $this->assertNotEmpty($rows);
        $this->assertSame(['not_invited'], array_values(array_unique(array_column($rows, 'state'))));

        $row = $this->getJson('/api/portal-admin/accounts?search=Zenaida')->assertOk()->json('data.0');
        $this->assertSame($active->borrower_id, $row['borrower_id']);
        $this->assertSame('active', $row['state']);
        $this->assertSame('09170000001', $row['contact_number']);

        $viewer = User::factory()->inBranches($this->branch)->create();
        $viewer->givePermissionTo('member_portal:view');
        $this->actingAs($viewer->fresh())->getJson('/api/portal-admin/accounts?search=Zenaida')->assertOk()->assertJsonPath('data.0.contact_number', null);

        $this->actingAs($this->admin)->postJson("/api/portal-admin/members/{$invited->borrower_id}/invite", ['identity_checked_as' => 'UMID'])->assertCreated();
        $log = $this->getJson("/api/portal-admin/members/{$invited->borrower_id}/communications")->assertOk()->json('data');
        $this->assertSame('portal_code_issued', $log[0]['kind']);
        $this->assertSame('Code issued (activation)', $log[0]['description']);
    }

    public function test_a_member_actor_reads_as_the_member_on_the_audit_trail(): void
    {
        $this->enablePortal();
        $portalUser = $this->portalMember(['first_name' => 'Rosa', 'last_name' => 'Dimaculangan', 'middle_name' => null, 'membership_number' => 'M-0042']);

        $this->portalJson('POST', '/api/portal/auth/login', ['member_number' => 'M-0042', 'password' => $this->portalPassword()])->assertOk();

        $row = AuditLog::query()->where('action', 'portal_signed_in')->latest('id')->firstOrFail();
        $this->assertSame('Member: Rosa Dimaculangan (M-0042)', AuditLogResource::memberActorLabel($row->load('portalUser.borrower')));

        $this->actingAs($this->admin)->getJson("/api/audit-logs/{$row->id}")
            ->assertOk()
            ->assertJsonPath('data.actor_type', 'member')
            ->assertJsonPath('data.actor_label', 'Member: Rosa Dimaculangan (M-0042)')
            ->assertJsonPath('data.user', null);
    }

    public function test_every_staff_portal_route_needs_its_permission(): void
    {
        $this->enablePortal();
        $member = $this->portalMember(['branch_id' => $this->branch->id]);
        $stranger = User::factory()->inBranches($this->branch)->create();
        $stranger->givePermissionTo(['borrowers:view', 'borrowers:update']);
        $stranger = $stranger->fresh();

        $swept = 0;
        $leaks = [];

        foreach (Router::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            if (! str_starts_with($route->uri(), 'api/portal-admin/')) {
                continue;
            }

            $uri = '/'.preg_replace('/\{\w+\}/', (string) $member->borrower_id, $route->uri());

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->actingAs($stranger);
                $status = $this->json($method, $uri, ['identity_checked_as' => 'UMID', 'enabled' => false, 'reason' => 'administrative'])->status();
                $swept++;

                if ($status !== 403) {
                    $leaks[] = "{$method} /{$route->uri()} answered {$status}";
                }
            }
        }

        $this->assertGreaterThanOrEqual(12, $swept);
        $this->assertSame([], $leaks, "These staff portal routes answer without their permission:\n  ".implode("\n  ", $leaks));
        $this->assertSame(PortalUser::STATUS_ACTIVE, $member->fresh()->status);
    }

    public function test_old_sign_in_history_is_pruned_after_365_days(): void
    {
        $portalUser = $this->portalMember();
        $old = PortalLoginEvent::factory()->create(['portal_user_id' => $portalUser->id]);
        PortalLoginEvent::query()->whereKey($old->id)->update(['created_at' => now()->subDays(366)]);
        $recent = PortalLoginEvent::factory()->create(['portal_user_id' => $portalUser->id]);
        $portalUser->createToken('Old device', [PortalUser::ABILITY], now()->subMinute());

        $this->artisan('portal:prune-login-events --dry-run')->assertSuccessful();
        $this->assertDatabaseHas('portal_login_events', ['id' => $old->id]);

        $this->artisan('portal:prune-login-events')->assertSuccessful();
        $this->assertDatabaseMissing('portal_login_events', ['id' => $old->id]);
        $this->assertDatabaseHas('portal_login_events', ['id' => $recent->id]);
        $this->assertSame(0, $portalUser->tokens()->count());
    }
}
