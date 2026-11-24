<?php

use Tests\Feature\Phase6\LoanOrigination\OriginationFixtures;
use Tests\TestCase;

/*
 * The staff application and its assessment send the term's unit beside the
 * term (`term_unit`, months or days, as the loan product stores it), so the
 * screens write "90 days" without reading the loan products (finish run
 * round 2, polish). An application with no product yet has no unit.
 */
uses(TestCase::class, OriginationFixtures::class);

beforeEach(fn () => $this->pinOriginationToday());

it('sends the loan product\'s term unit on the application list row and detail', function () {
    $days = $this->originationProduct(['term_unit' => 'days', 'term' => 90, 'min_term' => 30, 'max_term' => 180, 'frequency' => 'weekly']);
    $application = $this->originationApplication('submitted', $days, [], ['loan' => ['term' => '90', 'frequency' => 'weekly']]);
    $staff = $this->originationStaff('loan_origination:view');

    $this->actingAs($staff)->getJson('/api/loan-origination/applications')->assertOk()
        ->assertJsonPath('data.0.id', $application->id)
        ->assertJsonPath('data.0.requested_term', 90)
        ->assertJsonPath('data.0.term_unit', 'days');

    $this->actingAs($staff)->getJson("/api/loan-origination/applications/{$application->id}")->assertOk()
        ->assertJsonPath('data.requested_term', 90)
        ->assertJsonPath('data.term_unit', 'days');
});

it('reads months for a product in months, and no unit while no product is chosen', function () {
    $months = $this->originationApplication('submitted');
    $none = $this->originationApplication('submitted', null, ['loan_product_id' => null, 'requested_term' => null]);
    $staff = $this->originationStaff('loan_origination:view');

    $this->actingAs($staff)->getJson("/api/loan-origination/applications/{$months->id}")->assertOk()
        ->assertJsonPath('data.term_unit', 'months');
    $this->actingAs($staff)->getJson("/api/loan-origination/applications/{$none->id}")->assertOk()
        ->assertJsonPath('data.loan_product_id', null)
        ->assertJsonPath('data.term_unit', null);
});

it('sends the term unit on the assessment, its history, the assessments list and the application\'s current assessment', function () {
    $days = $this->originationProduct(['term_unit' => 'days', 'term' => 90, 'min_term' => 30, 'max_term' => 180, 'frequency' => 'weekly']);
    $application = $this->originationApplication('under_assessment', $days, [], ['loan' => ['term' => '90', 'frequency' => 'weekly']]);
    $staff = $this->originationStaff('loan_origination:view', 'loan_origination:assess');
    $url = "/api/loan-origination/applications/{$application->id}/assessment";

    $this->actingAs($staff)->postJson("{$url}/rerun", ['verified_monthly_income' => '40000', 'verified_debt_payments' => '0'])->assertCreated()
        ->assertJsonPath('data.requested_term', 90)
        ->assertJsonPath('data.term_unit', 'days');

    $this->actingAs($staff)->getJson($url)->assertOk()->assertJsonPath('data.term_unit', 'days');
    $this->actingAs($staff)->getJson("{$url}/history")->assertOk()->assertJsonPath('data.0.term_unit', 'days');
    $this->actingAs($staff)->getJson('/api/loan-origination/assessments')->assertOk()->assertJsonPath('data.0.term_unit', 'days');
    $this->actingAs($staff)->getJson("/api/loan-origination/applications/{$application->id}")->assertOk()
        ->assertJsonPath('data.assessment.term_unit', 'days');
});
