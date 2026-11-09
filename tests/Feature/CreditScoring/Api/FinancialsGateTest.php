<?php

use App\Models\CreditApplicationProfile;
use App\Models\CreditPolicyFlag;
use App\Models\CreditScore;
use App\Models\CreditScoreMember;
use App\Models\CreditScoringSetting;
use App\Models\Loan;
use App\Models\User;
use App\Services\CreditScoring\Api\SettingsEditor;
use Illuminate\Validation\ValidationException;
use Tests\Feature\CreditScoring\Api\CreditApiFixtures;
use Tests\TestCase;

/**
 * Security review M1: a member's income, expenses and debt payments, and
 * what is worked out from them, follow the personal-data gate. They show
 * only to a caller holding `loans:view` and `borrowers:view`, in the member's
 * branch, who can see the loan the application details came from.
 */
uses(TestCase::class, CreditApiFixtures::class);

beforeEach(function () {
    $this->pinToday();
    $this->full = $this->branchAnalyst();

    // A member whose current score was taken with application details from
    // a main-branch draft loan, with an amount past due.
    $this->memberWithFigures = function () {
        $member = $this->scoredMember();
        $loan = $this->application($member);
        CreditApplicationProfile::query()->create(['loan_id' => $loan->id, 'household_expenses' => 15000, 'outside_debt_payments' => 2000]);
        $member->update(['monthly_income' => 41000]);
        $this->period(Loan::query()->where('borrower_id', $member->id)->where('status', 'ongoing')->firstOrFail(), 4, '2026-09-01');
        $this->scoreNow($member);

        return $member;
    };
});

/**
 * @param  array<string, mixed>  $score
 */
function expectFinancialsHidden(array $score): void
{
    $details = collect($score['factors'])->pluck('detail')->implode(' ');

    expect($score['financials_hidden'])->toBeTrue()
        ->and($score['capacity'])->toBeNull()
        ->and($score['data_used'])->not->toHaveKeys(['capacity', 'application_profile_id', 'outstanding', 'past_due_amount', 'lendy_monthly_payments', 'requested_principal'])
        ->and($score['data_used'])->toHaveKeys(['loan_ids', 'active_loans', 'matured_periods', 'other_branch_loans'])
        ->and($details)->not->toContain('₱')
        ->and($details)->not->toContain('monthly income (')
        ->and($details)->not->toContain('Disposable income of')
        ->and($details)->not->toContain('% of annual income')
        ->and($score['recommendation'])->not->toMatch('/suggested maximum payment of ₱/u');
}

it('hides the financial figures from a caller who may read the member but not loans', function () {
    $member = $this->scoredMember();
    $loan = $this->application($member);
    CreditApplicationProfile::query()->create(['loan_id' => $loan->id, 'household_expenses' => 15000, 'outside_debt_payments' => 2000]);
    $member->update(['monthly_income' => 41000]);
    $this->period(Loan::query()->where('borrower_id', $member->id)->where('status', 'ongoing')->firstOrFail(), 4, '2026-09-01');
    $this->scoreNow($member);

    $full = $this->actingAs($this->full)->getJson("/api/credit-scoring/borrowers/{$member->id}")->assertOk()->json('data');
    expect($full['financials_hidden'])->toBeFalse()
        ->and($full['capacity']['monthly_income'])->toBe('41000.00')
        ->and($full['data_used']['application_profile_id'])->not->toBeNull();

    $narrow = $this->actingAs($this->caller(['credit_scoring:view', 'borrowers:view']))->getJson("/api/credit-scoring/borrowers/{$member->id}")->assertOk()->json('data');
    expectFinancialsHidden($narrow);
    expect($narrow['score'])->toBe($full['score'])
        ->and(collect($narrow['factors'])->firstWhere('label', 'Debt service ratio')['detail'])->toStartWith('Debt payments against monthly income.');
});

it('hides them when the application details came from a loan in another branch', function () {
    $member = $this->scoredMember();
    $farLoan = $this->application($member, ['branch_id' => $this->farBranch()->id]);
    CreditApplicationProfile::query()->create(['loan_id' => $farLoan->id, 'household_expenses' => 15000]);
    $member->update(['monthly_income' => 41000]);
    $this->period(Loan::query()->where('borrower_id', $member->id)->where('status', 'ongoing')->firstOrFail(), 4, '2026-09-01');
    $this->scoreNow($member);

    expectFinancialsHidden($this->actingAs($this->full)->getJson("/api/credit-scoring/borrowers/{$member->id}")->assertOk()->json('data'));

    $head = $this->actingAs($this->headOffice())->getJson("/api/credit-scoring/borrowers/{$member->id}")->json('data');
    expect($head['financials_hidden'])->toBeFalse()
        ->and($head['capacity']['household_expenses'])->toBe('15000.00');
});

