<?php

use App\Models\CreditScorecardRevision;
use App\Models\CreditScoringSetting;
use App\Services\CreditScoring\FactorTable;
use App\Services\CreditScoring\ScorecardV1;
use Tests\TestCase;

uses(TestCase::class);

function v1Factor(string $key): array
{
    $table = FactorTable::fromRevision(CreditScorecardRevision::query()->where('version', '1.0')->firstOrFail());

    return collect($table->factors())->firstWhere('key', $key);
}

function v1Points(string $key, int|float $value, bool $defaulted = false): int
{
    $table = FactorTable::fromRevision(CreditScorecardRevision::query()->where('version', '1.0')->firstOrFail());

    return $table->points(v1Factor($key), $value, $defaulted);
}

it('seeds revision 1.0 with the task weights, unvalidated, and points the settings at it', function () {
    $revision = CreditScorecardRevision::query()->where('version', '1.0')->firstOrFail();

    expect($revision->validated)->toBeFalse()
        ->and(collect($revision->categories)->pluck('weight_percent', 'key')->all())
        ->toBe(['repayment' => 35, 'capacity' => 25, 'exposure' => 15, 'stability' => 10, 'relationship' => 10, 'application' => 5])
        ->and(collect($revision->factors)->sum('points'))->toBe(100)
        ->and(collect($revision->risk_bands)->pluck('min_score')->all())->toBe([90, 80, 70, 60, 40, 0])
        ->and(CreditScoringSetting::current()->current_revision_id)->toBe($revision->id);

    foreach (ScorecardV1::categories() as $category) {
        expect(collect($revision->factors)->where('category_key', $category['key'])->sum('points'))->toBe($category['weight_percent']);
    }
});

it('scores every factor band', function (string $key, int|float $value, int $points, bool $defaulted = false) {
    expect(v1Points($key, $value, $defaulted))->toBe($points);
})->with([
    'on-time 100%' => ['on_time_rate', 100, 15],
    'on-time 95%' => ['on_time_rate', 95, 15],
    'on-time 94.99%' => ['on_time_rate', 94.99, 12],
    'on-time 90%' => ['on_time_rate', 90, 12],
    'on-time 80%' => ['on_time_rate', 80, 8],
    'on-time 70%' => ['on_time_rate', 70, 4],
    'on-time 69.9%' => ['on_time_rate', 69.9, 0],
    'worst late 0' => ['worst_days_late', 0, 8],
    'worst late 1' => ['worst_days_late', 1, 6],
    'worst late 7' => ['worst_days_late', 7, 6],
    'worst late 8' => ['worst_days_late', 8, 3],
    'worst late 30' => ['worst_days_late', 30, 3],
    'worst late 31' => ['worst_days_late', 31, 1],
    'worst late 60' => ['worst_days_late', 60, 1],
    'worst late 61' => ['worst_days_late', 61, 0],
    'dpd 0' => ['days_past_due', 0, 7],
    'dpd 1' => ['days_past_due', 1, 4],
    'dpd 7' => ['days_past_due', 7, 4],
    'dpd 8' => ['days_past_due', 8, 2],
    'dpd 30' => ['days_past_due', 30, 2],
    'dpd 31' => ['days_past_due', 31, 0],
    'no restructures' => ['defaults_restructures', 0, 5],
    'one restructure' => ['defaults_restructures', 1, 3],
    'two restructures' => ['defaults_restructures', 2, 1],
    'five restructures' => ['defaults_restructures', 5, 1],
    'a default' => ['defaults_restructures', 0, 0, true],
    'dsr 20%' => ['debt_service_ratio', 20, 15],
    'dsr 25%' => ['debt_service_ratio', 25, 12],
    'dsr 30%' => ['debt_service_ratio', 30, 12],
    'dsr 40%' => ['debt_service_ratio', 40, 8],
    'dsr 50%' => ['debt_service_ratio', 50, 4],
    'dsr 50.01%' => ['debt_service_ratio', 50.01, 0],
    'cover 3x' => ['payment_cover', 3, 10],
    'cover 2x' => ['payment_cover', 2, 7],
    'cover 1.5x' => ['payment_cover', 1.5, 5],
    'cover 1x' => ['payment_cover', 1, 2],
    'cover 0.99x' => ['payment_cover', 0.99, 0],
    'past due 0%' => ['past_due_amount', 0, 6],
    'past due 10%' => ['past_due_amount', 10, 3],
    'past due 10.5%' => ['past_due_amount', 10.5, 0],
    'active 0' => ['active_loans', 0, 4],
    'active 1' => ['active_loans', 1, 4],
    'active 2' => ['active_loans', 2, 2],
    'active 3' => ['active_loans', 3, 0],
    'debt/income 25%' => ['debt_to_income', 25, 5],
    'debt/income 50%' => ['debt_to_income', 50, 3],
    'debt/income 100%' => ['debt_to_income', 100, 1],
    'debt/income 101%' => ['debt_to_income', 101, 0],
    'tenure 5y' => ['tenure', 5, 6],
    'tenure 2y' => ['tenure', 2, 4],
    'tenure 1y' => ['tenure', 1, 2],
    'tenure 0.5y' => ['tenure', 0.5, 1],
    'residence 5y' => ['residence', 5, 4],
    'residence 2y' => ['residence', 2, 3],
    'residence 1y' => ['residence', 1, 1],
    'residence 0.5y' => ['residence', 0.5, 0],
    'membership 3y' => ['membership_tenure', 3, 4],
    'membership 1y' => ['membership_tenure', 1, 3],
    'membership 0.5y' => ['membership_tenure', 0.5, 1],
    'completed 3' => ['completed_loans', 3, 4],
    'completed 2' => ['completed_loans', 2, 3],
    'completed 1' => ['completed_loans', 1, 2],
    'completed 0' => ['completed_loans', 0, 0],
    'clean 2' => ['clean_completed_loans', 2, 2],
    'clean 1' => ['clean_completed_loans', 1, 1],
    'clean 0' => ['clean_completed_loans', 0, 0],
    'valid id' => ['valid_id', 1, 2],
    'no valid id' => ['valid_id', 0, 0],
    'valid mobile' => ['valid_mobile', 1, 1],
    'no valid mobile' => ['valid_mobile', 0, 0],
    'income verified' => ['income_verified', 1, 1],
    'income not verified' => ['income_verified', 0, 0],
    'two references' => ['verified_references', 2, 1],
    'one reference' => ['verified_references', 1, 0],
]);
