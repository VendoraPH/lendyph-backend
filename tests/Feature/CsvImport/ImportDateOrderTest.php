<?php

namespace Tests\Feature\CsvImport;

use App\Models\Borrower;
use App\Models\CsvImportRun;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\CsvImport\CsvImportProcessor;
use App\Services\CsvImport\CsvImportSchema;
use App\Services\CsvImport\CsvRow;
use App\Services\CsvImport\CustomerRowNormalizer;
use App\Services\CsvImport\LoanRowNormalizer;
use App\Services\CsvImport\RowNoteBag;
use App\Services\CsvImport\ValueNormalizer;
use Tests\TestCase;
use Tests\Traits\BuildsCsvImports;

/**
 * The date order the admin settled on the Check step travels with the import
 * (`csv_import_runs.date_orders`) and the server reads those columns with it
 * (finish run round 4). Before, `03/04/2025` read month-first whatever was
 * chosen, and `15/01/2025` was refused.
 *
 * The rules, written out:
 *  - day-first (`dmy`): a day/month/year cell, split by "/", "-" or ".", is
 *    the day, then the month, with no "reads two ways" warning;
 *  - month-first (`mdy`): the month, then the day, as before, with no warning;
 *  - an ISO date reads as itself under either order;
 *  - no order (none was needed): today's reading, month-first with the
 *    warning for a date that reads both ways.
 */
class ImportDateOrderTest extends TestCase
{
    use BuildsCsvImports;

    /**
     * @return array{0: string|null, 1: list<string>, 2: list<string>}
     */
    private function read(string $cell, ?string $order): array
    {
        $notes = new RowNoteBag;
        $value = (new ValueNormalizer)->date($cell, 'date_released', $notes, order: $order);

        return [$value, $notes->warningCodes(), $notes->errorCodes()];
    }

    public function test_day_first_reads_the_day_then_the_month(): void
    {
        $this->assertSame(['2025-01-15', [], []], $this->read('15/01/2025', 'dmy'));
        $this->assertSame(['2025-04-03', [], []], $this->read('03/04/2025', 'dmy'));
        $this->assertSame(['2025-04-03', [], []], $this->read('3/4/2025', 'dmy'));
        // The separators the Check step reads.
        $this->assertSame(['2025-04-03', [], []], $this->read('03-04-2025', 'dmy'));
        $this->assertSame(['2025-04-03', [], []], $this->read('03.04.2025', 'dmy'));
        // Not a real date read day-first.
        $this->assertSame([null, [], ['date_invalid']], $this->read('01/15/2025', 'dmy'));
    }

    public function test_month_first_reads_as_before_without_the_two_ways_warning(): void
    {
        $this->assertSame(['2025-03-04', [], []], $this->read('03/04/2025', 'mdy'));
        $this->assertSame(['2025-12-25', [], []], $this->read('12/25/2025', 'mdy'));
        $this->assertSame([null, [], ['date_invalid']], $this->read('15/01/2025', 'mdy'));
    }

    public function test_an_iso_date_reads_as_itself_under_either_order(): void
    {
        foreach (['dmy', 'mdy', null] as $order) {
            $this->assertSame(['2025-01-15', [], []], $this->read('2025-01-15', $order), (string) $order);
            $this->assertSame(['2025-03-04', [], []], $this->read('2025-03-04', $order), (string) $order);
        }
    }

