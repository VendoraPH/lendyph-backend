<?php

use App\Models\CreditApplicationProfile;
use App\Models\CreditPolicyFlag;
use App\Models\CreditScore;
use App\Models\CreditScoreDirtyMember;
use App\Models\CreditScoreMember;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\CreditScoring\ChangeScanner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Tests\Feature\CreditScoring\Engine\CreditFixtures;
use Tests\TestCase;

uses(TestCase::class, CreditFixtures::class);

beforeEach(function () {
    $this->pinToday();

    $this->member1 = $this->member(['monthly_income' => 40000]);
    $loan = $this->releasedLoan($this->member1);
    $this->paidMonthly($loan, '2026-05-01', 4);
    $this->period($loan, 5, '2026-09-20');
    $this->period($loan, 6, '2026-12-01');

    $this->member2 = $this->member();

    $product = LoanProduct::factory()->create(['interest_rate' => 2, 'term' => 6, 'frequency' => 'monthly']);
    $this->application = Loan::factory()->create([
        'borrower_id' => $this->member1->id,
        'branch_id' => $this->member1->branch_id,
        'loan_product_id' => $product->id,
        'status' => 'for_review',
        'principal_amount' => 12000,
        'interest_rate' => 2,
        'interest_method' => 'straight',
        'term' => 6,
        'frequency' => 'monthly',
        'start_date' => '2026-11-15',
        'maturity_date' => '2027-05-15',
        'created_by' => User::query()->value('id'),
    ]);
    CreditApplicationProfile::query()->create(['loan_id' => $this->application->id, 'household_expenses' => 15000]);
});

/**
 * Every column of every loan, schedule row and payment.
 *
 * @return array<string, mixed>
 */
function lendingRows(): array
{
    return [
        'loans' => DB::table('loans')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'amortization_schedules' => DB::table('amortization_schedules')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'repayments' => DB::table('repayments')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
    ];
}

it('scores every member and their open assessed applications', function () {
    $this->artisan('credit-scoring:recalculate')
        ->expectsOutputToContain('Scored 2 member(s)')
        ->assertSuccessful();

    expect(CreditScoreMember::query()->count())->toBe(2)
        ->and(CreditScoreMember::query()->where('borrower_id', $this->member2->id)->value('status'))->toBe('insufficient_data')
        ->and(CreditScore::query()->where('loan_id', $this->application->id)->where('context', 'assessment')->count())->toBe(1)
        ->and(CreditPolicyFlag::query()->where('loan_id', $this->application->id)->where('rule_key', 'notice_not_recorded')->open()->exists())->toBeTrue();
});

it('writes nothing on a dry run', function () {
    $this->artisan('credit-scoring:recalculate --dry-run')
        ->expectsOutputToContain('Dry run, nothing written. Checked 2 member(s): 2 would change')
        ->assertSuccessful();

    expect(CreditScore::query()->count())->toBe(0)
        ->and(CreditScoreMember::query()->count())->toBe(0)
        ->and(CreditPolicyFlag::query()->count())->toBe(0);
});

it('scores one member with --borrower, and refuses an unknown one', function () {
    $this->artisan('credit-scoring:recalculate', ['--borrower' => $this->member2->id])->assertSuccessful();

    expect(CreditScoreMember::query()->pluck('borrower_id')->all())->toBe([$this->member2->id]);

    $this->artisan('credit-scoring:recalculate', ['--borrower' => 999999])->assertFailed();
});

it('drops change flags raised before the nightly run', function () {
    app(ChangeScanner::class)->flag([$this->member1->id => 'Loan changed'], now()->subMinute());

    $this->artisan('credit-scoring:recalculate')->assertSuccessful();

    expect(CreditScoreDirtyMember::query()->count())->toBe(0);
});

it('leaves every loan, schedule and payment row identical through every command', function () {
    $before = lendingRows();

    $this->artisan('credit-scoring:recalculate --dry-run')->assertSuccessful();
    $this->artisan('credit-scoring:recalculate')->assertSuccessful();
    $this->travel(1)->minutes();
    app(ChangeScanner::class)->scan();
    $this->travel(1)->minutes();
    $this->member1->update(['monthly_income' => 5000]);
    $this->travel(10)->seconds();
    $this->artisan('credit-scoring:process-changes')->assertSuccessful();
    $this->artisan('credit-scoring:recalculate')->assertSuccessful();

    expect(CreditScore::query()->where('recalculation_reason', 'Member details changed')->exists())->toBeTrue()
        ->and(lendingRows())->toBe($before);
});

it('schedules the nightly run at 00:45 Manila and the minute job every minute in the background', function () {
    $events = collect(app(Schedule::class)->events());

    $nightly = $events->first(fn ($event) => str_contains($event->command, 'credit-scoring:recalculate'));
    $minute = $events->first(fn ($event) => str_contains($event->command, 'credit-scoring:process-changes'));

    expect($nightly->expression)->toBe('45 0 * * *')
        ->and($nightly->timezone)->toBe('Asia/Manila')
        ->and($nightly->withoutOverlapping)->toBeTrue()
        ->and($nightly->expiresAt)->toBe(120)
        ->and($minute->expression)->toBe('* * * * *')
        ->and($minute->runInBackground)->toBeTrue()
        ->and($minute->withoutOverlapping)->toBeTrue();
});
