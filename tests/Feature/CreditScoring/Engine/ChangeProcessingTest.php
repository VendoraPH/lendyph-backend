<?php

use App\Models\CreditScore;
use App\Models\CreditScoreDirtyMember;
use App\Models\CreditScoreMember;
use App\Models\CreditScoringCursor;
use App\Models\Repayment;
use App\Models\User;
use App\Services\CreditScoring\ChangeScanner;
use App\Services\CreditScoring\Recalculator;
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
    $this->travel(1)->minutes();
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
    $this->travel(1)->minutes();
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
