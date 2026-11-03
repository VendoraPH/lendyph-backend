<?php

namespace Tests\Feature\R7Integrity;

use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /dashboard/daily-dues validates `date` (round r7, decision 3): a value
 * that isn't a Y-m-d date answers 422 on `date`, never a 500 from deep inside
 * the dashboard service.
 */
class DailyDuesDateValidationTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_a_date_that_is_not_a_date_answers_422(): void
    {
        $this->getJson('/api/dashboard/daily-dues?date=foo')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }

    public function test_a_date_sent_as_an_array_answers_422(): void
    {
        $this->getJson('/api/dashboard/daily-dues?date[]=x')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }

    public function test_a_date_in_another_format_answers_422(): void
    {
        $this->getJson('/api/dashboard/daily-dues?date=15/04/2026')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }

    public function test_a_valid_date_and_no_date_still_answer_200(): void
    {
        $this->getJson('/api/dashboard/daily-dues?date=2026-04-15')->assertOk();
        $this->getJson('/api/dashboard/daily-dues')->assertOk();
    }
}
