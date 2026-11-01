<?php

namespace Tests\Feature\InsuranceOwed;

use App\Models\Loan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\InsuranceOwed\Concerns\OwesInsurancePremium;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * insurance:owed-on-closed-loans-report lists completed and restructured
 * loans that still show insurance premium owed, from before payments
 * collected it. It only reads.
 */
class InsuranceOwedClosedLoansReportTest extends TestCase
{
    use OwesInsurancePremium, SetupLendyPH;

    private const COMMAND = 'insurance:owed-on-closed-loans-report';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /** A loan closed before the change, premium still on it (fixture). */
    private function closedLoanOwingPremium(string $status, float $owed = self::OWED): Loan
    {
        $loan = $this->loanOwingPremium();
        DB::table('loans')->where('id', $loan->id)->update(['status' => $status, 'insurance_remaining_balance' => $owed]);

        return $loan->fresh();
    }

    private function runCommand(): string
    {
        $this->assertTrue(
            array_key_exists(self::COMMAND, Artisan::all()),
            'The '.self::COMMAND.' command is not registered.',
        );

        $this->assertSame(Command::SUCCESS, Artisan::call(self::COMMAND));

        return Artisan::output();
    }

    public function test_it_lists_closed_loans_that_still_show_premium_owed(): void
    {
        $completed = $this->closedLoanOwingPremium('completed');
        $restructured = $this->closedLoanOwingPremium('restructured', 150.25);
        $open = $this->loanOwingPremium();
        $settled = $this->closedLoanOwingPremium('completed', 0);

        $output = $this->runCommand();

        $this->assertStringContainsString('Read only. Nothing is written.', $output);
        $this->assertStringContainsString($completed->loan_account_number, $output);
        $this->assertStringContainsString($restructured->loan_account_number, $output);
        $this->assertStringNotContainsString($open->loan_account_number, $output);
        $this->assertStringNotContainsString($settled->loan_account_number, $output);
        $this->assertMatchesRegularExpression('/completed\s*\|\s*1\s*\|\s*₱400\.00/', $output);
        $this->assertMatchesRegularExpression('/restructured\s*\|\s*1\s*\|\s*₱150\.25/', $output);
        $this->assertMatchesRegularExpression('/Closed loans showing premium owed:\s+2 loans, ₱550\.25/', $output);
    }

    public function test_it_writes_nothing(): void
    {
        $this->closedLoanOwingPremium('completed');

        $before = DB::table('loans')->orderBy('id')->get()->toArray();

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->runCommand();

        $writes = array_values(array_filter(
            $statements,
            static fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $sql) === 1,
        ));

        $this->assertNotSame([], $statements, 'The command read nothing, so the check proves nothing.');
        $this->assertSame([], $writes);
        $this->assertEquals($before, DB::table('loans')->orderBy('id')->get()->toArray());
    }
}
