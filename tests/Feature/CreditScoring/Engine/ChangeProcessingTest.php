<?php

use App\Console\Commands\ProcessCreditScoreChanges;
use App\Models\CreditScore;
use App\Models\CreditScoreDirtyMember;
use App\Models\CreditScoreMember;
use App\Models\CreditScoringCursor;
use App\Models\Repayment;
use App\Models\User;
use App\Services\CreditScoring\ChangeScanner;
use App\Services\CreditScoring\PolicyFlags;
use App\Services\CreditScoring\Recalculator;
use App\Services\CreditScoring\Scorer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\CreditScoring\Engine\CreditFixtures;
use Tests\TestCase;

uses(TestCase::class, CreditFixtures::class);

beforeEach(fn () => $this->pinToday());

it('only sets the watermarks on its first scan', function () {
    $this->member();

    expect(app(ChangeScanner::class)->scan())->toBe(0)
        ->and(CreditScoringCursor::query()->whereNotNull('watermark')->count())->toBe(5)
        ->and(CreditScoreDirtyMember::query()->count())->toBe(0);
});

it('flags a member whose payment came after the watermark, and the minute job rescores them', function () {
    $member = $this->member(['monthly_income' => 40000]);
    $bystander = $this->member();
    $loan = $this->releasedLoan($member);
    $this->period($loan, 1, '2026-10-01');
    $this->travel(5)->minutes();
    app(ChangeScanner::class)->scan();

    $this->travel(1)->minutes();
    Repayment::factory()->create([
        'loan_id' => $loan->id,
        'payment_date' => now()->toDateString(),
        'received_by' => User::query()->value('id'),
    ]);
    $this->travel(10)->seconds();

    expect(app(ChangeScanner::class)->scan())->toBe(1);

    $dirty = CreditScoreDirtyMember::query()->sole();
    expect($dirty->borrower_id)->toBe($member->id)
        ->and($dirty->reason)->toBe('Payment recorded or changed');

    $this->artisan('credit-scoring:process-changes')->assertSuccessful();

    expect(CreditScoreDirtyMember::query()->count())->toBe(0)
        ->and(CreditScoreMember::query()->where('borrower_id', $member->id)->exists())->toBeTrue()
        ->and(CreditScoreMember::query()->where('borrower_id', $bystander->id)->exists())->toBeFalse()
        ->and(CreditScore::query()->where('borrower_id', $member->id)->sole()->recalculation_reason)->toBe('Payment recorded or changed');
});

it('waits a few seconds before reading a change, so a row written during a scan is not skipped', function () {
    $member = $this->member();
    $this->travel(5)->minutes();
    app(ChangeScanner::class)->scan();

    $this->travel(1)->minutes();
    $member->update(['monthly_income' => 25000]);

    expect(app(ChangeScanner::class)->scan())->toBe(0);

    $this->travel(ChangeScanner::LAG_SECONDS + 1)->seconds();

    expect(app(ChangeScanner::class)->scan())->toBe(1)
        ->and(CreditScoreDirtyMember::query()->sole()->reason)->toBe('Member details changed');
});

it('keeps a member flagged when a newer change arrives while they are being scored', function () {
    $member = $this->member(['monthly_income' => 40000]);
    $scanner = app(ChangeScanner::class);
    $scanner->flag([$member->id => 'Loan changed'], now()->subMinute());

    $this->artisan('credit-scoring:process-changes')->assertSuccessful();
    expect(CreditScoreDirtyMember::query()->count())->toBe(0);

    $scanner->flag([$member->id => 'Loan changed'], now()->addMinute());
    app(Recalculator::class)->clearDirty($member->id, now());

    expect(CreditScoreDirtyMember::query()->where('borrower_id', $member->id)->exists())->toBeTrue();
});

it('drops the flag of a registration that is not a member, without scoring it', function () {
    $pending = $this->member(['status' => 'pending', 'approved_at' => null]);
    app(ChangeScanner::class)->flag([$pending->id => 'Member details changed'], now()->subMinute());

    $this->artisan('credit-scoring:process-changes')->assertSuccessful();

    expect(CreditScoreDirtyMember::query()->count())->toBe(0)
        ->and(CreditScoreMember::query()->count())->toBe(0);
});

it('rescans an overlap window, so a change committed after a scan that it predates is still flagged', function () {
    $member = $this->member();
    $loan = $this->releasedLoan($member);
    $this->travel(1)->minutes();
    $scanner = app(ChangeScanner::class);
    $scanner->scan();
    $this->travel(1)->minutes();
    $scanner->scan();
    $watermark = CreditScoringCursor::query()->where('source', 'repayments')->value('watermark');

    // A long transaction stamped this row before the last scan, and only
    // committed after it.
    $late = Repayment::factory()->create(['loan_id' => $loan->id, 'received_by' => User::query()->value('id')]);
    DB::table('repayments')->where('id', $late->id)->update(['updated_at' => $watermark->copy()->subSeconds(60)]);
    $this->travel(1)->minutes();

    expect($scanner->scan())->toBe(1)
        ->and(CreditScoreDirtyMember::query()->sole()->borrower_id)->toBe($member->id);
});

