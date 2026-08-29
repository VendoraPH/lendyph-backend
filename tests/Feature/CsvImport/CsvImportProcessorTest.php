<?php

namespace Tests\Feature\CsvImport;

use App\Models\AmortizationSchedule;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\CsvImportRow;
use App\Models\CsvImportRun;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\ShareCapitalPledge;
use App\Services\CsvImport\CsvImportProcessor;
use App\Services\CsvImport\CsvImportSchema;
use App\Services\CsvImport\CsvImportStager;
use App\Services\CsvImport\ImportErrorDigest;
use App\Services\CsvImport\ImportTick;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;
use Tests\Traits\BuildsCsvImports;

/**
 * The import pass: what it writes, what it refuses to write, and what happens
 * when a row blows up in the middle of a chunk.
 */
class CsvImportProcessorTest extends TestCase
{
    use BuildsCsvImports;

    private function processor(): CsvImportProcessor
    {
        return new CsvImportProcessor;
    }

    /**
     * Drive a run to a standstill, exactly as the command's loop does.
     */
    private function runToIdle(CsvImportRun $run, int $maxTicks = 200): ImportTick
    {
        $processor = $this->processor();
        $tick = new ImportTick($run->phase, 0);

        for ($i = 0; $i < $maxTicks; $i++) {
            $tick = $processor->advance($run);

            if ($tick->idle) {
                return $tick;
            }

            $run->refresh();
        }

        $this->fail("The run did not settle within {$maxTicks} ticks — the window is not advancing.");
    }

    private function salaryLoanProduct(): LoanProduct
    {
        return LoanProduct::factory()->create([
            'name' => 'Salary Loan',
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'penalty_rate' => 2.0,
            'grace_period_days' => 3,
            'min_amount' => 1000,
            'max_amount' => 1000000,
        ]);
    }

    /**
     * Corrupt one staged row's money value into the exact shape a JSON column
     * would have produced had the payload been written as floats.
     *
     * This is the realistic poison: it throws inside the chunk, after earlier
     * rows in the same chunk have already written borrowers.
     */
    private function poisonMoney(CsvImportRow $row, string $key = 'monthly_income'): void
    {
        $payload = json_decode((string) $row->getRawOriginal('normalized'), true);
        $index = array_search($key, CsvImportSchema::keys($payload['shape']), true);
        $payload['values'][$index] = 18500;

        DB::table('csv_import_rows')->where('id', $row->id)->update(['normalized' => json_encode($payload)]);
    }

    /**
     * The invariant the whole design rests on.
     *
     * The chunk's borrowers AND the `csv_import_rows` stamps that describe them
     * are written in ONE transaction, so a throw part-way through leaves neither.
     * Driven against the transactional unit directly, because that is the only
     * place the intermediate state is observable — the caller above it isolates
     * and retries, which would hide exactly what is being asserted here.
     */
    public function test_a_crash_mid_chunk_leaves_nothing_half_imported_and_nothing_falsely_stamped(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'customers', [
            $this->customerRow('A-001', ['first_name' => 'Ana']),
            $this->customerRow('A-002', ['first_name' => 'Ben']),
            $this->customerRow('A-003', ['first_name' => 'Cora']),
        ]);

        (new CsvImportStager)->stage($file);

        $rows = CsvImportRow::where('csv_import_file_id', $file->id)->orderBy('id')->get()->all();

        // The LAST row of the chunk throws, so the first two have already been
        // written by the time it does.
        $this->poisonMoney($rows[2]);
        $rows[2]->refresh();

        try {
            DB::transaction(fn () => $this->processor()->writeChunk($run, $rows, CsvImportSchema::CUSTOMERS));
            $this->fail('The poisoned row did not throw, so this test proves nothing.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('rather than a string', $e->getMessage());
        }

        // Nothing half-imported: the two borrowers written before the throw went
        // back with it, and so did their pledges.
        $this->assertSame(0, Borrower::whereNotNull('external_account_no')->count());
        $this->assertSame(0, ShareCapitalPledge::count());

        // Nothing falsely stamped: no row claims an outcome it does not have.
        $this->assertSame(0, CsvImportRow::where('csv_import_file_id', $file->id)->whereNotNull('result')->count());
    }

    /**
     * The other half of the same story: after the chunk rolls back, the rows are
     * retried one at a time, so one bad row costs one row rather than fifty.
     */
    public function test_a_poison_row_is_isolated_as_failed_while_its_neighbours_import(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'customers', [
            $this->customerRow('A-001', ['first_name' => 'Ana']),
            $this->customerRow('A-002', ['first_name' => 'Ben']),
            $this->customerRow('A-003', ['first_name' => 'Cora']),
        ]);

        (new CsvImportStager)->stage($file);

        $rows = CsvImportRow::where('csv_import_file_id', $file->id)->orderBy('id')->get();
        $this->poisonMoney($rows[1]);

        $this->runToIdle($run);

        $stamped = CsvImportRow::where('csv_import_file_id', $file->id)->orderBy('id')->get();

        $this->assertSame('imported', $stamped[0]->result);
        $this->assertSame('failed', $stamped[1]->result);
        $this->assertSame('imported', $stamped[2]->result);

        $this->assertSame('exception', $stamped[1]->result_category);

