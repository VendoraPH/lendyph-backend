<?php

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `registrations:prune` excludes borrowers with `whereNull('approved_at')`, but
 * approvals have always gone through `/reactivate`, which stamps nothing — so
 * on binhs-coop production all 43 borrowers carry `approved_at = NULL` and that
 * guard protects nobody. This command arms it for the rows that already exist;
 * the frontend switching Approve to `/approve-registration` only covers
 * approvals made from now on.
 */
uses(TestCase::class);

beforeEach(function () {
    Artisan::call('migrate:fresh');
    $this->seed(DatabaseSeeder::class);
    $this->branch = Branch::first();
    $this->admin = User::where('username', 'super_admin')->first();
    $this->actingAs($this->admin);
});

/**
 * A borrower approved the way every existing member was: `/reactivate`, which
 * flips `status` and records nothing on the borrower row itself.
 */
function borrowerApprovedViaReactivate(int $branchId, User $admin): Borrower
{
    $applicant = Borrower::factory()->create([
        'branch_id' => $branchId,
        'status' => 'pending',
    ]);

    test()->actingAs($admin)
        ->patchJson("/api/borrowers/{$applicant->id}/reactivate")
        ->assertSuccessful();

    return $applicant->fresh();
}

it('stamps approved_at and approved_by from the audit trail', function () {
    $borrower = borrowerApprovedViaReactivate($this->branch->id, $this->admin);

    expect($borrower->approved_at)->toBeNull('the historical approval path stamps nothing');

    $this->artisan('registrations:backfill-approvals')->assertSuccessful();

    $approvalRow = DB::table('audit_logs')
        ->where('auditable_type', Borrower::class)
        ->where('auditable_id', $borrower->id)
        ->where('action', 'updated')
        ->orderBy('id')
        ->first();

    $fresh = $borrower->fresh();

    expect($fresh->approved_at?->toDateTimeString())->toBe((string) $approvalRow->created_at)
        ->and($fresh->approved_by)->toBe($this->admin->id);
});

it('falls back to the creation date when nothing names an approval', function () {
    // Operator-created members were never "approved" by anyone — they were
    // members from the moment the row existed.
    $member = Borrower::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => 'active',
    ]);

    $this->artisan('registrations:backfill-approvals')->assertSuccessful();

    $fresh = $member->fresh();

    expect($fresh->approved_at?->toDateTimeString())->toBe($member->created_at->toDateTimeString())
        // Deliberately null: inventing an approver would put a false name on an
        // accountability record.
        ->and($fresh->approved_by)->toBeNull();
});

it('stamps inactive and blacklisted members too', function (string $status) {
    // They are members in poor standing, not non-members, and the prune guard
    // has to cover them just the same.
    $member = Borrower::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => $status,
    ]);

    $this->artisan('registrations:backfill-approvals')->assertSuccessful();

    expect($member->fresh()->approved_at)->not->toBeNull();
})->with(['inactive', 'blacklisted']);

it('leaves pending and rejected registrations alone', function (string $status) {
    $applicant = Borrower::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => $status,
    ]);

    $this->artisan('registrations:backfill-approvals')->assertSuccessful();

    expect($applicant->fresh()->approved_at)->toBeNull();
})->with(Borrower::NON_MEMBER_STATUSES);

it('is safe to re-run', function () {
    $member = Borrower::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => 'active',
    ]);

    $this->artisan('registrations:backfill-approvals')->assertSuccessful();
    $stamped = $member->fresh()->approved_at->toDateTimeString();

    $this->artisan('registrations:backfill-approvals')
        ->expectsOutputToContain('Every member already has an approved_at timestamp.')
        ->assertSuccessful();

    expect($member->fresh()->approved_at->toDateTimeString())->toBe($stamped);
});

it('never overwrites an approved_at that is already recorded', function () {
    $member = Borrower::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => 'active',
        'approved_at' => '2026-01-02 03:04:05',
    ]);

    $this->artisan('registrations:backfill-approvals')->assertSuccessful();

    expect($member->fresh()->approved_at->toDateTimeString())->toBe('2026-01-02 03:04:05');
});

it('writes nothing on a dry run', function () {
    $viaAudit = borrowerApprovedViaReactivate($this->branch->id, $this->admin);
    $viaCreation = Borrower::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => 'active',
    ]);

    $this->artisan('registrations:backfill-approvals', ['--dry-run' => true])
        ->expectsOutputToContain('Would stamp')
        ->assertSuccessful();

    expect($viaAudit->fresh()->approved_at)->toBeNull()
        ->and($viaCreation->fresh()->approved_at)->toBeNull();
});

it('reports how each timestamp was resolved', function () {
    borrowerApprovedViaReactivate($this->branch->id, $this->admin);
    Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
    Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);

    Artisan::call('registrations:backfill-approvals');

    expect(Artisan::output())
        ->toContain('Stamped approved_at on 3 member(s)')
        ->toContain('1 dated from the audit trail, 2 from their creation date')
        ->toContain('approved_by resolved for 1 of them');
});

it('does not disturb the abandonment clock the prune reads', function () {
    // `registrations:prune` treats `updated_at` as "last applicant activity".
    // A historical correction that bumped it would reset that clock on every
    // row this touches.
    $member = Borrower::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => 'active',
    ]);

    DB::table('borrowers')
        ->where('id', $member->id)
        ->update(['updated_at' => '2026-02-03 04:05:06']);

    $this->artisan('registrations:backfill-approvals')->assertSuccessful();

    expect($member->fresh()->updated_at->toDateTimeString())->toBe('2026-02-03 04:05:06');
});

it('writes no audit rows carrying borrower personal data', function () {
    // Borrower is Auditable: saving through Eloquent would copy the full
    // original attributes — name, birthdate, address, contact number, income —
    // into audit_logs.old_values, permanently.
    $member = Borrower::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => 'active',
    ]);

    $before = DB::table('audit_logs')->where('auditable_id', $member->id)->count();

    $this->artisan('registrations:backfill-approvals')->assertSuccessful();

    expect(DB::table('audit_logs')->where('auditable_id', $member->id)->count())->toBe($before);
});