    public function test_with_no_order_the_reading_is_unchanged(): void
    {
        $this->assertSame(['2025-03-04', ['ambiguous_date'], []], $this->read('03/04/2025', null));
        $this->assertSame(['2025-12-25', [], []], $this->read('12/25/2025', null));
        $this->assertSame([null, [], ['date_invalid']], $this->read('15/01/2025', null));
        $this->assertSame([null, [], ['date_invalid']], $this->read('03-04-2025', null));
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function cells(string $shape, array $values): array
    {
        $cells = array_fill(0, count(CsvImportSchema::keys($shape)), '');

        foreach ($values as $key => $value) {
            $cells[CsvImportSchema::indexOf($shape, $key)] = $value;
        }

        return $cells;
    }

    public function test_each_file_reads_its_date_columns_with_their_own_orders(): void
    {
        $loan = (new LoanRowNormalizer)->normalize(new CsvRow(1, 1, $this->cells(CsvImportSchema::LOANS, $this->loanRow('A-001', 'L-1', [
            'date_released' => '03/01/2026',
            'maturity_date' => '2026-07-03',
            'term_in_months' => '6',
        ]))), ['date_released' => 'dmy']);

        $this->assertTrue($loan->isValid());
        $this->assertSame('2026-01-03', $loan->value('date_released'));
        $this->assertSame('2026-07-03', $loan->value('maturity_date'));

        $member = (new CustomerRowNormalizer)->normalize(new CsvRow(1, 1, $this->cells(CsvImportSchema::CUSTOMERS, $this->customerRow('A-001', [
            'birthdate' => '03/04/1985',
            'date_hired' => '04/03/2010',
        ]))), dateOrders: ['birthdate' => 'dmy', 'date_hired' => 'mdy']);

        $this->assertTrue($member->isValid());
        $this->assertSame('1985-04-03', $member->value('birthdate'));
        $this->assertSame('2010-04-03', $member->value('date_hired'));
        $this->assertNotContains('ambiguous_date', array_column($member->warningsToArray(), 'code'));
    }

    public function test_a_maturity_date_reads_in_its_own_order_or_as_before_with_none(): void
    {
        $loanOf = fn (array $orders) => (new LoanRowNormalizer)->normalize(new CsvRow(1, 1, $this->cells(CsvImportSchema::LOANS, $this->loanRow('A-001', 'L-1', [
            'date_released' => '03/01/2026',
            'maturity_date' => '07/03/2026',
        ]))), $orders);

        // Day-first release, month-first maturity: 3 January to 3 July.
        $both = $loanOf(['date_released' => 'dmy', 'maturity_date' => 'mdy']);
        $this->assertSame(['2026-01-03', '2026-07-03'], [$both->value('date_released'), $both->value('maturity_date')]);
        $this->assertNotContains('ambiguous_date', array_column($both->warningsToArray(), 'code'));

        // No order for the maturity date: read month-first as before, and it says
        // the date reads two ways.
        $none = $loanOf(['date_released' => 'dmy']);
        $this->assertSame(['2026-01-03', '2026-07-03'], [$none->value('date_released'), $none->value('maturity_date')]);
        $this->assertSame(['ambiguous_date'], array_column($none->warningsToArray(), 'code'));
        $this->assertSame('maturity_date', $none->warningsToArray()[0]['field']);
    }

    public function test_a_membership_date_reads_in_its_own_order(): void
    {
        $member = (new CustomerRowNormalizer)->normalize(new CsvRow(1, 1, $this->cells(CsvImportSchema::CUSTOMERS, $this->customerRow('A-001', [
            'membership_date' => '03/04/2020',
        ]))), mayImportMembership: true, dateOrders: ['membership_date' => 'dmy']);

        $this->assertTrue($member->isValid());
        $this->assertSame('2020-04-03', $member->value('membership_date'));
        $this->assertNotContains('ambiguous_date', array_column($member->warningsToArray(), 'code'));
    }

    private function runToIdle(CsvImportRun $run): void
    {
        $processor = new CsvImportProcessor;

        for ($i = 0; $i < 200; $i++) {
            if ($processor->advance($run)->idle) {
                return;
            }

            $run->refresh();
        }

        $this->fail('The run did not settle.');
    }

    public function test_an_import_reads_its_dates_in_the_order_the_run_carries(): void
    {
        $this->seedForImport();

        $product = LoanProduct::factory()->create([
            'name' => 'Salary Loan', 'interest_rate' => 3.0, 'interest_method' => 'straight', 'term' => 6,
            'frequency' => 'monthly', 'penalty_rate' => 2.0, 'grace_period_days' => 3, 'min_amount' => 1000, 'max_amount' => 1000000,
        ]);
        $run = $this->makeRun([
            'product_mapping' => ['Salary Loan' => $product->id],
            'date_orders' => [
                'customers' => ['birthdate' => 'dmy'],
                'loans' => ['date_released' => 'dmy', 'maturity_date' => 'dmy'],
            ],
        ]);

        $this->makeFile($run, 'customers', [$this->customerRow('A-001', ['birthdate' => '03/04/1985'])]);
        $this->makeFile($run, 'loans', [$this->loanRow('A-001', 'L-1', ['date_released' => '03/01/2026', 'maturity_date' => '03/07/2026'])]);

        $this->runToIdle($run);

        $this->assertSame('completed', $run->fresh()->phase);
        $this->assertSame('1985-04-03', Borrower::where('external_account_no', 'A-001')->firstOrFail()->birthdate->toDateString());

        $loan = Loan::where('external_loan_no', 'L-1')->firstOrFail();
        $this->assertSame('2026-01-03', $loan->start_date->toDateString());
        $this->assertSame('2026-07-03', $loan->maturity_date->toDateString());
    }

    public function test_a_run_with_no_orders_reads_as_before(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $this->makeFile($run, 'customers', [$this->customerRow('A-001', ['birthdate' => '03/04/1985'])]);

        $this->runToIdle($run);

        $this->assertNull($run->fresh()->date_orders);
        $this->assertSame('1985-03-04', Borrower::where('external_account_no', 'A-001')->firstOrFail()->birthdate->toDateString());
    }
}