        // The stamp names the LINE, never the exception. This column is
        // rendered by the error screen and streamed by errors.csv, and a
        // driver message in it is the member's own record — see
        // test_a_database_error_puts_no_cell_value_in_the_row_or_the_log().
        $this->assertSame(
            "Row {$stamped[1]->line_number} could not be written (unexpected error). See the run log.",
            (string) $stamped[1]->result_message,
        );

        $this->assertSame(['A-001', 'A-003'], Borrower::whereNotNull('external_account_no')
            ->orderBy('external_account_no')->pluck('external_account_no')->all());
    }

    /**
     * A row that keeps killing the PROCESS — an OOM, a deploy restart — is
     * something no rollback can catch, because nothing rolls back a signal. The
     * attempt counter is claimed in its own committed statement before the work
     * starts, so after MAX_ATTEMPTS the row is given up on and the run moves.
     *
     * Simulated by pre-setting the counter to where those deaths would have left
     * it, which is exactly the state the next tick would find.
     */
    public function test_the_attempt_cap_stops_a_row_wedging_the_run_forever(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'customers', [
            $this->customerRow('A-001', ['first_name' => 'Ana']),
            $this->customerRow('A-002', ['first_name' => 'Ben']),
            $this->customerRow('A-003', ['first_name' => 'Cora']),
        ]);

        (new CsvImportStager)->stage($file);

        $rows = CsvImportRow::where('csv_import_file_id', $file->id)->orderBy('id')->get();

        DB::table('csv_import_rows')->where('id', $rows[1]->id)
            ->update(['attempts' => CsvImportProcessor::MAX_ATTEMPTS]);

        $this->runToIdle($run);

        $stamped = CsvImportRow::where('csv_import_file_id', $file->id)->orderBy('id')->get();

        $this->assertSame('failed', $stamped[1]->result);
        $this->assertSame('abandoned', $stamped[1]->result_category);
        $this->assertStringContainsString('given up on', (string) $stamped[1]->result_message);

        // Given up on WITHOUT being processed, and without taking its
        // neighbours with it.
        $this->assertNull($stamped[1]->borrower_id);
        $this->assertSame('imported', $stamped[0]->result);
        $this->assertSame('imported', $stamped[2]->result);
        $this->assertSame('completed', $run->fresh()->phase);
    }

    /**
     * Re-running the whole import must be a reported no-op, not a second set of
     * members. `borrowers.external_account_no` is the join key AND the backstop,
     * and it is unique.
     */
    public function test_re_running_the_import_creates_no_borrower_twice(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'customers', [
            $this->customerRow('A-001', ['first_name' => 'Ana']),
            $this->customerRow('A-002', ['first_name' => 'Ben']),
        ]);

        (new CsvImportStager)->stage($file);
        $this->runToIdle($run);

        $this->assertSame(2, Borrower::whereNotNull('external_account_no')->count());

        // A second run over the same file, exactly as an admin re-uploading it
        // would produce.
        $second = $this->makeRun();
        $secondFile = $this->makeFile($second, 'customers', [
            $this->customerRow('A-001', ['first_name' => 'Ana']),
            $this->customerRow('A-002', ['first_name' => 'Ben']),
        ]);

        (new CsvImportStager)->stage($secondFile);
        $this->runToIdle($second);

        $this->assertSame(2, Borrower::whereNotNull('external_account_no')->count());
        $this->assertSame(
            ['already_imported', 'already_imported'],
            CsvImportRow::where('csv_import_file_id', $secondFile->id)->orderBy('id')->pluck('result')->all(),
        );
    }

    /**
     * The trap that costs the CSV its Pledge Amt.
     *
     * Borrower::booted()'s `created` hook is what creates the ShareCapitalPledge,
     * so suppressing model events to quiet the per-row audit rows would take the
     * pledge with it — silently, with no error anywhere, and unrecoverably,
     * because `pledges:backfill` hardcodes amount = 0.
     */
    public function test_the_share_capital_pledge_carries_the_csv_pledge_amount(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'customers', [
            $this->customerRow('A-001', ['pledge_amount' => '12,500.00']),
            $this->customerRow('A-002', ['pledge_amount' => '']),
        ]);

        (new CsvImportStager)->stage($file);
        $this->runToIdle($run);

        $withPledge = Borrower::where('external_account_no', 'A-001')->firstOrFail();
        $withoutPledge = Borrower::where('external_account_no', 'A-002')->firstOrFail();

        $this->assertNotNull($withPledge->shareCapitalPledge, 'The pledge row was never created — model events were suppressed.');
        $this->assertSame('12500.00', (string) $withPledge->shareCapitalPledge->amount);
        $this->assertSame('12500.00', (string) $withPledge->pledge_amount);

        // A blank cell is a real zero pledge, not a missing row.
        $this->assertNotNull($withoutPledge->shareCapitalPledge);
        $this->assertSame('0.00', (string) $withoutPledge->shareCapitalPledge->amount);
    }

    /**
     * Both address columns, or the member's address is invisible in the app.
     *
     * `borrowers.address` is the legacy column and it is the one the admin UI
     * reads and writes — labelled "Street Address" on the member form.
     * `street_address` is what the newer API resources expose. Writing one and
     * not the other loses the value to whichever half is not looking.
     */
    public function test_it_writes_both_address_and_street_address(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'customers', [
            $this->customerRow('A-001', ['street_address' => '12 Mabini Street']),
        ]);

        (new CsvImportStager)->stage($file);
        $this->runToIdle($run);

        $borrower = Borrower::where('external_account_no', 'A-001')->firstOrFail();

        $this->assertSame('12 Mabini Street', $borrower->address);
        $this->assertSame('12 Mabini Street', $borrower->street_address);
    }

    /**
     * `active`, never `pending`. Borrower::members() excludes `pending` from
     * every read path that means "member", so importing a coop's membership as
     * pending would migrate all of it into invisibility.
     */
    public function test_imported_members_are_active_and_on_the_run_branch(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'customers', [$this->customerRow('A-001')]);

        (new CsvImportStager)->stage($file);
        $this->runToIdle($run);

        $borrower = Borrower::where('external_account_no', 'A-001')->firstOrFail();

        $this->assertSame('active', $borrower->status);
        $this->assertSame($this->branch->id, $borrower->branch_id);
        $this->assertSame(1, Borrower::members()->whereNotNull('external_account_no')->count());
    }

    /**
     * Loan rows join to members through `borrowers.external_account_no`, which
     * only exists once the customers file has been imported. Starting early does
     * not fail loudly — it produces a pile of `borrower_not_found` rows for
     * members who were three chunks away.
     */
    public function test_the_loans_phase_refuses_to_start_before_customers_completes(): void
    {
        $this->seedForImport();

        $product = $this->salaryLoanProduct();
        $run = $this->makeRun(['product_mapping' => ['Salary Loan' => $product->id]]);

        $customers = $this->makeFile($run, 'customers', [$this->customerRow('A-001')]);
        $this->makeFile($run, 'loans', [$this->loanRow('A-001', 'L-1')]);

        (new CsvImportStager)->stage($customers->fresh());

        // Forced into the loans phase with the customers file untouched, which
        // is what a hand-edited row or a half-finished resume looks like.
        $run->forceFill(['phase' => 'importing_loans'])->save();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/cannot import loans yet/');

        $this->processor()->advance($run->fresh());
    }

    /**
     * The happy path for the whole run, and the four things an imported loan has
     * to carry that a natively originated one does not.
     */
    public function test_it_imports_a_loan_with_its_reconstructed_schedule(): void
    {
        $this->seedForImport();

        $product = $this->salaryLoanProduct();
        $run = $this->makeRun(['product_mapping' => ['Salary Loan' => $product->id]]);

        $this->makeFile($run, 'customers', [$this->customerRow('A-001')]);
        $this->makeFile($run, 'loans', [$this->loanRow('A-001', 'L-0001')]);

        $this->runToIdle($run);

        $run->refresh();
        $this->assertSame('completed', $run->phase);

        $loan = Loan::where('external_loan_no', 'L-0001')->firstOrFail();

        // Live, and on the tab an operator looks for a migrated book on.
        $this->assertSame('ongoing', $loan->status);
        // Pre-import arrears stop here, or the night after the import charges
        // months of penalties the coop already collected.
        $this->assertSame('2026-06-30', $loan->imported_arrears_baseline->toDateString());
        // NULL, or LoanService::release()'s number arithmetic breaks permanently
        // on this deployment.
        $this->assertNull($loan->loan_account_number);
        $this->assertMatchesRegularExpression('/^LA-\d{6}$/', $loan->application_number);

        // A period count derived from the dates, not the CSV's "Term in Months".
        $this->assertSame(6, $loan->term);
        $this->assertSame(6, $loan->amortizationSchedules()->count());
        // reorder(), because the relation already carries an ORDER BY that a
        // second orderBy would only append to.
        $this->assertSame(
            '2026-07-15',
            $loan->amortizationSchedules()->reorder()->orderByDesc('period_number')->first()->due_date->toDateString(),
            'The last instalment must fall on the maturity date the file states, verbatim.',
        );

        // The two invariants the reconstruction exists to reproduce.
        $this->assertSame(
            '40000.00',
            number_format((float) AmortizationSchedule::where('loan_id', $loan->id)
                ->selectRaw('SUM('.AmortizationSchedule::remainingPrincipalSql().') as v')->value('v'), 2, '.', ''),
        );
        $this->assertSame(
            '7200.00',
            number_format((float) AmortizationSchedule::where('loan_id', $loan->id)
                ->selectRaw('SUM('.AmortizationSchedule::remainingInterestSql().') as v')->value('v'), 2, '.', ''),
        );
    }

    /**
     * `deductions` is ALWAYS an array. Null reads as "unknown" to every consumer
     * and leaves the disclosure statement with nothing to itemise; an empty array
     * says plainly that nothing was withheld.
     */
    public function test_deductions_are_always_an_explicit_array(): void
    {
        $this->seedForImport();

        $product = $this->salaryLoanProduct();
        $run = $this->makeRun(['product_mapping' => ['Salary Loan' => $product->id]]);

        $this->makeFile($run, 'customers', [
            $this->customerRow('A-001'),
            $this->customerRow('A-002'),
        ]);
        $this->makeFile($run, 'loans', [
            $this->loanRow('A-001', 'L-WITH', ['processing_fee' => '1,200.00', 'service_fee' => '600.00']),
            $this->loanRow('A-002', 'L-NONE', ['processing_fee' => '', 'service_fee' => '', 'other_fee_amount' => '']),
        ]);

        $this->runToIdle($run);

        $withFees = Loan::where('external_loan_no', 'L-WITH')->firstOrFail();
        $withoutFees = Loan::where('external_loan_no', 'L-NONE')->firstOrFail();

        $this->assertIsArray($withFees->deductions);

        /*
         * Read by NAME, deliberately, and this test proved why on its first run.
         *
         * `loans.deductions` is a MySQL JSON column, so the item objects come
         * back with their keys reordered by length then lexicographically —
         * name, type, amount, original_value — not in the order they were
         * written. That is harmless HERE, because every consumer indexes these
         * by key, and it is the same shape LoanService::createLoan() has always
         * stored. It is exactly the property that makes an object unusable for
         * `csv_import_rows.raw`, where position is the meaning.
         */
        $this->assertCount(2, $withFees->deductions);
        $this->assertSame(['Processing Fee', 'Service Fee'], array_column($withFees->deductions, 'name'));
        $this->assertSame(['1200.00', '600.00'], array_column($withFees->deductions, 'amount'));
        $this->assertSame(['fixed', 'fixed'], array_column($withFees->deductions, 'type'));
        $this->assertSame(['1200.00', '600.00'], array_column($withFees->deductions, 'original_value'));

        $this->assertSame('1800.00', (string) $withFees->total_deductions);
        $this->assertSame('58200.00', (string) $withFees->net_proceeds);

        $this->assertIsArray($withoutFees->deductions, 'A fee-free loan stored NULL deductions rather than an empty array.');
        $this->assertSame([], $withoutFees->deductions);
        $this->assertSame('0.00', (string) $withoutFees->total_deductions);
    }

    /**
     * The per-row audit rows are gone, but everything else about the write is
     * untouched. Asserted together because the two are one decision: suppression
     * had to be aimed at the audit row rather than at the event carrying it.
     */
    public function test_it_writes_no_per_row_audit_entries_but_still_runs_the_model_hooks(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'customers', [
            $this->customerRow('A-001'),
            $this->customerRow('A-002'),
        ]);

        (new CsvImportStager)->stage($file);
        $this->runToIdle($run);

        $this->assertSame(0, AuditLog::where('auditable_type', Borrower::class)->count());

        // The hooks that share that event still ran: the code sequence and the
        // pledge.
        foreach (Borrower::whereNotNull('external_account_no')->get() as $borrower) {
            $this->assertMatchesRegularExpression('/^BRW-\d{6}$/', $borrower->borrower_code);
            $this->assertNotNull($borrower->shareCapitalPledge);
        }
    }

    /**
     * Suppression is scoped to the import and must not leak. If it did, every
     * ordinary edit made after an import ran in the same process would go
     * unaudited — a far worse bug than the noise it was avoiding.
     */
    public function test_audit_suppression_does_not_outlive_the_import(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'customers', [$this->customerRow('A-001')]);

        (new CsvImportStager)->stage($file);
        $this->runToIdle($run);

        Borrower::factory()->create(['branch_id' => $this->branch->id, 'first_name' => 'Walkin']);

        $this->assertSame(1, AuditLog::where('auditable_type', Borrower::class)->where('action', 'created')->count());
    }

    /**
     * Rows that failed validation at staging are decided too, so that "no result"
     * and "still to do" are the same set. Otherwise every progress figure counts
     * them as outstanding forever and the run never reads as finished.
     */
    public function test_invalid_rows_are_stamped_skipped_rather_than_left_undecided(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'customers', [
            $this->customerRow('A-001'),
            $this->customerRow('', ['first_name' => '']),
        ]);

        (new CsvImportStager)->stage($file);
        $this->runToIdle($run);

        $invalid = CsvImportRow::where('csv_import_file_id', $file->id)->where('status', 'invalid')->firstOrFail();

        $this->assertSame('skipped', $invalid->result);
        $this->assertSame('invalid_row', $invalid->result_category);
        $this->assertSame(0, CsvImportRow::where('csv_import_file_id', $file->id)->pending()->count());
    }

    /**
     * A mapping that covers every staged product string can still fail to
     * RESOLVE one — the product it points at may have been deleted since, and
     * the staged list the coverage gate checks is capped. So the per-row failure
     * has to stay, and it has to name the product: the error report groups by
     * `result_category`, and a single generic bucket would render "312 rows
     * failed" instead of something an admin can act on.
     */
    public function test_a_loan_whose_mapped_product_cannot_be_resolved_fails_under_a_named_category(): void
    {
        $this->seedForImport();

        // Mapped — so the coverage gate is satisfied — but pointing at a product
        // that is not there.
        $run = $this->makeRun(['product_mapping' => ['Calamity Loan' => 999999]]);

        $this->makeFile($run, 'customers', [$this->customerRow('A-001')]);
        $this->makeFile($run, 'loans', [$this->loanRow('A-001', 'L-1', ['loan_product' => 'Calamity Loan'])]);

        $this->runToIdle($run);

        $row = CsvImportRow::where('result', 'failed')->firstOrFail();

        $this->assertSame('unmapped_product:Calamity Loan', $row->result_category);
        $this->assertStringContainsString('Calamity Loan', (string) $row->result_message);
        $this->assertSame(0, Loan::whereNotNull('external_loan_no')->count());
        // The member still imported: one unresolvable product must not cost the
        // coop its membership.
        $this->assertSame(1, Borrower::whereNotNull('external_account_no')->count());
    }

    /**
     * The mapping gate, which is the whole of this transition: the endpoint that
     * stores the mapping writes `product_mapping` and stops, because `phase` is
     * owned here.
     *
     * Held to COVERAGE rather than to the mapping merely existing — a partial
     * mapping that started the import would hand the admin a few hundred failed
     * loans to fix by re-running, when what they needed was to finish the form
     * they were already on.
     */
    public function test_the_run_parks_until_the_mapping_covers_every_staged_product(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $this->makeFile($run, 'customers', [$this->customerRow('A-001'), $this->customerRow('A-002')]);
        $this->makeFile($run, 'loans', [
            $this->loanRow('A-001', 'L-1', ['loan_product' => 'Salary Loan']),
            $this->loanRow('A-002', 'L-2', ['loan_product' => 'Emergency Loan']),
        ]);

        $tick = $this->runToIdle($run);

        $this->assertSame('awaiting_mapping', $tick->phase);
        $this->assertSame(['Salary Loan', 'Emergency Loan'], $run->fresh()->notes[CsvImportProcessor::NOTE_LOAN_PRODUCTS]);
        $this->assertSame(0, Borrower::whereNotNull('external_account_no')->count());

        $salary = $this->salaryLoanProduct();

        // Half a mapping is not a mapping.
        $run->forceFill(['product_mapping' => ['Salary Loan' => $salary->id]])->save();
        $this->runToIdle($run->fresh());

        $this->assertSame('awaiting_mapping', $run->fresh()->phase, 'A partial mapping must not start the import.');
        $this->assertSame(0, Borrower::whereNotNull('external_account_no')->count());

        $emergency = LoanProduct::factory()->create(['name' => 'Emergency Loan', 'interest_rate' => 3.0, 'interest_method' => 'straight', 'term' => 6, 'frequency' => 'monthly', 'min_amount' => 1000, 'max_amount' => 1000000]);
        $run->forceFill(['product_mapping' => ['Salary Loan' => $salary->id, 'Emergency Loan' => $emergency->id]])->save();

        $this->runToIdle($run->fresh());

        $this->assertSame('completed', $run->fresh()->phase);
        $this->assertSame(2, Loan::whereNotNull('external_loan_no')->count());
    }

    /**
     * A blank Loan Product cell is staged as `""` and mapped under that key,
     * byte for byte. Looking it up with any re-normalised variant would miss and
     * fail the whole cohort.
     */
    public function test_a_blank_loan_product_maps_under_the_empty_string_key(): void
    {
        $this->seedForImport();

        $product = $this->salaryLoanProduct();
        $run = $this->makeRun(['product_mapping' => ['' => $product->id]]);

        $this->makeFile($run, 'customers', [$this->customerRow('A-001')]);
        $this->makeFile($run, 'loans', [$this->loanRow('A-001', 'L-1', ['loan_product' => ''])]);

        $this->runToIdle($run);

        $this->assertSame('completed', $run->fresh()->phase);
        $this->assertSame($product->id, Loan::where('external_loan_no', 'L-1')->firstOrFail()->loan_product_id);
    }

    /**
     * An imported member is admitted BY somebody, ON a date — and neither is
     * "now", and neither is nobody.
     *
     * `status = 'active'` while `approved_at`/`approved_by` are null is not a
     * gap in the record, it is a contradiction in it: the member is admitted and
     * nobody admitted them. It is also not a stable gap —
     * `registrations:backfill-approvals` fills `whereNull('approved_at')` with
     * `created_at` and a null approver, so leaving these blank lets a later
     * housekeeping run invent an admission dated to the night of the upload.
     */
    public function test_imported_members_carry_who_admitted_them_and_when(): void
    {
        $this->seedForImport();

        $run = $this->makeRun(['as_of_date' => '2026-06-30']);
        $this->makeFile($run, 'customers', [$this->customerRow('A-001')]);

        $this->runToIdle($run);

        $borrower = Borrower::where('external_account_no', 'A-001')->firstOrFail();

        $this->assertSame('active', $borrower->status);
        $this->assertSame($this->admin->id, $borrower->approved_by);

        // Midnight Manila on the date the EXTRACT represents, not the moment the
        // file happened to be uploaded. A file cut on the 30th and imported in
        // September must not date a decade-old membership to September.
        $this->assertNotNull($borrower->approved_at);
        $this->assertSame('2026-06-30 00:00:00', $borrower->approved_at->toDateTimeString());
        $this->assertTrue($borrower->approved_at->lt(now()->subDay()));

        // And the backfill command now skips them, because that is the whole
        // point of stamping rather than leaving it to housekeeping.
        $this->assertSame(0, Borrower::whereNotNull('external_account_no')->whereNull('approved_at')->count());
    }

    /**
     * A product whose bounds this fixture's default loan row breaks.
     *
     * 60,000.00 against a 50,000 ceiling, and the fixture's 6-month dates
     * against a 6-month product — so the CSV's own "Term in Months" is the only
     * thing that can push the term out of range, which is what makes the term
     * assertion below mean something.
     */
    private function narrowSalaryLoanProduct(): LoanProduct
    {
        return LoanProduct::factory()->create([
            'name' => 'Salary Loan',
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'min_term' => null,
            'max_term' => null,
            'min_interest_rate' => null,
            'frequency' => 'monthly',
            'penalty_rate' => 2.0,
            'grace_period_days' => 3,
            'min_amount' => 1000,
            'max_amount' => 50000,
        ]);
    }

    /**
     * A loan the importer writes past LoanService::createLoan()'s bounds is
     * recorded as having broken them.
     *
     * Bypassing the guard is deliberate — a migration has to carry a decade of
     * loans that today's product configuration would refuse, and refusing them
     * would strand the members they belong to. Pretending the guard passed is
     * not part of that bargain.
     *
     * Driven through the REAL ProductMappingResolver::boundsBreaches(), the same
     * function the mapping screen's "288 loans will disagree with their product"
     * forecast is computed from. It used to bind a double at a container key
     * while the two halves of this feature were on different branches; the
     * branches have met, the indirection is gone, and a double here would now
     * only be able to prove that the importer can call a closure.
     *
     * The two arguments that are easy to get wrong are pinned by CONSEQUENCE
     * rather than by inspecting arguments, which is the stronger test — see the
     * two assertions that name what the wrong value would have produced.
     */
    public function test_a_loan_outside_its_products_bounds_is_imported_and_flagged(): void
    {
        $this->seedForImport();

        $product = $this->narrowSalaryLoanProduct();
        $run = $this->makeRun(['product_mapping' => ['Salary Loan' => $product->id]]);

        $this->makeFile($run, 'customers', [$this->customerRow('A-001')]);
        $this->makeFile($run, 'loans', [$this->loanRow('A-001', 'L-1', ['term_in_months' => '18'])]);

        $this->runToIdle($run);

        // Imported, not refused.
        $loan = Loan::where('external_loan_no', 'L-1')->firstOrFail();

        $row = CsvImportRow::where('loan_id', $loan->id)->firstOrFail();
        $this->assertSame('imported', $row->result);
        $this->assertSame(CsvImportProcessor::CATEGORY_OUT_OF_PRODUCT_BOUNDS, $row->result_category);
        $this->assertStringContainsString('amount_above_max, term_above_max', (string) $row->result_message);
        $this->assertStringContainsString($product->name, (string) $row->result_message);

        /*
         * The principal is handed over as INTEGER CENTAVOS, because the resolver
         * divides by 100. Hand it pesos and 60,000.00 is compared as 600, which
         * is under the 1,000 floor — so `amount_below_min` appearing here, or
         * `amount_above_max` not appearing, is precisely the unit bug.
         */
        $this->assertStringNotContainsString('amount_below_min', (string) $row->result_message);

        /*
         * And the term is the CSV's stated "Term in Months" (18), never the
         * reconstructed period count. The product's ceiling is 6 and so is the
         * reconstruction — the fixture's dates are six months apart — so
         * `term_above_max` can ONLY have come from the CSV's own figure.
         */
        $this->assertSame(6, (int) $loan->term);
        $this->assertStringNotContainsString('term_below_min', (string) $row->result_message);
    }

    /**
     * A bounds breach OUTRANKS `imported_with_warnings`.
     *
     * Normalisation warnings are already on the row in `normalized.warnings` and
     * survive whatever category the row is filed under. A bounds breach has no
     * other trace anywhere, so filing it under the weaker category because the
     * row also happened to warn about a fee description is the one way to lose
     * it.
     */
    public function test_a_bounds_breach_outranks_a_warning_and_both_are_reported(): void
    {
        $this->seedForImport();

        $product = $this->narrowSalaryLoanProduct();
        $run = $this->makeRun(['product_mapping' => ['Salary Loan' => $product->id]]);

        $this->makeFile($run, 'customers', [$this->customerRow('A-001')]);
        // An unexplained other-fee is a normalisation warning, and the default
        // 60,000.00 is over this product's ceiling — so the row qualifies for
        // both categories at once, which is the whole point.
        $this->makeFile($run, 'loans', [$this->loanRow('A-001', 'L-1', [
            'other_fee_amount' => '250.00',
            'other_fee_detail' => '',
        ])]);

        $this->runToIdle($run);

        $row = CsvImportRow::whereNotNull('loan_id')->firstOrFail();

        $this->assertSame('imported', $row->result);
        $this->assertSame(CsvImportProcessor::CATEGORY_OUT_OF_PRODUCT_BOUNDS, $row->result_category);
        $this->assertNotSame('imported_with_warnings', $row->result_category);

        // Only the CATEGORY was outranked. The warning itself is still in the
        // message, alongside the breach.
        $this->assertStringContainsString('amount_above_max', (string) $row->result_message);
        $this->assertStringContainsString('no description', (string) $row->result_message);
    }

    /**
     * A loan inside every bound is not flagged, so the category means something.
     *
     * Without this, a resolver that returned a breach for everything — or a
     * call site that passed the wrong product — would look identical to a
     * working one in the two tests above.
     */
    public function test_a_loan_inside_its_products_bounds_is_not_flagged(): void
    {
        $this->seedForImport();

        $product = $this->salaryLoanProduct();
        $run = $this->makeRun(['product_mapping' => ['Salary Loan' => $product->id]]);

        $this->makeFile($run, 'customers', [$this->customerRow('A-001')]);
        $this->makeFile($run, 'loans', [$this->loanRow('A-001', 'L-1')]);

        $this->runToIdle($run);

        $row = CsvImportRow::whereNotNull('loan_id')->firstOrFail();

        $this->assertSame('imported', $row->result);
        $this->assertNotSame(CsvImportProcessor::CATEGORY_OUT_OF_PRODUCT_BOUNDS, $row->result_category);
        $this->assertSame(0, CsvImportRow::where('result_category', CsvImportProcessor::CATEGORY_OUT_OF_PRODUCT_BOUNDS)->count());
    }

    /**
     * One member's distinctive cells, so an assertion that they are absent is
     * about those cells and not about a common word.
     *
     * @return array<string, string>
     */
    private function distinctiveCells(int $seed = 5): array
    {
        return [
            'account_no' => "ACC-{$seed}7",
            'first_name' => 'Juanita',
            'last_name' => 'Santos',
            'birthdate' => '1979-11-03',
            'contact_number' => '09991112222',
            'email' => 'juanita.santos@coop.ph',
            'street_address' => '44 Rizal Ave',
            'employer_or_business' => 'Santos Sari-Sari',
        ];
    }

    /**
     * Arrange a REAL duplicate-key failure on `borrowers.borrower_code`.
     *
     * Not a thrown stub: the whole finding is about what a driver puts in a
     * message, so the message has to come from the driver. The setup is also a
     * real-world state rather than a contrivance — codes out of step with ids is
     * what a manual fix or an out-of-order migration leaves behind. The hooks
     * derive the next code from the HIGHEST ID, so with id 2 holding
     * BRW-000004, the next code issued is BRW-000005, which id 1 already holds.
     *
     * `$seed` keeps two arrangements in the same test out of each other's way:
     * the codes it plants must be unused, and the second call cannot re-plant
     * the first call's.
     *
     * @return array{CsvImportRun, CsvImportRow}
     */
    private function arrangeDuplicateBorrowerCode(int $seed = 5): array
    {
        $taken = sprintf('BRW-%06d', 900000 + $seed);
        $highest = sprintf('BRW-%06d', 900000 + $seed - 1);

        // Repair whatever a previous arrangement left behind first: the anchors
        // below are created through the hook, so they would trip over the last
        // sabotage before they could plant the next one.
        $previous = Borrower::query()->orderByDesc('id')->first();

        if ($previous !== null) {
            DB::table('borrowers')->where('id', $previous->id)
                ->update(['borrower_code' => sprintf('BRW-%06d', 990000 + $previous->id)]);
        }

        $first = Borrower::factory()->create(['branch_id' => $this->branch->id, 'first_name' => "Existing{$seed}", 'last_name' => 'Alpha']);
        $second = Borrower::factory()->create(['branch_id' => $this->branch->id, 'first_name' => "Existing{$seed}", 'last_name' => 'Beta']);

        DB::table('borrowers')->where('id', $first->id)->update(['borrower_code' => $taken]);
        DB::table('borrowers')->where('id', $second->id)->update(['borrower_code' => $highest]);

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'customers', [
            $this->customerRow("ACC-{$seed}7", array_merge($this->distinctiveCells($seed), [
                'monthly_income' => '44,000.00',
                'pledge_amount' => '12,500.00',
            ])),
        ]);

        (new CsvImportStager)->stage($file);

        return [$run, CsvImportRow::where('csv_import_file_id', $file->id)->orderBy('id')->firstOrFail()];
    }

    /**
     * A QueryException's message is the failing SQL WITH THE BINDINGS
     * SUBSTITUTED IN, so on this importer it is the member's entire record —
     * name, birthdate, address, contact number, employer, income — as one line
     * of text.
     *
     * This asserts the leak exists at the source, which is what makes the test
     * below mean something rather than merely pass. Everything after this point
     * is about that string never reaching anywhere it can be read.
     */
    public function test_the_driver_message_really_does_contain_the_members_record(): void
    {
        $this->seedForImport();

        [$run, $row] = $this->arrangeDuplicateBorrowerCode();

        try {
            DB::transaction(fn () => $this->processor()->writeChunk($run, [$row], CsvImportSchema::CUSTOMERS));
            $this->fail('The duplicate code did not fail, so nothing below this proves anything.');
        } catch (QueryException $e) {
            foreach ($this->distinctiveCells() as $key => $value) {
                $this->assertStringContainsString(
                    $value,
                    $e->getMessage(),
                    "The setup is wrong: [{$key}] is not in the driver message, so the test is not exercising the leak."
                );
            }
        }
    }

    /**
     * ...and none of it may be persisted, logged, or handed to a browser.
     *
     * `csv_import_rows.result_message` is rendered by the admin error screen and
     * streamed by `errors.csv`, so a driver message in that column returns the
     * member's own record to whoever opens the report. The log is worse: it is
     * the `single` channel — one file, never rotated, no scrubbing, mode 644 —
     * and a SYSTEMIC fault (a lock wait, a deadlock under the chunk's
     * lockForUpdate, a poisoned code sequence) fails every row it touches, so
     * the register arrives one member per line.
     *
     * Both call sites are exercised: the chunk attempt fails first, then the
     * row is retried on its own and fails again.
     */
    public function test_a_database_error_puts_no_cell_value_in_the_row_or_the_log(): void
    {
        $this->seedForImport();

        [$run, $row] = $this->arrangeDuplicateBorrowerCode();

        $entries = [];
        Event::listen(function (MessageLogged $message) use (&$entries): void {
            $entries[] = $message->message.' '.json_encode($message->context);
        });

        $this->runToIdle($run);

        $stamped = CsvImportRow::whereKey($row->id)->firstOrFail();

        $this->assertSame('failed', $stamped->result);
        $this->assertSame('exception', $stamped->result_category);

        // Fixed prose, keyed to the line the operator can find in their
        // spreadsheet, plus the driver's numeric code — 1062, duplicate entry.
        $this->assertSame(
            "Row {$stamped->line_number} could not be written (database error 1062). See the run log.",
            (string) $stamped->result_message,
        );

        foreach ($this->distinctiveCells() as $key => $value) {
            $this->assertStringNotContainsString(
                $value,
                (string) $stamped->result_message,
                "[{$key}] reached csv_import_rows.result_message, which the error screen renders and errors.csv streams."
            );

            foreach ($entries as $entry) {
                $this->assertStringNotContainsString(
                    $value,
                    $entry,
                    "[{$key}] reached the log. That is the `single` channel: one file, never rotated, world-readable."
                );
            }
        }

        // What DID reach the log is enough to tell a duplicate key from a lock
        // wait without opening a database.
        $logged = implode("\n", $entries);
        $this->assertStringContainsString('csv-import: chunk rolled back', $logged);
        $this->assertStringContainsString('csv-import: row failed and was isolated', $logged);
        $this->assertStringContainsString('"sql_state":"23000"', $logged);
        $this->assertStringContainsString('"driver_code":"1062"', $logged);
        $this->assertStringContainsString(class_basename(UniqueConstraintViolationException::class), $logged);
    }

    /**
     * The row stamp is fixed for NON-database failures too.
     *
     * A driver message is the worst case, not the only one: PHP's own exceptions
     * quote their input often enough (a malformed date, a bad JSON value) that
     * an allowlist of "safe" exception classes would be a standing invitation to
     * get one wrong. The column an HTTP endpoint streams carries no exception
     * text at all; the class goes to the log, where it belongs.
     */
    public function test_a_non_database_failure_is_stamped_with_fixed_prose_too(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'customers', [
            $this->customerRow('A-001', ['first_name' => 'Ana']),
            $this->customerRow('A-002', ['first_name' => 'Ben']),
        ]);

        (new CsvImportStager)->stage($file);

        $rows = CsvImportRow::where('csv_import_file_id', $file->id)->orderBy('id')->get();
        $this->poisonMoney($rows[1]);

        $entries = [];
        Event::listen(function (MessageLogged $message) use (&$entries): void {
            $entries[] = $message->message.' '.json_encode($message->context);
        });

        $this->runToIdle($run);

        $stamped = CsvImportRow::whereKey($rows[1]->id)->firstOrFail();

        $this->assertSame('failed', $stamped->result);
        $this->assertSame(
            "Row {$stamped->line_number} could not be written (unexpected error). See the run log.",
            (string) $stamped->result_message,
        );

        // The exception's own words are gone from the column and present in the
        // log only as the class that raised them.
        $this->assertStringNotContainsString('rather than a string', (string) $stamped->result_message);
        $this->assertStringContainsString('InvalidArgumentException', implode("\n", $entries));

        // And the row's neighbour still imported.
        $this->assertSame(1, Borrower::where('external_account_no', 'A-001')->count());
    }

    /**
     * The full message is not thrown away — it is moved somewhere that can hold
     * it safely, and that somewhere is off by default.
     *
     * The point of the switch is that the answer to "I need the real message" is
     * a channel with its own file, its own 0600 mode and its own retention,
     * rather than somebody quietly putting `$e->getMessage()` back into the
     * shared log where it would never rotate and never be scrubbed.
     */
    public function test_the_full_message_goes_to_the_restricted_channel_only_when_it_is_switched_on(): void
    {
        $this->seedForImport();

        [$run, $row] = $this->arrangeDuplicateBorrowerCode();

        // A real file, because "which channel did it go to" is the entire
        // question and every channel raises the same MessageLogged event.
        $path = storage_path('logs/testing-'.Str::random(12).'.log');
        config()->set('logging.channels.'.ImportErrorDigest::DIAGNOSTIC_CHANNEL, [
            'driver' => 'single',
            'path' => $path,
            'level' => 'debug',
        ]);
        Log::forgetChannel(ImportErrorDigest::DIAGNOSTIC_CHANNEL);

        try {
            config()->set('logging.csv_import_diagnostics', false);
            $this->runToIdle($run);

            $this->assertFileDoesNotExist($path, 'Diagnostics are off, so nothing may have been written at all.');

            config()->set('logging.csv_import_diagnostics', true);
            [$secondRun] = $this->arrangeDuplicateBorrowerCode(25);
            $this->runToIdle($secondRun);

            $this->assertFileExists($path);

            // Deliberately unredacted — that is what the switch is FOR. The
            // guarantee is the destination, not the contents.
            $this->assertStringContainsString('Juanita', (string) file_get_contents($path));
        } finally {
            Log::forgetChannel(ImportErrorDigest::DIAGNOSTIC_CHANNEL);
            @unlink($path);
        }
    }
}
