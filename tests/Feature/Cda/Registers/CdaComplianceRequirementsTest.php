<?php

namespace Tests\Feature\Cda\Registers;

use App\Models\AuditLog;
use App\Models\CdaComplianceRequirement;
use App\Models\CdaNoticeSent;
use App\Models\User;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\PeriodOpener;
use App\Services\Cda\Reminders\DeadlineReminders;
use Illuminate\Support\Carbon;
use Tests\Feature\Cda\Foundation\CdaTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Custom compliance calendar items (gap round B6): reading needs `cda:view`,
 * writing `cda:prepare`, both with `branches:view_all`; every write is
 * audited; the calendar lists them among the reports (`kind`) by effective
 * due date; pending ones get the deadline milestones once each, recorded as
 * `cda_requirement` subjects, and filed ones get none.
 */
class CdaComplianceRequirementsTest extends TestCase
{
    use CdaTestHelpers, SetupLendyPH;

    private User $preparer;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00', 'Asia/Manila'));
        app(PeriodOpener::class)->ensure(2026);
        $this->preparer = $this->userWith(['branches:view_all', CdaAccess::VIEW, CdaAccess::PREPARE]);
        $this->viewer = $this->userWith(['branches:view_all', CdaAccess::VIEW]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function create(array $body = []): array
    {
        return $this->actingAs($this->preparer)->postJson('/api/cda/periods/2026/requirements', [
            'title' => 'Mayor\'s permit renewal',
            'default_due_on' => '2027-01-20',
            ...$body,
        ])->assertCreated()->json('data');
    }

    public function test_reading_needs_view_writing_needs_prepare_and_every_write_is_audited(): void
    {
        $noViewAll = $this->userWith([CdaAccess::VIEW, CdaAccess::PREPARE]);

        $this->actingAs($this->viewer)->postJson('/api/cda/periods/2026/requirements', ['title' => 'BIR', 'default_due_on' => '2027-01-31'])->assertForbidden();
        $this->actingAs($noViewAll)->getJson('/api/cda/periods/2026/requirements')->assertForbidden();
        $this->actingAs($this->preparer)->postJson('/api/cda/periods/2031/requirements', ['title' => 'BIR', 'default_due_on' => '2032-01-31'])->assertNotFound();

        $row = $this->create(['reference' => 'Permit 2026-114', 'description' => 'Renew at the city hall']);
        $this->assertSame('requirement', $row['kind']);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('2027-01-20', $row['effective_deadline']);
        $this->assertSame(['id' => $this->preparer->id, 'name' => $this->preparer->full_name], $row['created_by']);

        $this->actingAs($this->viewer)->getJson('/api/cda/periods/2026/requirements')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Mayor\'s permit renewal');
        $this->actingAs($this->viewer)->putJson("/api/cda/requirements/{$row['id']}", ['status' => 'filed', 'filed_on' => '2026-11-19'])->assertForbidden();
        $this->actingAs($this->viewer)->deleteJson("/api/cda/requirements/{$row['id']}")->assertForbidden();

        $this->actingAs($this->preparer)->putJson("/api/cda/requirements/{$row['id']}", ['extended_due_on' => '2027-01-10'])
            ->assertStatus(422)->assertJsonValidationErrors(['extended_due_on']);
        $this->actingAs($this->preparer)->putJson("/api/cda/requirements/{$row['id']}", ['status' => 'filed'])
            ->assertStatus(422)->assertJsonValidationErrors(['filed_on']);
        $this->actingAs($this->preparer)->putJson("/api/cda/requirements/{$row['id']}", ['status' => 'filed', 'filed_on' => '2026-11-21'])
            ->assertStatus(422)->assertJsonValidationErrors(['filed_on']);
        $this->actingAs($this->preparer)->putJson("/api/cda/requirements/{$row['id']}", ['filed_on' => '2026-11-19'])
            ->assertStatus(422)->assertJsonValidationErrors(['filed_on']);

        $this->actingAs($this->preparer)->putJson("/api/cda/requirements/{$row['id']}", ['extended_due_on' => '2027-02-15'])->assertOk()
            ->assertJsonPath('data.effective_deadline', '2027-02-15');
        $this->actingAs($this->preparer)->putJson("/api/cda/requirements/{$row['id']}", ['status' => 'filed', 'filed_on' => '2026-11-19'])->assertOk()
            ->assertJsonPath('data.filed', true)
            ->assertJsonPath('data.filed_on', '2026-11-19');
        $this->actingAs($this->preparer)->putJson("/api/cda/requirements/{$row['id']}", ['status' => 'pending'])->assertOk()
            ->assertJsonPath('data.filed_on', null);
        $this->actingAs($this->preparer)->deleteJson("/api/cda/requirements/{$row['id']}")->assertOk();
        $this->actingAs($this->preparer)->deleteJson("/api/cda/requirements/{$row['id']}")->assertNotFound();

        $audit = AuditLog::query()->where('auditable_type', CdaComplianceRequirement::class)->where('auditable_id', $row['id'])->orderBy('id')->get();
        $this->assertSame(['created', 'updated', 'updated', 'updated', 'deleted'], $audit->pluck('action')->all());
        $this->assertSame('Compliance requirement "Mayor\'s permit renewal" (2026) changed; it is filed.', $audit[2]->description);
        $this->assertTrue($audit->every(fn (AuditLog $log) => $log->user_id === $this->preparer->id));
    }

