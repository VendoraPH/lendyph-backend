<?php

use App\Models\CreditApplicationProfile;
use App\Models\CreditScore;
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
});

/**
 * @param  array<string, mixed>  $score
 */
function expectFinancialsHidden(array $score): void
{
    $details = collect($score['factors'])->pluck('detail')->implode(' ');

    expect($score['financials_hidden'])->toBeTrue()
        ->and($score['capacity'])->toBeNull()
        ->and($score['data_used']['capacity'])->toBeNull()
        ->and($score['data_used']['application_profile_id'])->toBeNull()
        ->and($details)->not->toContain('monthly income (')
        ->and($details)->not->toContain('Disposable income of')
        ->and($details)->not->toContain('% of annual income')
        ->and($score['recommendation'])->not->toMatch('/suggested maximum payment of ₱/u');
}

it('hides the financial figures from a caller holding only credit_scoring:view', function () {
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

    $narrow = $this->actingAs($this->caller(['credit_scoring:view']))->getJson("/api/credit-scoring/borrowers/{$member->id}")->assertOk()->json('data');
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

it('hides the member\'s income on an assessment from a caller without the member\'s branch or borrowers:view', function () {
    $member = $this->member(['branch_id' => $this->farBranch()->id, 'monthly_income' => 35000]);
    $loan = $this->application($member, ['branch_id' => $this->mainBranch()->id]);
    $saved = $this->actingAs($this->headOffice())->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", [
        'other_income' => 5000, 'household_expenses' => 33000, 'outside_debt_payments' => 5000,
    ])->assertOk()->json('data');
    expect($saved['capacity']['monthly_income'])->toBe('40000.00')
        ->and($saved['score']['recommendation'])->toContain('suggested maximum payment of ₱');

    foreach ([$this->full, $this->caller(['credit_scoring:view', 'loans:view'])] as $caller) {
        $data = $this->actingAs($caller)->getJson("/api/credit-scoring/loans/{$loan->id}/assessment")->assertOk()->json('data');

        expect($data['capacity'])->toBeNull()
            ->and($data['application_profile']['household_expenses'])->toBe('33000.00');
        expectFinancialsHidden($data['score']);
    }
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

it('needs loans:view to save an assessment, as well as loans:update', function () {
    $loan = $this->application($this->member(['monthly_income' => 35000]));

    $this->actingAs($this->caller(['credit_scoring:view', 'loans:update']))
        ->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", ['household_expenses' => 1000])
        ->assertForbidden();

    expect(CreditApplicationProfile::query()->count())->toBe(0)
        ->and(CreditScore::query()->count())->toBe(0);
});
