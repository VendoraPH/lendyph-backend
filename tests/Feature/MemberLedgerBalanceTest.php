<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\Repayment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\BrowserMoneyRound\Concerns\BuildsReleasedLoans;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Round fy, member ledger balance (GET /borrowers/{id}/ledger).
 *
 * The member ledger is built from each loan's own Ledger
 * (GET /loans/{id}/ledger, LoanLedgerService): every ever-released loan
 * (Loan::EVER_RELEASED_STATUSES) the caller may see, in date order. Each row
 * moves the member balance by what its loan Ledger row moved that loan's
 * principal, interest and penalty balances, so the balance after any row is
 * the loans' own balances added up, and the closing balance is the sum of the
 * loans' Ledger closings. Each row names its loan (`loan_id`,
 * `loan_account_number`). A voided payment and its void are left out.
 */
class MemberLedgerBalanceTest extends TestCase
{
    use BuildsReleasedLoans, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_the_closing_balance_is_the_sum_of_each_loans_ledger_closing(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $first = $this->releasedLoanFor(borrower: $member);
        $second = $this->releasedLoanFor(borrower: $member, principal: 40000);
        $this->payLoan($first, 11800);
        $this->payLoan($second, 5000.37);

        $rows = $this->memberLedger($member);

        $this->assertNotEmpty($rows);
        $this->assertMoney($this->closingOf($first) + $this->closingOf($second), end($rows)['balance'], 'closing = sum of the loans\' Ledger closings');
        $this->assertBalanceWalks($rows);
    }

    public function test_a_defaulted_loan_and_a_restructured_loan_appear_with_their_history(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $defaulted = $this->releasedLoanFor(borrower: $member);
        $this->payLoan($defaulted, 11800);
        DB::table('loans')->where('id', $defaulted->id)->update(['status' => 'defaulted']);

        $source = $this->releasedLoanFor(borrower: $member, principal: 30000);
        $this->payLoan($source, 5900);
        $successor = $this->restructure($source);

        $rows = $this->memberLedger($member);
        $references = array_column($rows, 'reference');

        $this->assertContains($defaulted->loan_account_number, $references, 'the defaulted loan\'s release appears');
        $this->assertContains($source->loan_account_number, $references, 'the restructured loan\'s release appears');
        $this->assertContains($successor->loan_account_number, $references, 'the successor loan\'s release appears');
        $this->assertContains('Balance moved to a restructured loan', array_column($rows, 'description'), 'the restructured loan\'s move row appears');

        $this->assertMoney(0, $this->closingOf($source), 'setup: the restructured loan closes at zero');
        $this->assertMoney(
            $this->closingOf($defaulted) + $this->closingOf($successor),
            end($rows)['balance'],
            'closing = sum of the loans\' Ledger closings',
        );
        $this->assertBalanceWalks($rows);

        foreach ([$defaulted, $source, $successor] as $loan) {
            $this->assertCount(
                count($this->loanLedger($loan)['rows']),
                array_filter($rows, fn (array $row): bool => ($row['loan_id'] ?? null) === $loan->id),
                "every Ledger row of loan {$loan->loan_account_number} appears once",
            );
        }
    }

    public function test_a_voided_payment_and_its_void_do_not_appear(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $loan = $this->releasedLoanFor(borrower: $member);
        $kept = $this->payLoan($loan, 11800);
        $voided = $this->payLoan($loan, 11800);
        $this->patchJson("/api/repayments/{$voided->id}/void", ['void_reason' => 'Keyed in error'])->assertOk();

        $rows = $this->memberLedger($member);

        $this->assertNotContains($voided->receipt_number, array_column($rows, 'reference'), 'the voided payment is left out');
        foreach ($rows as $row) {
            $this->assertStringNotContainsString($voided->receipt_number, (string) $row['description'], 'neither the voided payment nor its void appears');
        }
        $this->assertContains($kept->receipt_number, array_column($rows, 'reference'), 'the posted payment stays');

        $this->assertCount(count($this->loanLedger($loan)['rows']) - 2, $rows, 'the loan\'s Ledger rows less the voided payment and its void');
        $this->assertMoney($this->closingOf($loan), end($rows)['balance'], 'closing = the loan\'s Ledger closing');
        $this->assertBalanceWalks($rows);
    }

