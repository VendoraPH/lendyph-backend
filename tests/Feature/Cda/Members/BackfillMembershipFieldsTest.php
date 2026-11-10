<?php

namespace Tests\Feature\Cda\Members;

use App\Models\AuditLog;
use App\Models\Borrower;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * `members:backfill-membership-fields` writes only what a replay proves (C1
 * review, H3):
 * - `membership_date` only from a real approval in the audit trail (a staff
 *   user moving the member out of `pending` or `rejected`), or from the
 *   audit row of a staff user creating them directly as a member: that
 *   row's date;
 * - never from an `approved_at` that `registrations:backfill-approvals`
 *   stamped (no approver, or the creation time) or the CSV importer stamped
 *   (the extract's date), nor for a member with no approval at all: those
 *   are skipped and listed with the reason;
 * - `membership_number` from the member code for every member, since that
 *   is exact, unless another member holds it.
 * The dry-run writes nothing and shows filled and skipped; one system audit
 * row per member changed; a second run changes nothing.
 */
class BackfillMembershipFieldsTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /** @return array<string, Borrower> */
    private function members(): array
    {
        $create = fn (array $attributes = []) => Borrower::factory()->create(['branch_id' => $this->branch->id, ...$attributes]);

        $viaEndpoint = $create(['status' => 'pending', 'approved_at' => null]);
        $viaEndpoint->documents()->create(['type' => 'valid_id', 'label' => 'umid', 'file_path' => 'documents/valid_id/t.jpg', 'original_filename' => 't.jpg']);
        $this->travelTo('2024-03-04 23:30:00');
        $this->patchJson("/api/borrowers/{$viaEndpoint->id}/approve-registration")->assertOk();
        $this->travelBack();

        $viaReactivate = $create(['status' => 'pending', 'approved_at' => null]);
        DB::table('audit_logs')->insert([
            'user_id' => $this->admin->id, 'action' => 'updated', 'auditable_type' => Borrower::class, 'auditable_id' => $viaReactivate->id,
            'old_values' => json_encode(['status' => 'pending']), 'new_values' => json_encode(['status' => 'active']), 'created_at' => '2023-05-06 09:00:00',
        ]);
        DB::table('borrowers')->where('id', $viaReactivate->id)->update(['status' => 'active', 'approved_at' => '2023-05-06 09:00:00', 'approved_by' => $this->admin->id]);

        // Created as a member by a staff user: the creation row proves the date.
        $this->travelTo('2021-09-10 14:00:00');
        $staffCreated = $create(['approved_at' => null]);
        $this->travelBack();

        $imported = $create(['external_account_no' => 'ACC-0042', 'approved_at' => '2025-12-31 00:00:00', 'approved_by' => $this->admin->id]);
        $applicant = $create(['status' => 'pending', 'approved_at' => null]);
        $dated = $create(['approved_at' => '2022-01-01 08:00:00']);

        // Created with no user on record (a console or seeder write), so
        // nothing proves when they joined.
        $this->app['auth']->forgetGuards();
        $stamped = $create(['approved_at' => null]);
        DB::table('borrowers')->where('id', $stamped->id)->update(['approved_at' => $stamped->created_at, 'approved_by' => null]);
        $noApproval = $create(['approved_at' => null]);

        DB::table('borrowers')->whereIn('id', [$viaEndpoint->id, $viaReactivate->id, $staffCreated->id, $stamped->id, $imported->id, $noApproval->id, $applicant->id])
            ->update(['membership_date' => null, 'membership_number' => null]);
        DB::table('borrowers')->where('id', $dated->id)->update(['membership_date' => '2020-01-15', 'membership_number' => 'OLD-0001']);

        return compact('viaEndpoint', 'viaReactivate', 'staffCreated', 'stamped', 'imported', 'noApproval', 'applicant', 'dated');
    }

    public function test_the_dry_run_writes_nothing_and_shows_filled_and_skipped_with_reasons(): void
    {
        $m = $this->members();
        $audits = AuditLog::query()->count();

        $this->artisan('members:backfill-membership-fields', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain("{$m['viaEndpoint']->borrower_code}: would set membership date 2024-03-04")
            ->expectsOutputToContain("{$m['staffCreated']->borrower_code}: would set membership date 2021-09-10")
            ->expectsOutputToContain("{$m['stamped']->borrower_code}: membership date skipped: the approval date was stamped by registrations:backfill-approvals")
            ->expectsOutputToContain("{$m['imported']->borrower_code}: membership date skipped: imported from a CSV migration")
            ->expectsOutputToContain("{$m['noApproval']->borrower_code}: membership date skipped: no approval on record")
            ->assertSuccessful();

        $this->assertNull($m['viaEndpoint']->fresh()->membership_date);
        $this->assertNull($m['viaEndpoint']->fresh()->membership_number);
        $this->assertSame($audits, AuditLog::query()->count());
    }

    public function test_dates_come_only_from_real_approvals_and_numbers_from_the_member_code(): void
    {
        $m = $this->members();
        $before = AuditLog::query()->max('id') ?? 0;

        $this->artisan('members:backfill-membership-fields')->assertSuccessful();

        $this->assertSame('2024-03-04', $m['viaEndpoint']->fresh()->membership_date->toDateString());
        $this->assertSame('2023-05-06', $m['viaReactivate']->fresh()->membership_date->toDateString());
        $this->assertSame('2021-09-10', $m['staffCreated']->fresh()->membership_date->toDateString());

        foreach (['stamped', 'imported', 'noApproval'] as $skipped) {
            $this->assertNull($m[$skipped]->fresh()->membership_date, $skipped);
            $this->assertSame($m[$skipped]->borrower_code, $m[$skipped]->fresh()->membership_number, $skipped);
        }

        $this->assertNull($m['applicant']->fresh()->membership_number);
        $this->assertSame('2020-01-15', $m['dated']->fresh()->membership_date->toDateString());
        $this->assertSame('OLD-0001', $m['dated']->fresh()->membership_number);

        $rows = AuditLog::query()->where('id', '>', $before)->get();
        $this->assertEqualsCanonicalizing(
            [$m['viaEndpoint']->id, $m['viaReactivate']->id, $m['staffCreated']->id, $m['stamped']->id, $m['imported']->id, $m['noApproval']->id],
            $rows->pluck('auditable_id')->all(),
        );
        $this->assertSame([null], $rows->pluck('user_id')->unique()->values()->all());
        $endpointRow = $rows->firstWhere('auditable_id', $m['viaEndpoint']->id);
        $this->assertSame(['membership_date' => '2024-03-04', 'membership_number' => $m['viaEndpoint']->borrower_code], $endpointRow->new_values);
        $this->assertStringContainsString('approved by Super Admin', (string) $endpointRow->description);
        $this->assertSame(['membership_number' => $m['stamped']->borrower_code], $rows->firstWhere('auditable_id', $m['stamped']->id)->new_values);
        $this->assertStringContainsString('created as a member by Super Admin', (string) $rows->firstWhere('auditable_id', $m['staffCreated']->id)->description);
        $this->assertSame(['membership_number' => $m['imported']->borrower_code], $rows->firstWhere('auditable_id', $m['imported']->id)->new_values, 'An import keeps no date, even though staff ran it.');
    }

    public function test_a_second_run_changes_nothing_and_still_lists_the_skipped(): void
    {
        $m = $this->members();
        $this->artisan('members:backfill-membership-fields')->assertSuccessful();
        $audits = AuditLog::query()->count();
        $snapshot = DB::table('borrowers')->orderBy('id')->get(['id', 'membership_date', 'membership_number', 'updated_at'])->toArray();

        $this->artisan('members:backfill-membership-fields')
            ->expectsOutputToContain('0 members updated')
            ->expectsOutputToContain($m['noApproval']->borrower_code)
            ->assertSuccessful();

        $this->assertSame($audits, AuditLog::query()->count());
        $this->assertEquals($snapshot, DB::table('borrowers')->orderBy('id')->get(['id', 'membership_date', 'membership_number', 'updated_at'])->toArray());
    }

    public function test_a_membership_number_already_taken_is_skipped_and_listed(): void
    {
        $m = $this->members();
        DB::table('borrowers')->where('id', $m['dated']->id)->update(['membership_number' => $m['noApproval']->borrower_code]);

        $this->artisan('members:backfill-membership-fields')
            ->expectsOutputToContain('already used')
            ->assertSuccessful();

        $this->assertNull($m['noApproval']->fresh()->membership_number);
    }
}
