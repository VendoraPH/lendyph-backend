<?php

namespace Tests\Feature\Ledger\Release;

use App\Models\AccountingJournal;
use App\Models\AmortizationSchedule;
use App\Models\AuditLog;
use App\Services\LoanService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Releasing a restructure into a new loan N from a source S (Phase 2,
 * Accounting 2 and 3): J1 on N carries principal with no cash paid out, J2 on
 * S writes off the principal not carried, the split is stored on N, a source
 * paid down after approval is refused, and release-preview's `restructure`
 * block states exactly what the release posts.
 *
 * createReleasedLoan() leaves ₱60,000.00 principal and ₱10,800.00 interest
 * owed on six open periods.
 */
class RestructureReleaseJournalTest extends TestCase
{
    use PostsJournals, ReleasesLoans, SetupLendyPH;

    private const PAID_DOWN = 'The source loan was paid down or adjusted after this restructure was approved. Send it back for approval so the figures can be taken again.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_carrying_exactly_the_unpaid_principal_posts_j1_and_no_write_off(): void
    {
        $this->seedChartOfAccounts();
        $source = $this->createReleasedLoan();
        $new = $this->approvedRestructureOf($source, 60000, 'Interest forgiven');

        $this->patchJson("/api/loans/{$new->id}/release")->assertOk();

        $new->refresh();
        $release = $this->journalFor($new, 'loan_release');
        $deductions = $this->centavos($new->total_deductions);

        $this->assertSame(6_000_000, $this->sumOn($release, '1110', 'debit'));
        $this->assertSame(6_000_000, $this->sumOn($release, '1110', 'credit'), 'all the principal is carried');
        $this->assertSame(0, $this->sumOn($release, '1210', 'credit'));
        $this->assertSame($deductions, $this->sumOn($release, '1010', 'debit'), 'the deductions are collected from the member');
        $this->assertSame(0, $this->sumOn($release, '1010', 'credit'), 'nothing is paid out');
        $this->assertSame(6_000_000 + $deductions, $this->assertBalances($release));
        $this->assertNull($this->journalFor($source, 'credit_loss'), 'nothing written off, so no J2');

        $this->assertSame(['60000.00', '0.00', '0.00', '0.00'], $this->storedSplit($new));
        // Unchanged meaning: the approved shortfall, interest included.
        $this->assertSame('10800.00', $source->fresh()->write_off_amount);
    }

    public function test_a_shortfall_writes_the_principal_not_carried_off_on_the_source(): void
    {
        $this->seedChartOfAccounts();
        // ₱4,000.00 already provided for.
        $this->postSimpleJournal('5140', '1200', 400_000);
        $source = $this->createReleasedLoan();
        $new = $this->approvedRestructureOf($source, 50000, 'Board approved write-off');

        $this->patchJson("/api/loans/{$new->id}/release")->assertOk();

        $new->refresh();
        $release = $this->journalFor($new, 'loan_release');
        $this->assertSame(5_000_000, $this->sumOn($release, '1110', 'debit'));
        $this->assertSame(5_000_000, $this->sumOn($release, '1110', 'credit'));
        $this->assertSame(0, $this->sumOn($release, '1210', 'credit'));
        $this->assertBalances($release);

        $writeOff = $this->journalFor($source, 'credit_loss');
        $this->assertNotNull($writeOff, 'no J2 on the source');
        $this->assertSame('posted', $writeOff->status);
        $this->assertSame($source->branch_id, $writeOff->branch_id);
        $this->assertSame($release->date->toDateString(), $writeOff->date->toDateString());
        $this->assertSame(1_000_000, $this->sumOn($writeOff, '1110', 'credit'));
        $this->assertSame(400_000, $this->sumOn($writeOff, '1200', 'debit'), 'the allowance first');
        $this->assertSame(600_000, $this->sumOn($writeOff, '5140', 'debit'), 'then expense');
        $this->assertSame(1_000_000, $this->assertBalances($writeOff));

        $this->assertSame(['50000.00', '0.00', '0.00', '10000.00'], $this->storedSplit($new));
        $source->refresh();
        $this->assertSame('20800.00', $source->write_off_amount);
        $this->assertSame('70800.00', $source->restructured_balance);
    }

