<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Fee;
use App\Models\User;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The audit trail stores whole model rows, so for User it was storing the
 * bcrypt password and remember_token — and AuditLogResource returns those
 * blobs verbatim behind `audit_logs:view`, which the read-only `viewer` role
 * holds. Any viewer could page the trail and collect every hash on the
 * deployment for offline cracking.
 */
class AuditLogRedactionTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function blobsFor(User $user, string $action): array
    {
        $log = AuditLog::where('auditable_type', $user->getMorphClass())
            ->where('auditable_id', $user->id)
            ->where('action', $action)
            ->latest('id')
            ->firstOrFail();

        return [$log->old_values ?? [], $log->new_values ?? [], $log];
    }

    public function test_creating_a_user_does_not_record_the_password_hash(): void
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);

        [$old, $new] = $this->blobsFor($user, 'created');

        $this->assertArrayNotHasKey('password', $new);
        $this->assertArrayNotHasKey('remember_token', $new);
        $this->assertArrayNotHasKey('password', $old);
    }

    public function test_an_admin_password_reset_records_the_action_but_not_the_hash(): void
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);

        $this->postJson("/api/users/{$user->id}/reset-password", [
            'password' => 'TemporaryPass!234',
            'password_confirmation' => 'TemporaryPass!234',
        ])->assertOk();

        [$old, $new, $log] = $this->blobsFor($user, 'updated');

        $this->assertArrayNotHasKey('password', $old, 'The PREVIOUS hash leaked via getOriginal().');
        $this->assertArrayNotHasKey('password', $new, 'The NEW hash leaked via getChanges().');
        $this->assertArrayNotHasKey('remember_token', $old);
        $this->assertArrayNotHasKey('remember_token', $new);

        // The row itself must survive — it is the compliance record, and the
        // handler deliberately uses save() rather than saveQuietly() to keep it.
        $this->assertSame('updated', $log->action);
        $this->assertNotNull($log->created_at);
        $this->assertSame($this->admin->id, $log->user_id);
    }

    public function test_a_self_service_password_change_does_not_record_the_hash(): void
    {
        $user = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password' => 'OldPassword!234',
        ]);

        $this->actingAs($user)
            ->postJson('/api/auth/change-password', [
                'current_password' => 'OldPassword!234',
                'new_password' => 'BrandNewPass!234',
                'new_password_confirmation' => 'BrandNewPass!234',
            ])->assertOk();

        [$old, $new] = $this->blobsFor($user, 'updated');

        $this->assertArrayNotHasKey('password', $old);
        $this->assertArrayNotHasKey('password', $new);
    }

    public function test_non_secret_user_changes_are_still_recorded(): void
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'first_name' => 'Before']);

        $user->update(['first_name' => 'After']);

        [$old, $new] = $this->blobsFor($user, 'updated');

        $this->assertSame('Before', $old['first_name'] ?? null);
        $this->assertSame('After', $new['first_name'] ?? null);
    }

    public function test_a_model_without_a_redaction_list_is_unaffected(): void
    {
        $fee = Fee::factory()->create(['name' => 'Before']);
        $fee->update(['name' => 'After']);

        $log = AuditLog::where('auditable_type', $fee->getMorphClass())
            ->where('auditable_id', $fee->id)
            ->where('action', 'updated')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('Before', ($log->old_values ?? [])['name'] ?? null);
        $this->assertSame('After', ($log->new_values ?? [])['name'] ?? null);
    }
}
