<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The audit trail must be stamped by PHP, in the application timezone.
 *
 * `audit_logs.created_at` is declared `->useCurrent()`, so if the model does not
 * supply the value the database fills it with `CURRENT_TIMESTAMP`. The database
 * server runs in UTC in production while the app runs in Asia/Manila, so that
 * default wrote every audit entry eight hours behind the event it describes —
 * including `restructure_closed`, the justification record for written-off debt.
 *
 * These tests pin the value to PHP's clock without assuming anything about how
 * the database server happens to be configured.
 */
class AuditLogTimestampTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /**
     * The decisive test: PHP's clock is moved somewhere the database clock
     * cannot possibly be, and the stored value has to follow PHP.
     *
     * This holds whatever timezone the database server runs in, which is the
     * point — we do not control it, and it is currently UTC.
     */
    public function test_audit_entry_is_stamped_from_the_php_clock_not_the_database_clock(): void
    {
        $appTimezone = config('app.timezone');

        $frozen = Carbon::create(2026, 1, 15, 8, 30, 0, $appTimezone);
        $this->travelTo($frozen);

        $log = AuditLogService::log(
            action: 'restructure_closed',
            auditable: $this->admin,
            description: 'Frozen-clock probe.',
        );

        // Read the column raw, so Eloquent's cast cannot paper over what landed.
        $stored = DB::table('audit_logs')->where('id', $log->id)->value('created_at');

        $this->assertSame(
            '2026-01-15 08:30:00',
            $stored,
            'created_at must be the wall clock PHP reported, not whatever the database clock said.'
        );

        // The database clock is real time, months away from the frozen instant.
        $databaseNow = DB::selectOne('select current_timestamp as now')->now;
        $this->assertNotSame(
            Carbon::parse($databaseNow)->format('Y-m-d H:i:s'),
            $stored,
            'The stored value must not track the database clock.'
        );
    }

    /**
     * The same instant expressed in UTC is a different wall clock, and it is
     * specifically the wrong one. This is the eight-hour skew, pinned.
     */
    public function test_audit_entry_is_not_stamped_in_utc(): void
    {
        $appTimezone = config('app.timezone');
        $frozen = Carbon::create(2026, 1, 15, 8, 30, 0, $appTimezone);

        $this->assertNotSame(
            0,
            $frozen->utcOffset(),
            "This test is meaningless if the app timezone ({$appTimezone}) is UTC."
        );

        $this->travelTo($frozen);

        $log = AuditLogService::log('login', $this->admin, description: 'UTC probe.');

        $stored = DB::table('audit_logs')->where('id', $log->id)->value('created_at');

        $this->assertNotSame(
            $frozen->copy()->utc()->format('Y-m-d H:i:s'),
            $stored,
            'created_at was written as a UTC wall clock; it must be the app timezone.'
        );

        $this->assertSame(
            $frozen->format('Y-m-d H:i:s'),
            $stored,
        );
    }

    /**
     * Unfrozen, against the real clock: the entry must land within seconds of
     * PHP's now(), not eight hours away from it.
     */
    public function test_audit_entry_created_at_tracks_real_php_now(): void
    {
        $before = now();
        $log = AuditLogService::log('login', $this->admin, description: 'Live-clock probe.');
        $after = now();

        $stored = Carbon::createFromFormat(
            'Y-m-d H:i:s',
            DB::table('audit_logs')->where('id', $log->id)->value('created_at'),
            config('app.timezone'),
        );

        $this->assertTrue(
            $stored->betweenIncluded($before->subSecond(), $after->addSecond()),
            "Audit entry stamped {$stored} but PHP's clock read {$before} — that gap is a timezone skew."
        );

        // And the hydrated model agrees, in the app timezone.
        $this->assertSame(config('app.timezone'), $log->created_at->timezone->getName());
        $this->assertLessThanOrEqual(5, abs($log->created_at->diffInSeconds($before)));
    }

    /**
     * The case that actually blocks the merge.
     *
     * `restructure_closed` and `loans.restructured_at` are written for the same
     * event inside the same transaction. `restructured_at` is stamped by Eloquent
     * from PHP; the audit row must agree with it. When the database stamped the
     * audit row the two were eight hours apart, which is exactly the discrepancy
     * that makes a write-off impossible to justify after the fact.
     */
    public function test_restructure_closed_audit_entry_agrees_with_the_loan_it_closed(): void
    {
        $source = $this->createReleasedLoan();

        $created = $this->postJson("/api/loans/{$source->id}/restructure", [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => 70000,
            'start_date' => now()->toDateString(),
            // Deliberately short of the payoff, so the closure writes debt off
            // and `restructure_closed` is carrying a real justification.
            'remarks' => 'Borrower rehabilitation; shortfall written off per committee.',
        ])->assertCreated();

        $new = Loan::findOrFail($created->json('data.id'));
        $this->releaseLoan($new);

        $source->refresh();
        $this->assertSame('restructured', $source->status);

        $entry = AuditLog::where('action', 'restructure_closed')
            ->where('auditable_id', $source->id)
            ->sole();

        $this->assertLessThanOrEqual(
            5,
            abs($entry->created_at->diffInSeconds($source->restructured_at)),
            'The write-off justification record and the loan it closed must carry the same instant.'
        );

        $this->assertSame(
            $source->restructured_at->toDateString(),
            $entry->created_at->toDateString(),
            'The audit entry must fall on the same calendar day as the closure it justifies.'
        );

        $this->assertSame(Carbon::today()->toDateString(), $entry->created_at->toDateString());
    }

    /**
     * Drive a fresh application all the way to released through the same
     * endpoints the frontend uses. Approval is done by a second user because
     * maker-checker forbids approving your own application.
     */
    private function releaseLoan(Loan $loan): void
    {
        $approver = tap(
            User::factory()->create(),
            fn (User $user) => $user->assignRole(Role::where('name', 'admin')->firstOrFail()),
        );

        $this->patchJson("/api/loans/{$loan->id}/submit")->assertOk();

        $this->actingAs($approver);
        $this->patchJson("/api/loans/{$loan->id}/approve", ['approval_remarks' => 'ok'])->assertOk();

        $this->actingAs($this->admin);
        $this->patchJson("/api/loans/{$loan->id}/release")->assertOk();
    }
}
