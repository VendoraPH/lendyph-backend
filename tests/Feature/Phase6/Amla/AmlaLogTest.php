<?php

namespace Tests\Feature\Phase6\Amla;

use App\Exceptions\AmlaRecordIsAppendOnlyException;
use App\Models\AmlaLogEntry;
use App\Models\AuditLog;
use App\Services\Amla\Foundation\AmlaLog;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * AMLA's own log (AMLA design §3.6, §4.14): append-only, kept forever, and
 * never the main audit trail, so nobody holding `audit_logs:view` is tipped
 * off. Writes are refused at the model level, on every path, as AuditLog and
 * the append-only savings rows refuse them.
 */
class AmlaLogTest extends TestCase
{
    use AmlaTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function entry(): AmlaLogEntry
    {
        return app(AmlaLog::class)->record('log_viewed', $this->admin, details: ['page' => 1]);
    }

    public function test_it_records_the_actor_and_the_request_and_writes_nothing_to_the_audit_trail(): void
    {
        $audit = AuditLog::query()->count();

        $entry = $this->entry();

        $this->assertSame('log_viewed', $entry->action);
        $this->assertSame('user', $entry->actor_type);
        $this->assertSame($this->admin->id, $entry->user_id);
        $this->assertSame(['page' => 1], $entry->fresh()->details);
        $this->assertNotNull($entry->created_at);
        $this->assertSame($audit, AuditLog::query()->count());
    }

    public function test_a_system_entry_has_no_user(): void
    {
        $entry = app(AmlaLog::class)->record('change_approved', null, 'settings_version', 1);

        $this->assertSame('system', $entry->actor_type);
        $this->assertNull($entry->user_id);
        $this->assertSame('settings_version', $entry->subject_type);
        $this->assertSame(1, $entry->subject_id);
    }

    public function test_an_unknown_action_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(AmlaLog::class)->record('something_else', $this->admin);
    }

    public function test_a_row_is_never_updated(): void
    {
        $entry = $this->entry();
        $entry->action = 'access_denied';

        $this->expectException(AmlaRecordIsAppendOnlyException::class);
        $entry->save();
    }

    public function test_a_row_is_never_updated_quietly_or_incremented(): void
    {
        $entry = $this->entry();

        foreach ([
            fn () => $entry->forceFill(['action' => 'access_denied'])->saveQuietly(),
            fn () => $entry->increment('subject_id'),
            fn () => $entry->incrementEach(['subject_id' => 1]),
        ] as $write) {
            try {
                $write();
                $this->fail('An AMLA log row was changed.');
            } catch (AmlaRecordIsAppendOnlyException) {
                $this->assertSame('log_viewed', $entry->fresh()->action);
            }
        }
    }

    public function test_a_row_is_never_deleted(): void
    {
        $entry = $this->entry();

        foreach ([fn () => $entry->delete(), fn () => $entry->forceDelete(), fn () => AmlaLogEntry::destroy($entry->id)] as $delete) {
            try {
                $delete();
                $this->fail('An AMLA log row was deleted.');
            } catch (AmlaRecordIsAppendOnlyException) {
                $this->assertNotNull(AmlaLogEntry::query()->find($entry->id));
            }
        }
    }

    public function test_bulk_writes_are_refused_before_any_sql_runs(): void
    {
        $entry = $this->entry();

        foreach ([
            fn () => AmlaLogEntry::query()->update(['action' => 'access_denied']),
            fn () => AmlaLogEntry::query()->delete(),
            fn () => AmlaLogEntry::query()->forceDelete(),
            fn () => AmlaLogEntry::query()->truncate(),
            fn () => AmlaLogEntry::query()->increment('subject_id'),
            fn () => AmlaLogEntry::query()->upsert([['id' => $entry->id, 'action' => 'x']], ['id']),
            fn () => AmlaLogEntry::query()->updateOrCreate(['id' => $entry->id], ['action' => 'x']),
        ] as $write) {
            try {
                $write();
                $this->fail('A bulk write reached the AMLA log.');
            } catch (AmlaRecordIsAppendOnlyException) {
                $this->assertSame('log_viewed', $entry->fresh()->action);
            }
        }
    }

    public function test_the_log_endpoint_needs_the_audit_permission_and_head_office_access(): void
    {
        $this->actingAs($this->amlaUser(['amla:view', 'amla:settings', 'branches:view_all']))
            ->getJson('/api/amla/log')->assertForbidden()
            ->assertJsonPath('message', 'This needs the AMLA audit history permission.');

        $this->actingAs($this->amlaUser(['amla:audit']))
            ->getJson('/api/amla/log')->assertForbidden()
            ->assertJsonPath('message', 'AMLA compliance covers the whole cooperative, so this needs head-office access to all branches.');
    }

    public function test_reading_the_log_is_itself_logged_and_lists_newest_first(): void
    {
        $auditor = $this->amlaUser(['amla:audit', 'branches:view_all']);
        app(AmlaLog::class)->record('change_requested', $this->admin, 'change_request', 7, ['type' => 'settings']);
        $before = $this->outsideAmlaCounts();

        $response = $this->actingAs($auditor)->getJson('/api/amla/log')->assertOk();
        $this->assertNothingWrittenOutsideAmla($before);

        $first = $response->json('data.0');
        $this->assertSame('log_viewed', $first['action']);
        $this->assertSame('AMLA log viewed', $first['action_words']);
        $this->assertSame($auditor->id, $first['actor']['id']);
        $this->assertSame('Change asked for', $response->json('data.1.action_words'));
        $this->assertSame('Change request #7', $response->json('data.1.subject_words'));
        $this->assertArrayHasKey('total', $response->json('meta'));
    }

    public function test_the_log_filters_by_action_and_user(): void
    {
        $auditor = $this->amlaUser(['amla:audit', 'branches:view_all']);
        app(AmlaLog::class)->record('change_requested', $this->admin, 'change_request', 7);

        $rows = $this->actingAs($auditor)->getJson('/api/amla/log?action=change_requested&user_id='.$this->admin->id)->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('change_requested', $rows[0]['action']);

        $this->actingAs($auditor)->getJson('/api/amla/log?action=nonsense')->assertUnprocessable();
    }
}
