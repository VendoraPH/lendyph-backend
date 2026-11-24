<?php

namespace Tests\Feature;

use App\Models\Borrower;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A member's date hired can't fall before their birthdate or after today
 * (finish run round 2, from S8): the member form and the CSV import refuse it,
 * and so does the API, on the staff create and edit and on the public
 * registration, which sends `date_hired` too. Both sides of the pair are
 * checked: the date hired against the birthdate, and the birthdate against
 * the date hired, each against the other as sent or, when an edit leaves it
 * out, as on file.
 */
class MemberDateHiredBirthdateTest extends TestCase
{
    use SetupLendyPH;

    private const BEFORE_BIRTHDATE = 'The date hired cannot be before the birthdate.';

    private const IN_THE_FUTURE = 'The date hired cannot be in the future.';

    private const AFTER_DATE_HIRED = 'The birthdate cannot be after the date hired.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        // Just after midnight in Manila, still the day before in UTC.
        Carbon::setTestNow(Carbon::parse('2026-11-24 00:30:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_staff_create_refuses_the_pair_the_wrong_way_round_on_both_fields(): void
    {
        $before = Borrower::count();

        $this->postJson('/api/borrowers', $this->staffMember(['birthdate' => '1990-05-12', 'date_hired' => '1990-05-11']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.date_hired', [self::BEFORE_BIRTHDATE])
            ->assertJsonPath('errors.birthdate', [self::AFTER_DATE_HIRED]);

        $this->assertSame($before, Borrower::count());

        $this->postJson('/api/borrowers', $this->staffMember(['birthdate' => '1990-05-12', 'date_hired' => '1990-05-12']))
            ->assertCreated()
            ->assertJsonPath('data.date_hired', '1990-05-12');
    }

    public function test_the_public_registration_refuses_a_date_hired_before_the_birthdate_or_after_today(): void
    {
        $this->app['auth']->forgetGuards();
        $before = Borrower::count();

        $this->postJson('/api/borrowers', $this->registration(['birthdate' => '1985-06-15', 'date_hired' => '1985-06-14']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.date_hired', [self::BEFORE_BIRTHDATE])
            ->assertJsonPath('errors.birthdate', [self::AFTER_DATE_HIRED]);

        $this->postJson('/api/borrowers', $this->registration(['date_hired' => '2026-11-25']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.date_hired', [self::IN_THE_FUTURE]);

        $this->assertSame($before, Borrower::count());

        $id = $this->postJson('/api/borrowers', $this->registration(['date_hired' => '2026-11-24']))
            ->assertCreated()
            ->json('data.id');
        $this->assertSame('2026-11-24', Borrower::findOrFail($id)->date_hired->toDateString());
    }

    public function test_an_edit_checks_the_birthdate_sent_or_else_the_one_on_file(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'birthdate' => '1990-05-12', 'date_hired' => '2015-06-01']);

        $this->putJson("/api/borrowers/{$member->id}", ['date_hired' => '1990-05-11'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.date_hired', [self::BEFORE_BIRTHDATE]);

        $this->putJson("/api/borrowers/{$member->id}", ['birthdate' => '1996-01-10', 'date_hired' => '1995-03-01'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.date_hired', [self::BEFORE_BIRTHDATE])
            ->assertJsonPath('errors.birthdate', [self::AFTER_DATE_HIRED]);

        $this->putJson("/api/borrowers/{$member->id}", ['date_hired' => '2026-11-25'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.date_hired', [self::IN_THE_FUTURE]);

        $this->assertSame('2015-06-01', $member->fresh()->date_hired->toDateString());

        $this->putJson("/api/borrowers/{$member->id}", ['birthdate' => '1980-02-02', 'date_hired' => '1999-08-01'])->assertOk();
        $this->assertSame('1999-08-01', $member->fresh()->date_hired->toDateString());

        $this->putJson("/api/borrowers/{$member->id}", ['date_hired' => '1980-02-02'])->assertOk();
        $this->assertSame('1980-02-02', $member->fresh()->date_hired->toDateString());
    }

    public function test_an_edit_refuses_a_birthdate_after_the_date_hired_sent_or_on_file(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'birthdate' => '1990-05-12', 'date_hired' => '2008-06-01']);

        $this->putJson("/api/borrowers/{$member->id}", ['birthdate' => '2008-07-01'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.birthdate', [self::AFTER_DATE_HIRED])
            ->assertJsonMissingValidationErrors(['date_hired']);
        $this->assertSame('1990-05-12', $member->fresh()->birthdate->toDateString());

        $this->putJson("/api/borrowers/{$member->id}", ['birthdate' => '2008-06-01'])->assertOk();
        $this->assertSame('2008-06-01', $member->fresh()->birthdate->toDateString());

        $this->putJson("/api/borrowers/{$member->id}", ['birthdate' => '2008-07-01', 'date_hired' => '2026-11-02'])->assertOk();
        $this->assertSame('2008-07-01', $member->fresh()->birthdate->toDateString());

        $this->putJson("/api/borrowers/{$member->id}", ['birthdate' => '2008-08-08', 'date_hired' => null])->assertOk();
        $this->assertSame('2008-08-08', $member->fresh()->birthdate->toDateString());
        $this->assertNull($member->fresh()->date_hired);
    }

    public function test_a_date_written_another_way_is_left_to_its_own_refusal(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'birthdate' => '1990-05-12', 'date_hired' => '2015-06-01']);

        $this->putJson("/api/borrowers/{$member->id}", ['birthdate' => '19960110', 'date_hired' => '1995-03-01'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.birthdate', ['The birthdate must be a date written as year-month-day, such as 1990-01-15.'])
            ->assertJsonMissingValidationErrors(['date_hired']);

        $this->putJson("/api/borrowers/{$member->id}", ['birthdate' => '1996-01-10', 'date_hired' => '19950301'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.date_hired', ['The date hired must be a date written as year-month-day, such as 2020-06-01.'])
            ->assertJsonMissingValidationErrors(['birthdate']);
    }

    /**
     * A complete staff create, as the member form sends it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function staffMember(array $overrides = []): array
    {
        return [
            'branch_id' => $this->branch->id,
            'first_name' => 'Teodora',
            'last_name' => 'Villanueva',
            'gender' => 'female',
            'civil_status' => 'single',
            'contact_number' => '09170000333',
            'address' => '12 Mabini St',
            'city' => 'Butuan',
            'province' => 'Agusan del Norte',
            'employer_or_business' => 'Butuan Trading',
            ...$overrides,
        ];
    }

    /**
     * A complete public registration, as the form sends it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function registration(array $overrides = []): array
    {
        return [
            'status' => 'pending',
            'branch_id' => $this->branch->id,
            'first_name' => 'Rosalinda',
            'last_name' => 'Macaraeg',
            'birthdate' => '1985-06-15',
            'gender' => 'female',
            'civil_status' => 'single',
            'contact_number' => '09171234567',
            'address' => '9 Rizal Ave',
            'city' => 'Butuan',
            'province' => 'Agusan del Norte',
            'employer_or_business' => 'Butuan Trading',
            ...$overrides,
        ];
    }
}