it('does not reach back past the overlap window', function () {
    $member = $this->member();
    $loan = $this->releasedLoan($member);
    $this->travel(1)->minutes();
    $scanner = app(ChangeScanner::class);
    $scanner->scan();
    $this->travel(10)->minutes();
    $scanner->scan();
    $watermark = CreditScoringCursor::query()->where('source', 'repayments')->value('watermark');

    $old = Repayment::factory()->create(['loan_id' => $loan->id, 'received_by' => User::query()->value('id')]);
    DB::table('repayments')->where('id', $old->id)->update(['updated_at' => $watermark->copy()->subSeconds(ChangeScanner::OVERLAP_SECONDS + 30)]);
    $this->travel(1)->minutes();

    expect($scanner->scan())->toBe(0);
});

it('backs off a member whose scoring keeps failing, and scores the others', function () {
    $broken = $this->member(['monthly_income' => 40000]);
    $fine = $this->member(['monthly_income' => 30000]);
    app()->instance(Recalculator::class, failingRecalculatorFor($broken->id));
    Log::spy();

    $scanner = app(ChangeScanner::class);
    $scanner->flag([$broken->id => 'Loan changed'], now()->subMinutes(2));
    $scanner->flag([$fine->id => 'Loan changed'], now()->subMinute());

    $this->artisan('credit-scoring:process-changes')->assertFailed();

    $dirty = CreditScoreDirtyMember::query()->where('borrower_id', $broken->id)->sole();
    expect(CreditScoreMember::query()->where('borrower_id', $fine->id)->exists())->toBeTrue()
        ->and(CreditScoreDirtyMember::query()->where('borrower_id', $fine->id)->exists())->toBeFalse()
        ->and($dirty->attempts)->toBe(1)
        ->and($dirty->flagged_at->toDateTimeString())->toBe(now()->addMinutes(2)->toDateTimeString());

    // Not retried before its 2 minutes are up.
    $this->travel(1)->minutes();
    $this->artisan('credit-scoring:process-changes')->assertSuccessful();
    expect(CreditScoreDirtyMember::query()->where('borrower_id', $broken->id)->value('attempts'))->toBe(1);

    $this->travel(1)->minutes();
    $this->artisan('credit-scoring:process-changes')->assertFailed();

    $dirty = CreditScoreDirtyMember::query()->where('borrower_id', $broken->id)->sole();
    expect($dirty->attempts)->toBe(2)
        ->and($dirty->flagged_at->toDateTimeString())->toBe(now()->addMinutes(4)->toDateTimeString());
    Log::shouldHaveReceived('warning')->with('Credit scoring failed for a member.', ['borrower_id' => $broken->id, 'attempts' => 2]);
});

it('caps the back-off at 60 minutes', function () {
    $broken = $this->member(['monthly_income' => 40000]);
    app()->instance(Recalculator::class, failingRecalculatorFor($broken->id));
    app(ChangeScanner::class)->flag([$broken->id => 'Loan changed'], now()->subMinute());
    CreditScoreDirtyMember::query()->where('borrower_id', $broken->id)->update(['attempts' => 4]);
    Log::spy();

    $this->artisan('credit-scoring:process-changes')->assertFailed();

    $dirty = CreditScoreDirtyMember::query()->where('borrower_id', $broken->id)->sole();
    expect($dirty->attempts)->toBe(5)
        ->and($dirty->flagged_at->toDateTimeString())->toBe(now()->addMinutes(32)->toDateTimeString());

    expect(ProcessCreditScoreChanges::retryDelayMinutes(6))->toBe(60)
        ->and(ProcessCreditScoreChanges::retryDelayMinutes(1))->toBe(2);
});

