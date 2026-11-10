<?php

namespace Tests\Feature\Cda\Members;

use App\Models\AuditLog;
use App\Models\Borrower;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * `members:backfill-membership-fields` (CDA design §1): for members only,
 * where empty, the membership date becomes the Manila date of `approved_at`
 * and the membership number the member code. The dry-run writes nothing;
 * each member changed gets one system audit row; a second run changes
 * nothing; members with no approval date keep no date and are listed.
 */
class BackfillMembershipFieldsTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Auth::logout();
    }

    /** @return array{0: Borrower, 1: Borrower, 2: Borrower, 3: Borrower, 4: Borrower} */
    private function members(): array
    {
        $plain = Borrower::factory()->create(['branch_id' => $this->branch->id, 'approved_at' => '2024-03-04 23:30:00']);
        $dated = Borrower::factory()->create(['branch_id' => $this->branch->id, 'approved_at' => '2024-05-06 09:00:00']);
        DB::table('borrowers')->where('id', $dated->id)->update(['membership_date' => '2020-01-15', 'membership_number' => 'OLD-0001']);
        $noApproval = Borrower::factory()->create(['branch_id' => $this->branch->id, 'approved_at' => null]);
        $applicant = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'pending', 'approved_at' => null]);
        $withdrawn = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'withdrawn', 'approved_at' => '2023-07-08 10:00:00']);

        DB::table('borrowers')->whereIn('id', [$plain->id, $noApproval->id, $applicant->id, $withdrawn->id])
            ->update(['membership_date' => null, 'membership_number' => null]);

        return [$plain, $dated, $noApproval, $applicant, $withdrawn];
    }

    public function test_the_dry_run_writes_nothing_and_lists_what_it_would_do(): void
    {
        [$plain, , $noApproval] = $this->members();
        $audits = AuditLog::query()->count();

        $this->artisan('members:backfill-membership-fields', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain($plain->borrower_code)
            ->expectsOutputToContain($noApproval->borrower_code)
            ->assertSuccessful();

        $this->assertNull($plain->fresh()->membership_date);
        $this->assertNull($plain->fresh()->membership_number);
        $this->assertSame($audits, AuditLog::query()->count());
    }

    public function test_only_empty_fields_of_members_are_filled_with_one_system_audit_row_each(): void
    {
        [$plain, $dated, $noApproval, $applicant, $withdrawn] = $this->members();
        $before = AuditLog::query()->max('id') ?? 0;

        $this->artisan('members:backfill-membership-fields')->assertSuccessful();

        $this->assertSame('2024-03-04', $plain->fresh()->membership_date->toDateString());
        $this->assertSame($plain->borrower_code, $plain->fresh()->membership_number);
        $this->assertSame('2020-01-15', $dated->fresh()->membership_date->toDateString());
        $this->assertSame('OLD-0001', $dated->fresh()->membership_number);
        $this->assertNull($noApproval->fresh()->membership_date);
        $this->assertSame($noApproval->borrower_code, $noApproval->fresh()->membership_number);
        $this->assertNull($applicant->fresh()->membership_date);
        $this->assertNull($applicant->fresh()->membership_number);
        $this->assertSame('2023-07-08', $withdrawn->fresh()->membership_date->toDateString());

        $rows = AuditLog::query()->where('id', '>', $before)->get();
        $this->assertEqualsCanonicalizing([$plain->id, $noApproval->id, $withdrawn->id], $rows->pluck('auditable_id')->all());
        $this->assertSame([Borrower::class], $rows->pluck('auditable_type')->unique()->values()->all());
        $this->assertSame([null], $rows->pluck('user_id')->unique()->values()->all());
        $plainRow = $rows->firstWhere('auditable_id', $plain->id);
        $this->assertSame(['membership_date' => null, 'membership_number' => null], $plainRow->old_values);
        $this->assertSame(['membership_date' => '2024-03-04', 'membership_number' => $plain->borrower_code], $plainRow->new_values);
        $this->assertStringContainsString('members:backfill-membership-fields', (string) $plainRow->description);
    }

    public function test_a_second_run_changes_nothing_and_still_lists_members_with_no_approval_date(): void
    {
        [, , $noApproval] = $this->members();
        $this->artisan('members:backfill-membership-fields')->assertSuccessful();
        $audits = AuditLog::query()->count();
        $snapshot = DB::table('borrowers')->orderBy('id')->get(['id', 'membership_date', 'membership_number', 'updated_at'])->toArray();

        $this->artisan('members:backfill-membership-fields')
            ->expectsOutputToContain('0 members updated')
            ->expectsOutputToContain($noApproval->borrower_code)
            ->assertSuccessful();

        $this->assertSame($audits, AuditLog::query()->count());
        $this->assertEquals($snapshot, DB::table('borrowers')->orderBy('id')->get(['id', 'membership_date', 'membership_number', 'updated_at'])->toArray());
    }

    public function test_a_membership_number_already_taken_is_skipped_and_listed(): void
    {
        [$plain] = $this->members();
        $other = Borrower::factory()->create(['branch_id' => $this->branch->id, 'approved_at' => '2024-01-01 08:00:00']);
        DB::table('borrowers')->where('id', $other->id)->update(['membership_number' => $plain->borrower_code]);

        $this->artisan('members:backfill-membership-fields')
            ->expectsOutputToContain('already used')
            ->assertSuccessful();

        $this->assertNull($plain->fresh()->membership_number);
        $this->assertSame('2024-03-04', $plain->fresh()->membership_date->toDateString());
    }
}
