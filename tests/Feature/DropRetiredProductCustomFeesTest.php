<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LoanProduct;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Owner answer (2026-11-11): the retired product "Additional Fees"
 * (`loan_products.custom_fees`) are dropped. They were never charged, so only
 * the product rows change. `loan-products:drop-retired-custom-fees` (with
 * `--dry-run` to preview) and its migration list each product and fee, write
 * one system audit row per product, and change nothing on a second run.
 */
class DropRetiredProductCustomFeesTest extends TestCase
{
    use SetupLendyPH;

    private const COMMAND = 'loan-products:drop-retired-custom-fees';

    private const MIGRATION = 'migrations/2026_11_11_620100_drop_retired_product_custom_fees.php';

    private const HANDLING = ['name' => 'Handling', 'type' => 'fixed', 'value' => 1500];

    private const RUSH = ['name' => 'Rush', 'type' => 'percentage', 'value' => 0.3333];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_a_dry_run_lists_each_product_and_fee_and_writes_nothing(): void
    {
        $salary = $this->product('Salary Loan', [self::HANDLING, self::RUSH]);
        $this->product('Plain Loan', null);
        $this->product('Emptied Loan', []);
        $before = $this->snapshot();

        $output = $this->drop(['--dry-run' => true]);

        $this->assertStringContainsString('Dry run. Nothing is written.', $output);
        $this->assertStringContainsString("Salary Loan (id {$salary->id}): would drop 2 fee(s): Handling (fixed, ₱1,500.00); Rush (percentage, 0.3333%)", $output);
        $this->assertStringNotContainsString('Plain Loan', $output);
        $this->assertStringNotContainsString('Emptied Loan', $output);
        $this->assertStringContainsString('Would drop 2 fee(s) from 1 product(s); 0 left as they are.', $output);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_it_drops_the_fees_and_records_one_system_row_per_product(): void
    {
        $salary = $this->product('Salary Loan', [self::HANDLING, self::RUSH]);
        $business = $this->product('Business Loan', [['name' => 'Monthly Rate', 'type' => 'formula', 'value' => 0.5, 'per_month' => true]]);
        $auditRows = AuditLog::count();

        $output = $this->drop();

        $this->assertStringContainsString("Salary Loan (id {$salary->id}): dropped 2 fee(s)", $output);
        $this->assertStringContainsString("Business Loan (id {$business->id}): dropped 1 fee(s): Monthly Rate (formula, 0.5% per month)", $output);
        $this->assertStringContainsString('Dropped 3 fee(s) from 2 product(s); 0 left as they are.', $output);
        $this->assertNull(DB::table('loan_products')->where('id', $salary->id)->value('custom_fees'));
        $this->assertNull(DB::table('loan_products')->where('id', $business->id)->value('custom_fees'));

        // One row per product, written by the fix: no second row from the Auditable trait.
        $this->assertSame($auditRows + 2, AuditLog::count());
        $row = AuditLog::query()->where('auditable_type', (new LoanProduct)->getMorphClass())->where('auditable_id', $salary->id)->latest('id')->firstOrFail();
        $this->assertNull($row->user_id);
        $this->assertNull($row->ip_address);
        $this->assertSame('updated', $row->action);
        $this->assertEquals(['custom_fees' => [self::HANDLING, self::RUSH]], $row->old_values);
        $this->assertSame(['custom_fees' => null], $row->new_values);
        $this->assertSame(
            'System correction: Loan product "Salary Loan" retired Additional Fees dropped (2 fees: Handling, Rush). They were never charged.',
            $row->description,
        );
    }

    public function test_the_product_api_and_the_report_then_show_none(): void
    {
        $salary = $this->product('Salary Loan', [self::HANDLING]);

        $this->drop();

        $this->getJson("/api/loan-products/{$salary->id}")->assertOk()->assertJsonPath('data.custom_fees', []);
        Artisan::call('loan-products:retired-custom-fees-report');
        $this->assertMatchesRegularExpression('/Products holding retired custom fees:\s+0 products, 0 fees/', Artisan::output());
    }

    public function test_it_changes_no_loan_schedule_or_journal(): void
    {
        $loan = $this->createReleasedLoan();
        DB::table('loan_products')->where('id', $loan->loan_product_id)->update(['custom_fees' => json_encode([self::HANDLING])]);
        $tables = fn (): array => [
            'loans' => DB::table('loans')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'schedules' => DB::table('amortization_schedules')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'journals' => DB::table('accounting_journals')->count(),
            'journal_lines' => DB::table('accounting_journal_lines')->count(),
        ];
        $before = $tables();

        $this->drop();

        $this->assertNull(DB::table('loan_products')->where('id', $loan->loan_product_id)->value('custom_fees'));
        $this->assertSame($before, $tables());
    }

    public function test_a_stored_value_that_is_not_a_list_of_fees_is_left_as_it_is_and_listed(): void
    {
        $odd = $this->product('Odd Loan', null);
        DB::table('loan_products')->where('id', $odd->id)->update(['custom_fees' => json_encode('handling 100')]);
        $before = $this->snapshot();

        $output = $this->drop();

        $this->assertStringContainsString("Odd Loan (id {$odd->id}) left as it is: its stored fees are not a list", $output);
        $this->assertStringContainsString('Dropped 0 fee(s) from 0 product(s); 1 left as they are.', $output);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->product('Salary Loan', [self::HANDLING]);
        $this->drop();
        $after = $this->snapshot();

        $this->assertStringContainsString('Dropped 0 fee(s) from 0 product(s); 0 left as they are.', $this->drop());
        $this->assertSame($after, $this->snapshot());
    }

    public function test_a_product_changed_while_the_fix_ran_is_left_as_it_is_and_logged(): void
    {
        $salary = $this->product('Salary Loan', [self::HANDLING]);
        $raced = false;
        DB::listen(function ($query) use (&$raced, $salary): void {
            if (! $raced && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from `loan_products`') && ! str_contains($query->sql, 'for update')) {
                $raced = true;
                DB::table('loan_products')->where('id', $salary->id)->update(['custom_fees' => json_encode([self::RUSH])]);
            }
        });
        Log::spy();
        $auditRows = AuditLog::count();

        $output = $this->drop();

        $this->assertTrue($raced);
        $this->assertStringContainsString("Salary Loan (id {$salary->id}) left as it is: changed while the fix ran", $output);
        $this->assertSame([self::RUSH], json_decode((string) DB::table('loan_products')->where('id', $salary->id)->value('custom_fees'), true));
        $this->assertSame($auditRows, AuditLog::count());
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => str_contains($message, 'changed while the fix ran')
                && ($context['id'] ?? null) === $salary->id,
        );
    }