    public function test_each_row_moves_the_balance_by_its_loan_ledger_row_and_the_balance_adds_up_the_loans(): void
    {
        // Loan A released 2026-01-10: period 1 falls due 2026-02-10 and, with
        // 3 days' grace, is charged a late penalty on 2026-02-14. Loan B
        // released 2026-02-01. Both paid on 2026-02-20.
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $this->travelTo(Carbon::parse('2026-01-10 09:00'));
        $loanA = $this->releasedLoanFor(borrower: $member, principal: 61234.57, startDate: '2026-01-10');
        $this->travelTo(Carbon::parse('2026-02-01 09:00'));
        $loanB = $this->releasedLoanFor(borrower: $member, principal: 30000, startDate: '2026-02-01');
        $this->travelTo(Carbon::parse('2026-02-20 09:00'));
        $this->payLoan($loanA, 5000.37);
        $this->payLoan($loanB, 3000);

        $loans = [$loanA->id => $loanA, $loanB->id => $loanB];
        $ledgers = [$loanA->id => $this->loanLedger($loanA)['rows'], $loanB->id => $this->loanLedger($loanB)['rows']];
        $this->assertContains('penalty_charge', array_column($ledgers[$loanA->id], 'kind'), 'setup: loan A carries a late penalty');

        $rows = $this->memberLedger($member);

        $this->assertCount(count($ledgers[$loanA->id]) + count($ledgers[$loanB->id]), $rows, 'every loan Ledger row appears once');
        $this->assertSame(
            ['2026-01-10', '2026-02-01', '2026-02-14', '2026-02-20', '2026-02-20'],
            array_column($rows, 'date'),
            'date order across the loans',
        );

        $next = array_fill_keys(array_keys($loans), 0);
        $latest = array_fill_keys(array_keys($loans), 0.0);

        foreach ($rows as $index => $row) {
            $loanId = $row['loan_id'] ?? null;
            $this->assertArrayHasKey((int) $loanId, $loans, "row {$index} names its loan");
            $this->assertSame($loans[$loanId]->loan_account_number, $row['loan_account_number'] ?? null, "row {$index} carries its loan's account number");

            $loanRow = $ledgers[$loanId][$next[$loanId]++];
            $after = $this->balanceOf($loanRow);
            $moved = $after - $latest[$loanId];
            $latest[$loanId] = $after;

            $this->assertSame($loanRow['description'], $row['description'], "row {$index} follows its loan's Ledger order");
            $this->assertMoney(max(0, $moved), $row['debit'], "row {$index} debit is what its loan Ledger row raised");
            $this->assertMoney(max(0, -$moved), $row['credit'], "row {$index} credit is what its loan Ledger row lowered");
            $this->assertMoney(array_sum($latest), $row['balance'], "row {$index} balance is the loans' balances added up");

            if ($loanRow['kind'] === 'repayment') {
                $this->assertMoney($loanRow['principal'] + $loanRow['interest'] + $loanRow['penalty'], $row['credit'], "row {$index} payment credit is its principal, interest and penalty");
                $this->assertSame(Repayment::findOrFail($loanRow['repayment_id'])->receipt_number, $row['reference'], "row {$index} payment reference is its receipt");
            }
        }
    }

    public function test_a_branch_user_does_not_see_a_loan_booked_in_another_branch(): void
    {
        $far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $here = $this->releasedLoanFor(borrower: $member);
        $there = $this->releasedLoanFor(borrower: $member, principal: 40000);
        $there->update(['branch_id' => $far->id]);
        $this->payLoan($here, 11800);
        $this->payLoan($there, 5000);

        $hereClosing = $this->closingOf($here);
        $this->assertContains($there->loan_account_number, array_column($this->memberLedger($member), 'reference'), 'setup: view_all sees both loans');

        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole('loan_officer');
        $this->actingAs($user);
        $this->getJson("/api/loans/{$there->id}/ledger")->assertNotFound();

        $rows = $this->memberLedger($member);

        $this->assertNotContains($there->loan_account_number, array_column($rows, 'reference'), 'another branch\'s loan is left out');
        $this->assertNotContains($there->id, array_column($rows, 'loan_id'), 'no row names another branch\'s loan');
        $this->assertContains($here->id, array_column($rows, 'loan_id'), 'the loan in the user\'s branch appears');
        $this->assertMoney($hereClosing, end($rows)['balance'], 'closing = the visible loan\'s Ledger closing');
    }

    /** @return list<array<string, mixed>> */
    private function memberLedger(Borrower $member): array
    {
        return $this->getJson("/api/borrowers/{$member->id}/ledger")->assertOk()->json('data');
    }

    /** @return array<string, mixed> */
    private function loanLedger(Loan $loan): array
    {
        return $this->getJson("/api/loans/{$loan->id}/ledger")->assertOk()->json('data');
    }

    /** The loan Ledger's closing principal, interest and penalty, added up. */
    private function closingOf(Loan $loan): float
    {
        $closing = $this->loanLedger($loan)['closing'];

        return round($closing['principal'] + $closing['interest'] + $closing['penalty'], 2);
    }

    /** @param  array<string, mixed>  $loanRow */
    private function balanceOf(array $loanRow): float
    {
        $balances = $loanRow['balances'];

        return round($balances['principal'] + $balances['interest'] + $balances['penalty'], 2);
    }

    private function restructure(Loan $source): Loan
    {
        $principal = (float) $this->summaryOf($source)['restructurable_balance'];

        $id = $this->postJson("/api/loans/{$source->id}/restructure", [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => $principal,
            'start_date' => now()->toDateString(),
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/loans/{$id}/submit")->assertOk();
        $this->patchJson("/api/loans/{$id}/approve", ['approval_remarks' => 'ok'])->assertOk();
        $this->patchJson("/api/loans/{$id}/release")->assertOk();
        $this->assertSame('restructured', $source->fresh()->status, 'setup: the source is restructured');

        return Loan::findOrFail($id);
    }

    /**
     * Every row moves the balance by its own debit and credit.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertBalanceWalks(array $rows): void
    {
        $balance = 0.0;

        foreach ($rows as $index => $row) {
            $balance = round($balance + $row['debit'] - $row['credit'], 2);
            $this->assertMoney($balance, $row['balance'], "row {$index} balance = previous + debit - credit");
        }
    }

    private function assertMoney(float|int|string $expected, mixed $actual, string $message = ''): void
    {
        $this->assertEqualsWithDelta((float) $expected, (float) $actual, 0.001, $message);
    }
}
