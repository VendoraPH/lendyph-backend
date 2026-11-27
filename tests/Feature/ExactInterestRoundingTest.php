<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\CsvImport\CsvImportProcessor;
use App\Services\LoanService;
use App\Services\LoanTermSchedule;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Ledger\Release\ReleasesLoans;
use Tests\TestCase;
use Tests\Traits\BuildsCsvImports;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Follow-ups batch, decision 1. Each period's interest is amount × rate ×
 * days ÷ (100 × the rate period's days), worked out exactly and rounded half
 * up to the centavo, on every loan released from the change on, and on every
 * draft and preview. A float factor could land a hair below a half-centavo
 * and round it down: ₱48,334.50 at 1% a month for 10 days is exactly
 * ₱161.115, which the float path charged as ₱161.11.
 *
 * Every loan released before the change is marked `legacy`
 * (`loans.schedule_rounding`) and keeps the float rounding in every
 * recomputation: Ledger rebuilds, contract total, statement and extensions.
 */
class ExactInterestRoundingTest extends TestCase
{
    use BuildsCsvImports, PostsJournals, ReleasesLoans, SetupLendyPH;

    /** Diminishing, semi-monthly, 100 days at 1% a month: the last period is 10 days on ₱48,334.50. */
    private const TIE_PRODUCT = [
        'interest_rate' => 1,
        'interest_rate_frequency' => 'monthly',
        'interest_method' => 'diminishing',
        'term' => 100,
        'term_unit' => 'days',
        'frequency' => 'semi_monthly',
        'penalty_rate' => 2,
        'grace_period_days' => 3,
        'processing_fee' => 0,
        'service_fee' => 0,
        'notarial_fee' => 0,
        'min_amount' => 0,
        'max_amount' => 0,
    ];

    private const TIE_PRINCIPAL = 333333.33;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_interest_for_works_the_interest_out_exactly_and_rounds_a_half_centavo_up(): void
    {
        $this->assertSame('161.12', LoanTermSchedule::interestFor('48334.50', '1', 'monthly', 10));
        $this->assertSame('161.12', LoanTermSchedule::interestFor('48334.50', '12', 'annual', 10));
        $this->assertSame('1450.04', LoanTermSchedule::interestFor('48334.50', '3', 'monthly', 30));
        $this->assertSame('1800.00', LoanTermSchedule::interestFor('60000.00', '3', 'monthly', 30));
        $this->assertSame('42.86', LoanTermSchedule::interestFor('1000.00', '1', 'weekly', 30));
        $this->assertSame('0.00', LoanTermSchedule::interestFor('50000.00', '0', 'monthly', 30));

        // The float factor every loan before the change was charged with.
        $this->assertSame(161.11, round(48334.50 * LoanTermSchedule::rateForDays(1, 'monthly', 10), 2));
        $this->assertSame(1450.03, round(48334.50 * LoanTermSchedule::rateForDays(3, 'monthly', 30), 2));
    }

    public function test_a_new_schedule_rounds_the_tie_up_and_a_legacy_loan_keeps_it_down(): void
    {
        $exact = app(LoanService::class)->buildAmortizationPreview($this->stub());
        $legacy = app(LoanService::class)->buildAmortizationPreview($this->stub(['schedule_rounding' => 'legacy']));

        $this->assertCount(7, $exact);
        $this->assertSame(48334.50, $exact[5]['remaining_balance']);
        $this->assertSame(161.12, $exact[6]['interest_due']);
        $this->assertSame(161.11, $legacy[6]['interest_due']);
        $this->assertSame(array_slice($legacy, 0, 6), array_slice($exact, 0, 6), 'only the tie differs');
        $this->assertSame($legacy[6]['principal_due'], $exact[6]['principal_due']);
    }

    public function test_every_interest_method_uses_the_exact_figure_and_a_legacy_loan_the_float_one(): void
    {
        $service = app(LoanService::class);
        // Each charges ₱48,334.50 × 3% for one month = exactly ₱1,450.035.
        $shapes = [
            'straight' => ['interest_method' => 'straight', 'frequency' => 'monthly', 'term' => 1, 'term_unit' => 'months'],
            'diminishing' => ['interest_method' => 'diminishing', 'frequency' => 'monthly', 'term' => 1, 'term_unit' => 'months'],
            'upon maturity' => ['interest_method' => 'upon_maturity', 'frequency' => 'monthly', 'term' => 1, 'term_unit' => 'months'],
            'upon maturity, instalments' => ['interest_method' => 'upon_maturity', 'frequency' => 'monthly', 'term' => 2, 'term_unit' => 'months'],
            'single payment, months' => ['interest_method' => 'straight', 'frequency' => 'upon_maturity', 'term' => 1, 'term_unit' => 'months'],
            'single payment, days' => ['interest_method' => 'straight', 'frequency' => 'upon_maturity', 'term' => 30, 'term_unit' => 'days'],
        ];

        foreach ($shapes as $name => $shape) {
            $shape += ['principal_amount' => 48334.50, 'interest_rate' => 3];

            $this->assertSame(1450.04, $service->buildAmortizationPreview($this->stub($shape))[0]['interest_due'], $name);
            $this->assertSame(1450.03, $service->buildAmortizationPreview($this->stub($shape + ['schedule_rounding' => 'legacy']))[0]['interest_due'], $name);
        }
    }

    public function test_a_drafts_preview_equals_its_release_and_release_marks_the_loan_exact(): void
    {
        $loan = $this->tieDraft();
        $this->assertNull($loan->schedule_rounding);

        $preview = $this->getJson("/api/loans/{$loan->id}/amortization-preview")->assertOk()->json('data');

        $service = app(LoanService::class);
        $service->submitForReview($loan);
        $service->approve($loan, $this->admin, 'Approved for testing');
        $service->release($loan->fresh(), $this->admin);

        $loan->refresh();
        $saved = $loan->amortizationSchedules()->orderBy('period_number')->get();

        $this->assertSame('exact', $loan->schedule_rounding);
        $this->assertCount(count($preview), $saved);
        foreach ($preview as $i => $row) {
            $this->assertEquals($row['interest_due'], (float) $saved[$i]->interest_due, "period {$row['period_number']}");
            $this->assertEquals($row['total_due'], (float) $saved[$i]->total_due, "period {$row['period_number']}");
        }
        $this->assertEquals(161.12, (float) $saved->last()->interest_due);
    }

    public function test_the_migration_marks_every_saved_loan_legacy_and_runs_safely_twice(): void
    {
        $saved = [
            'released' => $this->createReleasedLoan(),
            'completed' => $this->createReleasedLoan(),
            'defaulted' => $this->createReleasedLoan(),
            'restructured' => $this->createReleasedLoan(),
            'imported' => $this->createReleasedLoan(),
        ];
        foreach (['completed', 'defaulted', 'restructured'] as $status) {
            DB::table('loans')->where('id', $saved[$status]->id)->update(['status' => $status]);
        }
        // As the CSV importer leaves one: ongoing, released on its own date by
        // no user, with its saved schedule and no LN number.
        DB::table('loans')->where('id', $saved['imported']->id)->update([
            'status' => 'ongoing', 'released_by' => null, 'loan_account_number' => null,
            'external_loan_no' => 'L-OLD-1', 'released_at' => '2025-06-01 00:00:00',
        ]);
        $drafts = [$this->tieDraft(), $this->tieDraft()];
        DB::table('loans')->where('id', $drafts[1]->id)->update(['status' => 'approved']);
        $migration = require database_path('migrations/2026_11_14_100000_add_schedule_rounding_to_loans.php');

        // As the loans stood before the change, then a first run that stopped
        // after adding the column, with a loan released since.
        DB::table('loans')->update(['schedule_rounding' => null]);
        $new = $this->createReleasedLoan();
        $this->assertSame('exact', $new->schedule_rounding);

        foreach ([1, 2] as $run) {
            $migration->up();

            foreach ($saved as $name => $loan) {
                $this->assertSame('legacy', $loan->fresh()->schedule_rounding, "run {$run}: {$name}");
            }
            foreach ($drafts as $draft) {
                $this->assertNull($draft->fresh()->schedule_rounding, "run {$run}: {$draft->status}");
            }
            $this->assertSame('exact', $new->fresh()->schedule_rounding, "run {$run}: released since");
        }
    }

    public function test_a_released_loan_with_no_marker_reads_as_legacy(): void
    {
        $loan = $this->createReleasedLoan(['product' => self::TIE_PRODUCT, 'principal_amount' => self::TIE_PRINCIPAL]);
        $this->makeLegacy($loan, null);
        $loan->refresh();

        $this->assertNull($loan->schedule_rounding);
        $this->assertFalse($loan->roundsInterestExactly());
        $this->assertFalse($loan->replicate()->roundsInterestExactly());
        $this->assertSame(161.11, app(LoanService::class)->buildAmortizationPreview($loan)[6]['interest_due']);

        // A saved schedule alone counts as released.
        DB::table('loans')->where('id', $loan->id)->update(['released_at' => null]);
        $this->assertFalse($loan->fresh()->roundsInterestExactly());

        // Never released: a draft and a preview are exact.
        $this->assertTrue($this->tieDraft()->roundsInterestExactly());
        $this->assertTrue($this->stub()->roundsInterestExactly());
    }

    public function test_a_released_loan_with_no_marker_keeps_its_figures_after_a_rebuild(): void
    {
        $source = $this->createReleasedLoan(['product' => self::TIE_PRODUCT, 'principal_amount' => self::TIE_PRINCIPAL]);
        $this->makeLegacy($source, null);
        $legacyInterest = $this->savedInterest($source);
        $before = $this->figures($source);

        $this->assertSame($legacyInterest, LoanService::toCentavos((float) $before['ledger_release']['interest']));

        $new = $this->approvedRestructureOf($source, $this->owed($source) / 100);
        $this->patchJson("/api/loans/{$new->id}/release")->assertOk();

        $this->assertNotNull($source->fresh()->restructured_at, 'setup: the source was rebuilt');
        $this->assertSame($before, $this->figures($source));
    }

    /**
     * A restructure or term extension applied to the loan itself builds its
     * new periods by the loan's own rounding, and a balance adjustment keeps
     * each open period's interest. ₱48,334.50 at 3% straight charges exactly
     * ₱1,450.035 a period: ₱1,450.03 on a legacy loan.
     */
    public function test_adjustments_applied_to_a_legacy_loan_keep_its_rounding(): void
    {
        foreach ([
            'restructure' => ['term' => 6],
            'term_extension' => ['additional_terms' => 2],
            'balance_adjustment' => ['adjustment_amount' => -1000],
        ] as $type => $newValues) {
            $loan = $this->createReleasedLoan(['principal_amount' => 48334.50]);
            $this->makeLegacy($loan);
            $before = $this->figures($loan);

            $this->assertEquals([1450.03], $this->openInterest($loan), "{$type}: setup");

            $id = $this->postJson("/api/loans/{$loan->id}/adjustments", [
                'adjustment_type' => $type,
                'new_values' => $newValues,
            ])->assertCreated()->json('data.id');
            $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
            $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk();

            $this->assertSame('legacy', $loan->fresh()->schedule_rounding, $type);
            $this->assertEquals([1450.03], $this->openInterest($loan), $type);
            $this->assertSame($before['ledger_release'], $this->figures($loan)['ledger_release'], $type);
        }
    }

    public function test_an_imported_loan_is_marked_exact(): void
    {
        LoanProduct::factory()->create([
            'name' => 'Salary Loan', 'interest_rate' => 3.0, 'interest_method' => 'straight', 'term' => 6,
            'frequency' => 'monthly', 'penalty_rate' => 3.0, 'grace_period_days' => 0, 'min_amount' => 1000, 'max_amount' => 1000000,
        ]);
        $this->seedForImport();
        $run = $this->makeRun(['product_mapping' => ['Salary Loan' => LoanProduct::where('name', 'Salary Loan')->value('id')]]);
        $this->makeFile($run, 'customers', [$this->customerRow('A-001')]);
        $this->makeFile($run, 'loans', [$this->loanRow('A-001', 'L-0001')]);

        $processor = new CsvImportProcessor;
        for ($i = 0; $i < 200 && ! $processor->advance($run)->idle; $i++) {
            $run->refresh();
        }

        $loan = Loan::where('external_loan_no', 'L-0001')->firstOrFail();

        $this->assertSame('exact', $loan->schedule_rounding);
        $this->assertTrue($loan->roundsInterestExactly());
    }

    public function test_a_legacy_loan_keeps_its_ledger_interest_contract_total_and_statement_after_a_rebuild(): void
    {
        $source = $this->createReleasedLoan(['product' => self::TIE_PRODUCT, 'principal_amount' => self::TIE_PRINCIPAL]);
        $exactInterest = $this->savedInterest($source);
        $this->makeLegacy($source);
        $legacyInterest = $this->savedInterest($source);

        $this->assertSame($exactInterest - 1, $legacyInterest, 'setup: the legacy schedule charges the tie a centavo less');

        $before = $this->figures($source);
        $this->assertSame($legacyInterest, LoanService::toCentavos((float) $before['ledger_release']['interest']));
        $this->assertSame(
            LoanService::toCentavos(self::TIE_PRINCIPAL) + $legacyInterest,
            LoanService::toCentavos((float) $before['contract_total']),
        );

        $new = $this->approvedRestructureOf($source, $this->owed($source) / 100);
        $this->patchJson("/api/loans/{$new->id}/release")->assertOk();

        $this->assertNotNull($source->fresh()->restructured_at, 'setup: the source was rebuilt');
        $this->assertSame('legacy', $source->fresh()->schedule_rounding);
        $this->assertSame('exact', $new->fresh()->schedule_rounding, 'a restructure is a new loan, released now');
        $this->assertSame($before, $this->figures($source));
    }

    public function test_extension_interest_follows_the_loans_marker_and_its_preview_equals_it(): void
    {
        $this->travelTo('2026-06-10 09:00:00');

        foreach (['exact' => 1450.04, 'legacy' => 1450.03] as $marker => $cycle) {
            $loan = $this->createReleasedLoan([
                'product' => ['interest_method' => 'upon_maturity', 'term' => 1, 'frequency' => 'monthly', 'interest_rate' => 3],
                'principal_amount' => 48334.50,
                'start_date' => '2026-05-27',
            ]);

            if ($marker === 'legacy') {
                $this->makeLegacy($loan);
            }

            $preview = $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option=defer")->assertOk()->json('data');

            $this->assertSame($cycle, (float) $preview['carry_interest'], "{$marker}: release interest");
            $this->assertSame($cycle, (float) $preview['fresh_interest'], "{$marker}: one cycle");

            $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])->assertOk();

            $newPeriod = $loan->amortizationSchedules()->orderByDesc('period_number')->first();

            $this->assertEquals($preview['new_interest_due'], (float) $newPeriod->interest_due, $marker);
            $this->assertEquals(round($cycle * 2, 2), (float) $newPeriod->interest_due, $marker);
        }
    }

    /** @param  array<string, mixed>  $overrides */
    private function stub(array $overrides = []): Loan
    {
        $fields = array_merge(self::TIE_PRODUCT, ['principal_amount' => self::TIE_PRINCIPAL, 'start_date' => '2026-01-15'], $overrides);
        $fields = array_intersect_key($fields, array_flip(['interest_rate', 'interest_rate_frequency', 'interest_method', 'term', 'term_unit', 'frequency', 'principal_amount', 'start_date', 'schedule_rounding']));
        $fields['maturity_date'] = app(LoanService::class)->maturityDateFor($fields['start_date'], (int) $fields['term'], $fields['term_unit'], $fields['frequency']);

        return (new Loan)->forceFill($fields);
    }

    private function tieDraft(): Loan
    {
        $product = LoanProduct::factory()->create(self::TIE_PRODUCT);

        return app(LoanService::class)->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $product->id,
            'principal_amount' => self::TIE_PRINCIPAL,
            'start_date' => now()->toDateString(),
            'deductions' => [],
        ], $this->admin)->fresh();
    }

    /**
     * As a loan released before the change stands: its saved schedule built by
     * the float path, and marked `legacy` (or, released by code older than the
     * marker, with none).
     */
    private function makeLegacy(Loan $loan, ?string $marker = 'legacy'): void
    {
        $legacy = $loan->fresh();
        $legacy->schedule_rounding = 'legacy';
        DB::table('loans')->where('id', $loan->id)->update(['schedule_rounding' => $marker]);

        foreach (app(LoanService::class)->buildAmortizationPreview($legacy) as $row) {
            DB::table('amortization_schedules')
                ->where('loan_id', $loan->id)
                ->where('period_number', $row['period_number'])
                ->update(['interest_due' => $row['interest_due'], 'total_due' => $row['total_due']]);
        }
    }

    private function owed(Loan $loan): int
    {
        return $loan->amortizationSchedules()->get()->sum(fn (AmortizationSchedule $s): int => LoanService::toCentavos((float) $s->total_due));
    }

    /** @return list<float> each distinct interest on the loan's open periods */
    private function openInterest(Loan $loan): array
    {
        return $loan->amortizationSchedules()
            ->whereIn('status', AmortizationSchedule::UNPAID_STATUSES)
            ->pluck('interest_due')
            ->map(fn (mixed $interest): float => (float) $interest)
            ->unique()
            ->values()
            ->all();
    }

    private function savedInterest(Loan $loan): int
    {
        return $loan->amortizationSchedules()->get()->sum(fn (AmortizationSchedule $s): int => LoanService::toCentavos((float) $s->interest_due));
    }

    /** @return array<string, mixed> */
    private function figures(Loan $loan): array
    {
        $show = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data');
        $ledger = $this->getJson("/api/loans/{$loan->id}/ledger")->assertOk()->json('data.rows');
        $statement = $this->getJson("/api/reports/statement-of-account/{$loan->id}")->assertOk()->json('data.transactions');

        $this->assertSame('release', $ledger[0]['kind']);

        return [
            'contract_total' => $show['contract_total'],
            'contract_interest' => $show['contract_interest'],
            'ledger_release' => $ledger[0],
            'statement_release' => $statement[0],
        ];
    }
}