    public function test_the_migration_applies_the_same_fix_and_runs_safely_twice(): void
    {
        $salary = $this->product('Salary Loan', [self::HANDLING, self::RUSH]);
        $auditRows = AuditLog::count();
        Log::spy();

        $this->migration()->up();

        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message): bool => $message === 'retired product custom fees: 2 dropped from 1 product(s), 0 left as they are',
        );
        $this->assertNull(DB::table('loan_products')->where('id', $salary->id)->value('custom_fees'));
        $this->assertSame($auditRows + 1, AuditLog::count());

        $after = $this->snapshot();
        $this->migration()->up();
        $this->assertSame($after, $this->snapshot());
    }

    public function test_the_migration_and_the_command_reach_the_same_outcome(): void
    {
        DB::beginTransaction();
        $this->product('Salary Loan', [self::HANDLING, self::RUSH]);
        $odd = $this->product('Odd Loan', null);
        DB::table('loan_products')->where('id', $odd->id)->update(['custom_fees' => json_encode(7)]);
        $this->product('Business Loan', [['name' => 'Monthly Rate', 'type' => 'formula', 'value' => 0.5]]);
        $fromId = (int) AuditLog::max('id');

        DB::beginTransaction();
        $this->drop();
        $byCommand = $this->outcome($fromId);
        DB::rollBack();

        $this->migration()->up();
        $byMigration = $this->outcome($fromId);
        DB::rollBack();

        $this->assertSame($byCommand, $byMigration);
        $this->assertCount(2, $byMigration['audit_rows']);
    }

    public function test_the_migration_depends_on_no_application_class(): void
    {
        $this->assertDoesNotMatchRegularExpression('/^use App\\\\/m', file_get_contents(database_path(self::MIGRATION)));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function drop(array $options = []): string
    {
        $this->assertSame(0, Artisan::call(self::COMMAND, $options));

        return Artisan::output();
    }

    /**
     * Written straight to the row, as products saved before the retirement hold them.
     *
     * @param  list<array<string, mixed>>|null  $customFees
     */
    private function product(string $name, ?array $customFees): LoanProduct
    {
        return LoanProduct::factory()->create([
            'name' => $name,
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'term_unit' => 'months',
            'frequency' => 'monthly',
            'custom_fees' => $customFees,
        ]);
    }

    /**
     * @return array{products: array<int, mixed>, audit_rows: int}
     */
    private function snapshot(): array
    {
        return [
            'products' => DB::table('loan_products')->orderBy('id')->get(['id', 'custom_fees', 'updated_at'])->map(fn ($row) => (array) $row)->all(),
            'audit_rows' => AuditLog::count(),
        ];
    }

    /**
     * @return array{products: array<int, mixed>, audit_rows: list<array<string, mixed>>}
     */
    private function outcome(int $fromId): array
    {
        return [
            'products' => DB::table('loan_products')->orderBy('id')->pluck('custom_fees', 'id')->all(),
            'audit_rows' => AuditLog::query()->where('id', '>', $fromId)->orderBy('auditable_id')->get()
                ->map(fn (AuditLog $row): array => $row->only(['user_id', 'action', 'auditable_type', 'auditable_id', 'old_values', 'new_values', 'ip_address', 'user_agent', 'description']))
                ->all(),
        ];
    }

    private function migration(): Migration
    {
        return require database_path(self::MIGRATION);
    }
}
