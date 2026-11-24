<?php

namespace Tests\Feature;

use App\Models\Borrower;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Date Hired on the member form's Employment step (finish run round 2,
 * decision 15). HasBorrowerRules already takes `date_hired` on create
 * (BorrowerNewFieldsTest); this checks that the staff member update saves it,
 * clears it, and refuses one in the future or written another way.
 */
class MemberDateHiredUpdateTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-11 00:30:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_staff_member_update_saves_date_hired_and_clears_it(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'birthdate' => '1990-05-12']);

        $this->putJson("/api/borrowers/{$member->id}", ['date_hired' => '2015-06-01'])
            ->assertOk()
            ->assertJsonPath('data.date_hired', '2015-06-01');
        $this->assertSame('2015-06-01', $member->fresh()->date_hired->toDateString());

        $this->putJson("/api/borrowers/{$member->id}", ['date_hired' => '2026-11-11'])
            ->assertOk()
            ->assertJsonPath('data.date_hired', '2026-11-11');

        $this->putJson("/api/borrowers/{$member->id}", ['date_hired' => null])
            ->assertOk()
            ->assertJsonPath('data.date_hired', null);
        $this->assertNull($member->fresh()->date_hired);
    }

    public function test_the_update_refuses_a_date_hired_in_the_future_or_written_another_way(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'date_hired' => '2015-06-01']);

        $this->putJson("/api/borrowers/{$member->id}", ['date_hired' => '2026-11-12'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_hired' => 'The date hired cannot be in the future.']);

        $this->putJson("/api/borrowers/{$member->id}", ['date_hired' => '20150601'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_hired' => 'The date hired must be a date written as year-month-day, such as 2020-06-01.']);

        $this->assertSame('2015-06-01', $member->fresh()->date_hired->toDateString());
    }
}
