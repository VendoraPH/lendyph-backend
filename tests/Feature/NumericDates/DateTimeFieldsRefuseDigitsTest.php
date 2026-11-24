<?php

namespace Tests\Feature\NumericDates;

use App\Models\Borrower;
use App\Models\PortalAnnouncement;
use App\Models\ReminderPause;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Portal\PortalTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The three date-time inputs (a portal announcement's start and end, an
 * application link's expiry, and a reminder pause's end) refuse a number and
 * bare digits, which Laravel's `date` rule reads as a date, and keep taking
 * the written forms the screens send: a date picker's day (link expiry, pause
 * end) and an ISO 8601 time with an offset (announcements), plus "Z" and a
 * datetime-local value.
 */
class DateTimeFieldsRefuseDigitsTest extends TestCase
{
    use PortalTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(Carbon::parse('2026-11-24 09:00', 'Asia/Manila'));
    }

    /**
     * @return array<string, array{int|float|string}>
     */
    public static function digits(): array
    {
        return [
            'a whole number' => [20261201],
            'a decimal' => [20261201.0],
            'digits as text' => ['20261201'],
            'a Unix timestamp' => [1796086800],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function writtenForms(): array
    {
        return [
            'a day' => ['2026-12-01'],
            'an offset time' => ['2026-12-01T09:30:00+08:00'],
            'a UTC time with milliseconds' => ['2026-12-01T01:30:00.000Z'],
            'a datetime-local value' => ['2026-12-01T09:30'],
            'a space-separated time' => ['2026-12-01 09:30:00'],
        ];
    }

    #[DataProvider('digits')]
    public function test_an_announcement_refuses_digits_for_its_start_and_end(int|float|string $value): void
    {
        $this->enablePortal();
        $before = PortalAnnouncement::query()->count();

        $this->announcement(['publish_at' => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['publish_at' => 'The start date must be a date written as year-month-day, such as 2026-11-24, with a time if needed, such as 2026-11-24T09:30.']);

        $this->announcement(['expires_at' => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expires_at');

        $this->assertSame($before, PortalAnnouncement::query()->count());
    }

    #[DataProvider('writtenForms')]
    public function test_an_announcement_takes_the_written_forms(string $value): void
    {
        $this->enablePortal();

        $this->announcement(['publish_at' => '2026-11-30T09:00:00+08:00', 'expires_at' => $value])->assertCreated();
    }

    #[DataProvider('digits')]
    public function test_an_application_link_refuses_digits_for_its_expiry(int|float|string $value): void
    {
        $this->link(['expires_at' => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['expires_at' => 'The expiry date must be a date written as year-month-day, such as 2026-11-24, with a time if needed, such as 2026-11-24T09:30.']);
    }

    #[DataProvider('writtenForms')]
    public function test_an_application_link_takes_the_written_forms(string $value): void
    {
        $this->link(['expires_at' => $value])->assertCreated();
    }

    #[DataProvider('digits')]
    public function test_a_reminder_pause_refuses_digits_for_its_end(int|float|string $value): void
    {
        $this->pause($value)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['until' => 'The resume date must be a date written as year-month-day, such as 2026-11-24, with a time if needed, such as 2026-11-24T09:30.']);

        $this->assertSame(0, ReminderPause::query()->count());
    }

    #[DataProvider('writtenForms')]
    public function test_a_reminder_pause_takes_the_written_forms(string $value): void
    {
        $this->pause($value)->assertCreated();
    }

    /** @param  array<string, mixed>  $dates */
    private function announcement(array $dates): TestResponse
    {
        return $this->postJson('/api/portal-admin/announcements', [
            'title' => 'Branch closed on Friday',
            'body' => 'Our branch is closed on Friday for the holiday.',
            'audience' => 'all',
            ...$dates,
        ]);
    }

    /** @param  array<string, mixed>  $fields */
    private function link(array $fields): TestResponse
    {
        config([
            'loan_origination.public_url' => 'https://apply.example.test/',
            'cors.allowed_origins' => ['https://app.example.test', 'https://apply.example.test'],
        ]);

        return $this->postJson('/api/loan-origination/links', [
            'name' => 'Facebook page', 'active' => true, 'branch_id' => null, 'loan_product_ids' => [],
            'display_name' => 'Binhs Coop', 'contact_phone' => '09171234567', 'contact_email' => 'loans@example.test',
            ...$fields,
        ]);
    }

    private function pause(int|float|string $until): TestResponse
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        return $this->postJson('/api/reminders/pause', ['scope' => 'borrower', 'borrower_id' => $member->id, 'reason' => 'Asked', 'until' => $until]);
    }
}
