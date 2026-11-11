<?php

namespace Tests\Feature\Portal\Foundation;

use App\Models\PortalSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The portal migrations (portal design §2): eleven tables and three column
 * sets, the portal seeded off, and a second run changes nothing.
 */
class PortalSchemaTest extends TestCase
{
    public function test_the_eleven_tables_and_the_column_sets_exist_with_the_portal_off(): void
    {
        foreach ([
            'portal_settings', 'portal_terms_versions', 'portal_users', 'portal_codes', 'portal_otp_challenges',
            'portal_login_events', 'portal_requests', 'portal_request_events', 'portal_announcements',
            'portal_announcement_reads', 'portal_idempotency_keys',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} is missing.");
        }

        $this->assertTrue(Schema::hasColumns('audit_logs', ['actor_type', 'portal_user_id']));
        $this->assertTrue(Schema::hasColumns('documents', ['portal_shared_at', 'portal_shared_by']));
        $this->assertTrue(Schema::hasColumns('savings_products', ['portal_account_requests', 'portal_withdrawal_requests']));

        $settings = PortalSetting::query()->sole();
        $this->assertFalse($settings->enabled);
        $this->assertFalse($settings->otp_required);
        $this->assertSame(['cash_pickup', 'bank_transfer', 'gcash'], $settings->withdrawal_methods);
        $this->assertSame([7, 30], [$settings->withdrawal_hold_days, $settings->request_expiry_days]);
        $this->assertNull(DB::table('audit_logs')->whereNotNull('actor_type')->value('id'), 'Existing audit rows keep their meaning.');
    }

    public function test_running_the_migrations_again_changes_nothing(): void
    {
        $before = [DB::table('portal_settings')->count(), (string) DB::table('portal_settings')->value('updated_at')];

        foreach (['2026_11_11_700000_create_portal_tables', '2026_11_11_700100_add_portal_columns'] as $name) {
            (require database_path("migrations/{$name}.php"))->up();
        }

        $this->assertSame($before, [DB::table('portal_settings')->count(), (string) DB::table('portal_settings')->value('updated_at')]);
    }
}