it('refuses a weight or rate that isn\'t a plain decimal with 422, never 500', function (mixed $value) {
    $settingsUser = $this->caller(['credit_scoring:settings']);
    $config = $this->actingAs($settingsUser)->getJson('/api/credit-scoring/scorecard-config')->json('data');
    $config['categories'][0]['weight_percent'] = $value;

    $this->actingAs($settingsUser)->putJson('/api/credit-scoring/scorecard-config', $config)
        ->assertUnprocessable()->assertJsonValidationErrors('categories.0.weight_percent');

    foreach (['dsr_manageable_max', 'capacity_share'] as $field) {
        $this->actingAs($settingsUser)->putJson('/api/credit-scoring/settings', [$field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }
})->with([
    'plus sign' => '+30',
    'leading point' => '.5',
    'trailing point' => '30.',
    'binary noise' => 0.1 + 0.2,
]);

it('checks the manageable level against the caution level again under the lock', function () {
    expect(fn () => app(SettingsEditor::class)->save(User::query()->firstOrFail(), ['dsr_manageable_max' => 45]))
        ->toThrow(ValidationException::class);

    expect(CreditScoringSetting::current()->dsr_manageable_max)->toBe('30.0000');
});

it('hides them from a caller who lacks only loans:view', function () {
    $member = ($this->memberWithFigures)();

    $data = $this->actingAs($this->caller(['credit_scoring:view', 'credit_scoring:override', 'borrowers:view', 'loans:update']))
        ->getJson("/api/credit-scoring/borrowers/{$member->id}")->assertOk()->json('data');

    expectFinancialsHidden($data);
    expect(collect($data['factors'])->firstWhere('label', 'Past due amount')['detail'])->toStartWith('Past due amount against the amount outstanding.');

    $full = $this->actingAs($this->full)->getJson("/api/credit-scoring/borrowers/{$member->id}")->json('data');
    expect(collect($full['factors'])->firstWhere('label', 'Past due amount')['detail'])->toContain('₱')
        ->and($full['data_used'])->toHaveKeys(['outstanding', 'past_due_amount', 'lendy_monthly_payments']);
});

it('reads the debt flags generically when the figures are hidden, and in full with them', function () {
    $member = ($this->memberWithFigures)();
    $loan = Loan::query()->where('borrower_id', $member->id)->where('status', 'draft')->firstOrFail();
    $flag = fn (?int $loanId, string $key, string $detail) => CreditPolicyFlag::query()->create([
        'borrower_id' => $member->id, 'loan_id' => $loanId, 'rule_key' => $key, 'type' => 'soft_flag', 'label' => $key,
        'detail' => $detail, 'first_triggered_at' => now(), 'last_seen_at' => now(),
    ]);
    $flag(null, 'too_many_active_loans', 'The member has 4 active loans, outside lenders included; the policy limit is 2.');
    $flag($loan->id, 'debt_service_above_caution', 'The debt service ratio is 52.10%, above the caution level of 40%.');
    $noLoans = $this->caller(['credit_scoring:view', 'borrowers:view']);

    $memberFlags = collect($this->actingAs($noLoans)->getJson("/api/credit-scoring/borrowers/{$member->id}/policy-flags")->assertOk()->json('data.data'))->pluck('detail', 'label');
    $fullFlags = collect($this->actingAs($this->full)->getJson("/api/credit-scoring/loans/{$loan->id}/assessment")->json('data.policy_flags'))->pluck('detail', 'label');

    expect($memberFlags['too_many_active_loans'])->toBe('The member\'s active loans, outside lenders included, exceed the policy limit.')
        ->and($memberFlags->has('debt_service_above_caution'))->toBeFalse('A loan\'s own flag needs loans:view.')
        ->and($fullFlags['debt_service_above_caution'])->toContain('52.10%')
        ->and($fullFlags['too_many_active_loans'])->toContain('4 active loans');
});

it('keeps the debt flags generic when the newest details sit on another branch\'s loan and the score is unchanged', function () {
    $member = $this->scoredMember(null, ['monthly_income' => 10000]);
    $details = ['outside_lenders_count' => 3, 'outside_debt_payments' => 6000];
    CreditApplicationProfile::query()->create(['loan_id' => $this->application($member)->id] + $details);
    $this->scoreNow($member);
    $before = CreditScore::query()->where('borrower_id', $member->id)->count();
    $flags = fn () => collect($this->actingAs($this->full)->getJson("/api/credit-scoring/borrowers/{$member->id}/policy-flags")->assertOk()->json('data.data'))->pluck('detail', 'label');
    expect($flags()['Active loans above policy limit'])->toContain('4 active loans')
        ->and($flags()['Debt service ratio above caution'])->toContain('60.00%');

    // The same details on a newer loan in another branch: the score doesn't move.
    CreditApplicationProfile::query()->create(['loan_id' => $this->application($member, ['branch_id' => $this->farBranch()->id])->id] + $details);
    $this->scoreNow($member);

    $farProfile = CreditApplicationProfile::query()->latest('id')->first();
    expect(CreditScore::query()->where('borrower_id', $member->id)->count())->toBe($before)
        ->and(CreditScoreMember::query()->where('borrower_id', $member->id)->value('flags_profile_id'))->toBe($farProfile->id)
        ->and($flags()['Active loans above policy limit'])->not->toContain('4 active loans')
        ->and($flags()['Debt service ratio above caution'])->not->toContain('%,');
});

it('needs loans:view to save an assessment, as well as loans:update', function () {
    $loan = $this->application($this->member(['monthly_income' => 35000]));

    $this->actingAs($this->caller(['credit_scoring:view', 'loans:update']))
        ->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", ['household_expenses' => 1000])
        ->assertForbidden();

    expect(CreditApplicationProfile::query()->count())->toBe(0)
        ->and(CreditScore::query()->count())->toBe(0);
});