    public function test_principal_above_the_unpaid_principal_capitalises_interest_before_penalty(): void
    {
        $this->seedChartOfAccounts();

        // ₱500.00 penalty owed on period 1: ₱71,300.00 restructurable.
        foreach ([[71000, ['71000.00', '60000.00', '10800.00', '200.00', '0.00']], [65000, ['65000.00', '60000.00', '5000.00', '0.00', '0.00']]] as [$principal, $expected]) {
            $source = $this->createReleasedLoan();
            $source->amortizationSchedules()->orderBy('period_number')->first()->update(['penalty_amount' => 500]);
            $new = $this->approvedRestructureOf($source, $principal, $principal < 71300 ? 'Penalty forgiven' : null);

            $this->patchJson("/api/loans/{$new->id}/release")->assertOk();

            $release = $this->journalFor($new, 'loan_release');
            [$p, $carried, $interest, $penalty, $writtenOff] = $expected;
            $this->assertSame([$carried, $interest, $penalty, $writtenOff], $this->storedSplit($new), "principal {$p}");
            $this->assertSame($this->centavos($p), $this->sumOn($release, '1110', 'debit'));
            $this->assertSame($this->centavos($carried), $this->sumOn($release, '1110', 'credit'));
            $this->assertSame($this->centavos($interest) + $this->centavos($penalty), $this->sumOn($release, '1210', 'credit'));
            $this->assertBalances($release);
            $this->assertNull($this->journalFor($source, 'credit_loss'));
        }
    }

    public function test_a_restructure_of_a_restructure_posts_and_balances(): void
    {
        $this->seedChartOfAccounts();
        $original = $this->createReleasedLoan();
        // Capitalises the ₱10,800.00 interest into the first restructure.
        $first = $this->approvedRestructureOf($original, 70800);
        $this->patchJson("/api/loans/{$first->id}/release")->assertOk();
        $first->refresh();
        $this->assertSame(['60000.00', '10800.00', '0.00', '0.00'], $this->storedSplit($first));

        $second = $this->approvedRestructureOf($first, 50000, 'Second restructure, part written off');
        $this->patchJson("/api/loans/{$second->id}/release")->assertOk();

        // The first restructure's principal is ₱70,800.00, so ₱20,800.00 is
        // written off. How much of it comes off the first restructure's own
        // deferral is the engine's rule, not asserted here.
        $this->assertSame(['50000.00', '0.00', '0.00', '20800.00'], $this->storedSplit($second));
        $release = $this->journalFor($second->fresh(), 'loan_release');
        $this->assertSame(5_000_000, $this->sumOn($release, '1110', 'credit'));
        $this->assertBalances($release);

        $writeOff = $this->journalFor($first, 'credit_loss');
        $this->assertNotNull($writeOff);
        $this->assertSame(2_080_000, $this->sumOn($writeOff, '1110', 'credit'));
        $this->assertSame(2_080_000, $this->assertBalances($writeOff));
    }

    public function test_restructurable_parts_add_up_to_the_restructurable_balance(): void
    {
        $rows = [
            new AmortizationSchedule(['status' => 'pending', 'principal_due' => 1000, 'principal_paid' => 0, 'interest_due' => 180, 'interest_paid' => 0, 'penalty_amount' => 0, 'penalty_paid' => 0]),
            new AmortizationSchedule(['status' => 'partial', 'principal_due' => 1000.10, 'principal_paid' => 400.05, 'interest_due' => 180.33, 'interest_paid' => 180.33, 'penalty_amount' => 25.50, 'penalty_paid' => 10.25]),
            new AmortizationSchedule(['status' => 'overdue', 'principal_due' => 1000, 'principal_paid' => 0, 'interest_due' => 180, 'interest_paid' => 200, 'penalty_amount' => 40, 'penalty_paid' => null]),
            new AmortizationSchedule(['status' => 'paid', 'principal_due' => 1000, 'principal_paid' => 1000, 'interest_due' => 180, 'interest_paid' => 180, 'penalty_amount' => 99, 'penalty_paid' => 0]),
        ];

        $parts = LoanService::restructurableParts($rows);

        $this->assertSame(['principal' => 260_005, 'interest' => 18_000, 'penalty' => 5_525], $parts);
        $this->assertSame(LoanService::restructurableCentavos($rows), array_sum($parts));
        $this->assertSame(['principal' => 0, 'interest' => 0, 'penalty' => 0], LoanService::restructurableParts([]));
    }

    public function test_a_source_paid_down_after_approval_is_refused_and_nothing_changes(): void
    {
        $this->seedChartOfAccounts();
        $source = $this->createReleasedLoan();
        $new = $this->approvedRestructureOf($source, 70800);
        // Period 1 paid after approval: ₱59,000.00 is now owed, under the ₱70,800.00 principal.
        $this->payOn($source, 11800);
        $before = $this->releaseState();

        $this->patchJson("/api/loans/{$new->id}/release")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['principal_amount' => self::PAID_DOWN]);

