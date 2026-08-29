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
use App\Services\CsvImport\ImportTick;
use Illuminate\Support\Facades\DB;
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
        $this->assertStringContainsString('rather than a string', (string) $stamped[1]->result_message);

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
}