    public function test_the_calendar_lists_requirements_among_the_reports_by_effective_deadline(): void
    {
        $this->create(['title' => 'BIR annual registration', 'default_due_on' => '2027-01-31']);
        $this->create(['title' => 'Late item', 'default_due_on' => '2027-04-01', 'extended_due_on' => '2027-06-30']);
        CdaComplianceRequirement::factory()->create(['year' => 2025, 'title' => 'Last year']);

        $rows = $this->actingAs($this->viewer)->getJson('/api/cda/periods/2026/calendar')->assertOk()->json('data');
        $requirements = array_values(array_filter($rows, fn (array $row) => $row['kind'] === 'requirement'));

        $this->assertSame(['BIR annual registration', 'Late item'], array_column($requirements, 'title'));
        $this->assertSame('2027-06-30', $requirements[1]['effective_deadline']);
        $this->assertTrue(collect($rows)->where('kind', 'report')->isNotEmpty());
        $this->assertSame('requirement', $rows[array_key_last($rows)]['kind'], 'The extended item is due after every report.');
        $deadlines = array_column($rows, 'effective_deadline');
        $sorted = $deadlines;
        sort($sorted);
        $this->assertSame($sorted, $deadlines);
    }

    public function test_pending_requirements_get_each_milestone_once_and_filed_ones_none(): void
    {
        $pending = CdaComplianceRequirement::factory()->create(['title' => 'Mayor\'s permit renewal', 'default_due_on' => '2027-01-20']);
        CdaComplianceRequirement::factory()->create(['title' => 'Already filed', 'default_due_on' => '2027-01-20', 'status' => 'filed', 'filed_on' => '2026-12-01']);
        CdaComplianceRequirement::factory()->create(['title' => 'Does not apply', 'default_due_on' => '2027-01-20', 'status' => 'not_applicable']);
        $approver = $this->userWith(['branches:view_all', CdaAccess::VIEW, CdaAccess::APPROVE]);

        $run = function (string $date): void {
            Carbon::setTestNow(Carbon::parse($date.' 00:50:00', 'Asia/Manila'));
            app(DeadlineReminders::class)->run();
        };
        $reminders = fn (User $user) => $user->notifications()->where('type', DeadlineReminders::TYPE)->get()
            ->filter(fn ($notice) => isset($notice->data['data']['requirement_id']))->values();

        $run('2026-12-21');
        $run('2026-12-21');
        $run('2026-12-22');

        $sent = $reminders($this->preparer);
        $this->assertCount(1, $sent);
        $this->assertSame('Compliance requirement due in 30 days', $sent[0]->data['title']);
        $this->assertSame('"Mayor\'s permit renewal" for CY2026 is due on Jan 20, 2027.', $sent[0]->data['body']);
        $this->assertSame($pending->id, $sent[0]->data['data']['requirement_id']);
        $this->assertSame('/cda/calendar?year=2026', $sent[0]->data['url']);
        $this->assertCount(1, $reminders($approver));
        $this->assertCount(0, $reminders($this->viewer));
        $this->assertSame(1, CdaNoticeSent::query()->where('subject_type', 'cda_requirement')->count());

        $pending->update(['extended_due_on' => '2027-02-28']);
        $run('2027-01-29');
        $this->assertCount(2, $reminders($this->preparer), 'A new due date starts the milestones again.');
        $this->assertSame(['days_30|2027-01-20', 'days_30|2027-02-28'], CdaNoticeSent::query()->where('subject_type', 'cda_requirement')->orderBy('id')->pluck('dedupe_key')->all());
    }
}