        $this->assertSame($before, $this->releaseState());
        $this->getJson("/api/loans/{$new->id}/release-preview")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['principal_amount' => self::PAID_DOWN]);
    }

    public function test_any_fall_in_what_the_source_owes_since_approval_refuses_the_release(): void
    {
        $this->seedChartOfAccounts();
        $source = $this->createReleasedLoan();
        // Approved at ₱50,000.00 with ₱70,800.00 owed. Period 1 is paid after
        // approval, leaving ₱59,000.00: still above the principal, but the
        // forgiveness signed off no longer matches.
        $new = $this->approvedRestructureOf($source, 50000, 'Board approved write-off');
        $this->payOn($source, 11800);
        $before = $this->releaseState();

        $this->patchJson("/api/loans/{$new->id}/release")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['principal_amount' => self::PAID_DOWN]);

        $this->assertSame($before, $this->releaseState());
        $this->getJson("/api/loans/{$new->id}/release-preview")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['principal_amount' => self::PAID_DOWN]);
    }

    public function test_a_rise_from_penalty_since_approval_is_accepted_and_logged_as_drift(): void
    {
        $this->seedChartOfAccounts();
        $source = $this->createReleasedLoan();
        $new = $this->approvedRestructureOf($source, 50000, 'Board approved write-off');
        $source->amortizationSchedules()->orderBy('period_number')->first()->update(['penalty_amount' => 500]);

        $this->getJson("/api/loans/{$new->id}/release-preview")->assertOk();
        $this->patchJson("/api/loans/{$new->id}/release")->assertOk();

        $closed = AuditLog::where('action', 'restructure_closed')->where('auditable_id', $source->id)->firstOrFail();
        $this->assertEqualsWithDelta(500.0, (float) $closed->new_values['balance_drift_since_approval'], 0.001);
        $this->assertSame('20800.00', $source->fresh()->write_off_amount, 'the approved forgiveness is unchanged');
        $this->assertSame(['50000.00', '0.00', '0.00', '10000.00'], $this->storedSplit($new));
    }

    public function test_the_closing_audit_row_and_the_loans_label_the_forgiveness_and_the_books_write_off_apart(): void
    {
        $this->seedChartOfAccounts();
        $source = $this->createReleasedLoan();
        $new = $this->approvedRestructureOf($source, 50000, 'Board approved write-off');

        $this->patchJson("/api/loans/{$new->id}/release")->assertOk();

        $new->refresh();
        $closed = AuditLog::where('action', 'restructure_closed')->where('auditable_id', $source->id)->firstOrFail();
        $this->assertSame(50000.0, (float) $closed->new_values['restructure_carried_principal']);
        $this->assertSame(0.0, (float) $closed->new_values['restructure_capitalised_interest']);
        $this->assertSame(0.0, (float) $closed->new_values['restructure_capitalised_penalty']);
        $this->assertSame(10000.0, (float) $closed->new_values['restructure_written_off_principal']);
        $this->assertSame(20800.0, (float) $closed->new_values['write_off_amount']);
        $this->assertSame(
            "Loan closed as restructured — ₱70,800.00 moved to loan {$new->loan_account_number}, ₱20,800.00 forgiven (approved), "
            .'₱10,000.00 principal written off in the books (Board approved write-off)',
            $closed->description,
        );

        $this->getJson("/api/loans/{$new->id}")
            ->assertOk()
            ->assertJsonPath('data.restructure_written_off_principal', fn (mixed $v): bool => (float) $v === 10000.0);
        $this->getJson("/api/loans/{$source->id}")
            ->assertOk()
            ->assertJsonPath('data.write_off_amount', fn (mixed $v): bool => (float) $v === 20800.0)
            ->assertJsonPath('data.restructured_into.0.restructure_written_off_principal', fn (mixed $v): bool => (float) $v === 10000.0);
    }

    public function test_the_release_preview_refuses_every_restructure_the_release_refuses(): void
    {
        $this->seedChartOfAccounts();

        // The principal edited after sign-off.
        $edited = $this->approvedRestructureOf($this->createReleasedLoan(), 60000, 'Interest forgiven');
        DB::table('loans')->where('id', $edited->id)->update(['principal_amount' => 59000]);
        // No approved terms recorded.
        $unrecorded = $this->approvedRestructureOf($this->createReleasedLoan(), 60000, 'Interest forgiven');
        DB::table('loans')->where('id', $unrecorded->id)->update(['restructure_principal' => null]);
        // Approved while the premium owed still counted as balance.
        $premiumSource = $this->createReleasedLoan();
        $premium = $this->approvedRestructureOf($premiumSource, 70800);
        DB::table('loans')->where('id', $premiumSource->id)->update(['insurance_remaining_balance' => 500]);
        DB::table('loans')->where('id', $premium->id)->update(['restructure_outstanding' => 71300]);

        foreach ([[$edited, 'principal_amount'], [$unrecorded, 'source_loan_id'], [$premium, 'principal_amount']] as [$loan, $field]) {
            $release = $this->patchJson("/api/loans/{$loan->id}/release")->assertUnprocessable()->json("errors.{$field}.0");
            $preview = $this->getJson("/api/loans/{$loan->id}/release-preview")->assertUnprocessable()->json("errors.{$field}.0");

            $this->assertNotNull($release);
            $this->assertSame($release, $preview, "loan {$loan->id}");
        }
    }

    public function test_the_release_preview_restructure_block_is_what_the_release_posts(): void
    {
        $this->seedChartOfAccounts();
        $this->postSimpleJournal('5140', '1200', 400_000);
        $insurance = ['insurance_premium_percentage' => 1, 'insurance_payment_type' => 'full'];

        foreach ([[50000, 'Board approved write-off'], [70800, null]] as [$principal, $remarks]) {
            $source = $this->createReleasedLoan();
            $new = $this->approvedRestructureOf($source, $principal, $remarks);

            $block = $this->getJson("/api/loans/{$new->id}/release-preview?".http_build_query($insurance))
                ->assertOk()
                ->json('data.restructure');

            $this->assertSame(['carried_principal', 'capitalised_interest', 'capitalised_penalty', 'written_off_principal', 'collected_now', 'paid_out'], array_keys($block));
            $this->assertSame('0.00', $block['paid_out']);

            $this->patchJson("/api/loans/{$new->id}/release", $insurance)->assertOk();

            $new->refresh();
            $release = $this->journalFor($new, 'loan_release');
            $writeOff = $this->journalFor($source, 'credit_loss');

            $this->assertSame([
                $block['carried_principal'],
                $block['capitalised_interest'],
                $block['capitalised_penalty'],
                $block['written_off_principal'],
            ], $this->storedSplit($new), "principal {$principal}");
            $this->assertSame($this->centavos($block['carried_principal']), $this->sumOn($release, '1110', 'credit'));
            $this->assertSame($this->centavos($block['capitalised_interest']) + $this->centavos($block['capitalised_penalty']), $this->sumOn($release, '1210', 'credit'));
            $this->assertSame($this->centavos($block['collected_now']), $this->sumOn($release, '1010', 'debit'));
            $this->assertSame($this->centavos($new->total_deductions), $this->centavos($block['collected_now']), 'collected now is every deduction, insurance included');
            $this->assertSame($this->centavos($block['paid_out']), $this->sumOn($release, '1010', 'credit'));
            $this->assertSame($this->centavos($block['written_off_principal']), $writeOff === null ? 0 : $this->sumOn($writeOff, '1110', 'credit'));
        }
    }

    public function test_an_ordinary_loans_release_preview_has_no_restructure_block(): void
    {
        $loan = $this->approvedLoan();

        $this->getJson("/api/loans/{$loan->id}/release-preview")
            ->assertOk()
            ->assertJsonMissingPath('data.restructure');
    }

    public function test_without_a_chart_a_restructure_still_releases_and_posts_nothing(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->approvedRestructureOf($source, 50000, 'Board approved write-off');

        $this->patchJson("/api/loans/{$new->id}/release")->assertOk();

        $this->assertSame('released', $new->fresh()->status);
        $this->assertSame('restructured', $source->fresh()->status);
        $this->assertSame(['50000.00', '0.00', '0.00', '10000.00'], $this->storedSplit($new));
        $this->assertSame(0, AccountingJournal::count());
    }

    public function test_a_restructure_released_through_gcash_collects_its_deductions_there(): void
    {
        $this->seedChartOfAccounts();
        $source = $this->createReleasedLoan();
        $new = $this->approvedRestructureOf($source, 60000, 'Interest forgiven');

        $this->patchJson("/api/loans/{$new->id}/release", ['method' => 'gcash', 'reference_number' => 'GC-0001'])->assertOk();

        $new->refresh();
        $release = $this->journalFor($new, 'loan_release');
        $this->assertSame($this->centavos($new->total_deductions), $this->sumOn($release, '1020', 'debit'));
        $this->assertSame(0, $this->sumOn($release, '1010', 'debit'));
        $this->assertSame('gcash', $new->release_method);
        $this->assertSame('GC-0001', $new->release_reference);
    }
}