it('leaves a member to the nightly run after 5 failed attempts', function () {
    $broken = $this->member(['monthly_income' => 40000]);
    $counter = new stdClass;
    app()->instance(Recalculator::class, failingRecalculatorCounting([$broken->id], $counter));
    app(ChangeScanner::class)->flag([$broken->id => 'Loan changed'], now()->subMinute());
    CreditScoreDirtyMember::query()->where('borrower_id', $broken->id)->update(['attempts' => ProcessCreditScoreChanges::MAX_ATTEMPTS]);
    Log::spy();

    $this->artisan('credit-scoring:process-changes')->assertSuccessful();

    expect($counter->calls)->toBe(0)
        ->and(CreditScoreDirtyMember::query()->where('borrower_id', $broken->id)->value('attempts'))->toBe(ProcessCreditScoreChanges::MAX_ATTEMPTS);

    app()->forgetInstance(Recalculator::class);
    $this->artisan('credit-scoring:recalculate', ['--borrower' => $broken->id])->assertSuccessful();

    expect(CreditScoreDirtyMember::query()->where('borrower_id', $broken->id)->exists())->toBeFalse()
        ->and(CreditScoreMember::query()->where('borrower_id', $broken->id)->exists())->toBeTrue();
});

it('scores a new change while 250 members keep failing', function () {
    $failing = collect(range(1, 250))->map(fn () => $this->member()->id)->all();
    $scanner = app(ChangeScanner::class);
    $scanner->flag(array_fill_keys($failing, 'Loan changed'), now()->subMinutes(10));
    app()->instance(Recalculator::class, failingRecalculatorFor(...$failing));
    Log::spy();

    $this->artisan('credit-scoring:process-changes')->assertFailed();

    $newcomer = $this->member(['monthly_income' => 40000]);
    $scanner->flag([$newcomer->id => 'Payment recorded or changed'], now());
    $this->travel(1)->minutes();
    $this->artisan('credit-scoring:process-changes')->assertFailed();

    expect(CreditScoreMember::query()->where('borrower_id', $newcomer->id)->exists())->toBeTrue()
        ->and(CreditScoreDirtyMember::query()->where('borrower_id', $newcomer->id)->exists())->toBeFalse()
        ->and(CreditScoreDirtyMember::query()->whereIn('borrower_id', $failing)->where('attempts', 1)->count())->toBe(250);

    // A third tick, still inside every failing member's back-off, scores nothing and tries none of them.
    $this->travel(30)->seconds();
    $this->artisan('credit-scoring:process-changes')->assertSuccessful();
    expect(CreditScoreDirtyMember::query()->whereIn('borrower_id', $failing)->where('attempts', '>', 1)->count())->toBe(0);
});

it('rescores a member flagged again while being scored on the next tick', function () {
    $member = $this->member(['monthly_income' => 40000]);
    $scanner = app(ChangeScanner::class);
    $scanner->flag([$member->id => 'Loan changed'], now()->subMinute());
    app()->instance(Recalculator::class, reflaggingRecalculator());

    $this->artisan('credit-scoring:process-changes')->assertSuccessful();

    expect(CreditScoreDirtyMember::query()->where('borrower_id', $member->id)->value('reason'))->toBe('Payment recorded or changed');

    app()->forgetInstance(Recalculator::class);
    $this->travel(1)->minutes();
    $this->artisan('credit-scoring:process-changes')->assertSuccessful();

    expect(CreditScoreDirtyMember::query()->count())->toBe(0)
        ->and(CreditScoreMember::query()->where('borrower_id', $member->id)->sole()->checked_at->gte(now()->startOfSecond()))->toBeTrue();
});

function failingRecalculatorFor(int ...$borrowerIds): Recalculator
{
    return failingRecalculatorCounting($borrowerIds);
}

/**
 * @param  list<int>  $borrowerIds
 */
function failingRecalculatorCounting(array $borrowerIds, ?stdClass $counter = null): Recalculator
{
    return new class(app(Scorer::class), app(PolicyFlags::class), $borrowerIds, $counter ?? new stdClass) extends Recalculator
    {
        /**
         * @param  list<int>  $failingIds
         */
        public function __construct(Scorer $scorer, PolicyFlags $flags, private readonly array $failingIds, private readonly stdClass $counter)
        {
            parent::__construct($scorer, $flags);
            $this->counter->calls ??= 0;
        }

        public function scoreMember(int $borrowerId, string $reason, bool $dryRun = false, ?Carbon\Carbon $asOf = null): array
        {
            if (in_array($borrowerId, $this->failingIds, true)) {
                $this->counter->calls++;

                throw new RuntimeException('Scoring failed for a test member.');
            }

            return parent::scoreMember($borrowerId, $reason, $dryRun, $asOf);
        }
    };
}

function reflaggingRecalculator(): Recalculator
{
    return new class(app(Scorer::class), app(PolicyFlags::class)) extends Recalculator
    {
        public function scoreMember(int $borrowerId, string $reason, bool $dryRun = false, ?Carbon\Carbon $asOf = null): array
        {
            $outcome = parent::scoreMember($borrowerId, $reason, $dryRun, $asOf);
            app(ChangeScanner::class)->flag([$borrowerId => 'Payment recorded or changed'], now()->addSecond());

            return $outcome;
        }
    };
}
