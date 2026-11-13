<?php

use App\Models\Repayment;
use Illuminate\Support\Facades\DB;
use Tests\Feature\CreditScoring\Engine\CreditFixtures;
use Tests\TestCase;

/**
 * A loan recovered from default (completed by a payment recorded while it
 * was defaulted, `repayments.loan_status_before`) ended badly: it scores the
 * Defaults and restructures factor like a default does and counts as a loan
 * that ended badly. It is not a loan defaulted now, so it raises no
 * `existing_default` hard flag and never reads "has a defaulted loan".
 */
uses(TestCase::class, CreditFixtures::class);

beforeEach(fn () => $this->pinToday());

it('scores a loan recovered from default like a default, with no existing_default flag and no "has" line', function () {
    $member = $this->member(['monthly_income' => 40000]);
    // Three periods paid, the last by a recovery recorded while the loan was defaulted.
    $loan = $this->releasedLoan($member, ['status' => 'completed']);
    $this->paidPeriod($loan, 1, '2026-05-01', '2026-05-01');
    $this->paidPeriod($loan, 2, '2026-06-01', '2026-06-01');
    $this->paidPeriod($loan, 3, '2026-07-01', '2026-09-15');
    DB::table('repayments')->where('id', Repayment::query()->where('loan_id', $loan->id)->max('id'))
        ->update(['loan_status_before' => 'defaulted']);

    $result = $this->scoreOf($member);
    $factor = $this->factorOf($result, 'defaults_restructures');

    expect($factor['points_earned'])->toBe(0)
        ->and($factor['raw_value']['defaulted'])->toBeTrue()
        ->and($factor['detail'])->toBe('The member had a loan in default (since paid).')
        ->and(collect($result['factors'])->pluck('detail')->all())->not->toContain('The member has a defaulted loan.')
        ->and(collect($result['flags'])->pluck('rule_key')->all())->not->toContain('existing_default')
        ->and($result['data_used']['defaulted_loans'])->toBe(0)
        ->and($result['data_used']['recovered_from_default_loans'])->toBe(1);
});

it('gives the same loan paid without a default the full points', function () {
    $member = $this->member(['monthly_income' => 40000]);
    $loan = $this->releasedLoan($member, ['status' => 'completed']);
    $this->paidPeriod($loan, 1, '2026-05-01', '2026-05-01');
    $this->paidPeriod($loan, 2, '2026-06-01', '2026-06-01');
    $this->paidPeriod($loan, 3, '2026-07-01', '2026-09-15');

    $result = $this->scoreOf($member);

    expect($this->factorOf($result, 'defaults_restructures')['points_earned'])->toBe(5)
        ->and($result['data_used']['recovered_from_default_loans'])->toBe(0);
});

it('scores a loan defaulted now exactly as before, counted once even with a recovery payment', function () {
    $member = $this->member(['monthly_income' => 40000]);
    $loan = $this->releasedLoan($member, ['status' => 'defaulted']);
    $this->paidPeriod($loan, 1, '2026-05-01', '2026-05-01');
    $this->period($loan, 2, '2026-06-01');
    // A part recovery: the loan is still defaulted.
    DB::table('repayments')->where('loan_id', $loan->id)->update(['loan_status_before' => 'defaulted']);

    $result = $this->scoreOf($member);
    $factor = $this->factorOf($result, 'defaults_restructures');

    expect($factor['points_earned'])->toBe(0)
        ->and($factor['detail'])->toBe('The member has a defaulted loan.')
        ->and(collect($result['flags'])->pluck('rule_key')->all())->toContain('existing_default')
        ->and(collect($result['flags'])->firstWhere('rule_key', 'existing_default')['detail'])->toBe('The member has 1 defaulted loan.')
        ->and($result['data_used']['defaulted_loans'])->toBe(1)
        ->and($result['data_used']['recovered_from_default_loans'])->toBe(0);
});
