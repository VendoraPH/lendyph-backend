<?php

namespace Tests\Feature\Phase6\Amla;

use App\Exceptions\AmlaRecordIsAppendOnlyException;
use App\Models\AmlaCalendarEntry;
use App\Models\AmlaCalendarYear;
use App\Models\AmlaChangeRequest;
use App\Models\User;
use App\Services\Amla\Foundation\Deadlines;
use App\Services\Amla\Foundation\WorkingDayCalendar;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The AMLA working-day calendar and deadlines (AMLA design §4.15): holidays,
 * AMLC non-reporting days and facility suspensions, each with its source and
 * approver, are not working days, and neither are Saturdays and Sundays. A
 * CTR is due within 5 working days and an STR by the next working day (both
 * "to verify"), counted from the day after. A deadline that reaches a year
 * whose calendar isn't confirmed is provisional. Every calendar change applies
 * only once a second `amla:settings` holder approves it.
 */
class AmlaCalendarTest extends TestCase
{
    use AmlaTestHelpers, SetupLendyPH;

    private User $maker;

    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->maker = $this->settingsHolder();
        $this->checker = $this->settingsHolder();
    }

    /** @param  array<string, mixed>  $body */
    private function approved(array $body): array
    {
        $id = $this->actingAs($this->maker)->postJson('/api/amla/change-requests', ['reason' => 'Official proclamation.', ...$body])
            ->assertCreated()->json('data.id');

        return $this->actingAs($this->checker)->postJson("/api/amla/change-requests/{$id}/approve")->assertOk()->json('data');
    }

    private function holiday(string $date, string $kind = 'holiday'): void
    {
        $this->approved(['type' => 'calendar_add', 'date' => $date, 'kind' => $kind, 'name' => 'Day '.$date, 'source' => 'Proclamation No. 1']);
    }

    public function test_weekends_and_approved_entries_are_not_working_days(): void
    {
        $calendar = app(WorkingDayCalendar::class);

        $this->assertTrue($calendar->isWorkingDay('2026-12-23'));
        $this->assertFalse($calendar->isWorkingDay('2026-12-26'), 'Saturday');
        $this->assertFalse($calendar->isWorkingDay('2026-12-27'), 'Sunday');

        $id = $this->actingAs($this->maker)->postJson('/api/amla/change-requests', [
            'type' => 'calendar_add', 'reason' => 'Christmas.', 'date' => '2026-12-25', 'kind' => 'holiday', 'name' => 'Christmas Day', 'source' => 'Proclamation No. 1',
        ])->json('data.id');
        $this->assertTrue($calendar->isWorkingDay('2026-12-25'), 'a pending entry changes nothing');

        $this->actingAs($this->checker)->postJson("/api/amla/change-requests/{$id}/approve")->assertOk();
        $this->assertFalse($calendar->isWorkingDay('2026-12-25'));

        $this->holiday('2026-12-28', 'amlc_non_reporting');
        $this->holiday('2026-12-29', 'facility_suspension');
        $this->assertFalse($calendar->isWorkingDay('2026-12-28'));
        $this->assertFalse($calendar->isWorkingDay('2026-12-29'));
    }

    public function test_a_ctr_is_due_on_the_fifth_working_day_after_and_is_provisional_until_every_year_is_confirmed(): void
    {
        $before = $this->outsideAmlaCounts();

        foreach (['2026-12-24', '2026-12-25', '2026-12-30', '2026-12-31', '2027-01-01'] as $date) {
            $this->holiday($date);
        }
        $this->approved(['type' => 'calendar_confirm_year', 'year' => 2026]);

        $ctr = app(Deadlines::class)->due('ctr', '2026-12-22');

        $this->assertSame('2027-01-05', $ctr['due_on']);
        $this->assertSame(5, $ctr['working_days']);
        $this->assertTrue($ctr['provisional']);
        $this->assertSame([2027], $ctr['provisional_years']);
        $this->assertTrue($ctr['to_verify']);

        $this->approved(['type' => 'calendar_confirm_year', 'year' => 2027]);
        $this->assertFalse(app(Deadlines::class)->due('ctr', '2026-12-22')['provisional']);
        $this->assertNothingWrittenOutsideAmla($before);
    }

    public function test_an_str_is_due_the_next_working_day(): void
    {
        $this->assertSame('2026-12-28', app(Deadlines::class)->due('str', '2026-12-25')['due_on'], 'Friday to Monday');
        $this->holiday('2026-12-28');
        $this->assertSame('2026-12-29', app(Deadlines::class)->due('str', '2026-12-25')['due_on']);
    }

    public function test_the_deadline_preview_endpoint_needs_amla_view_only(): void
    {
        $branchUser = $this->amlaUser(['amla:view']);

        $this->actingAs($branchUser)->getJson('/api/amla/deadlines/preview?kind=ctr&from=2026-12-22')->assertOk()
            ->assertJsonPath('data.due_on', '2026-12-29')
            ->assertJsonPath('data.provisional', true)
            ->assertJsonPath('data.settings_version', 1);

        $this->actingAs($branchUser)->getJson('/api/amla/deadlines/preview?kind=other&from=nope')->assertUnprocessable()
            ->assertJsonValidationErrors(['kind', 'from']);

        $this->actingAs($this->amlaUser(['loans:view']))->getJson('/api/amla/deadlines/preview?kind=ctr&from=2026-12-22')->assertForbidden();
    }

    public function test_the_calendar_lists_a_years_entries_and_its_pending_changes(): void
    {
        $this->holiday('2026-12-25');
        $this->actingAs($this->maker)->postJson('/api/amla/change-requests', [
            'type' => 'calendar_add', 'reason' => 'Rizal Day.', 'date' => '2026-12-30', 'kind' => 'holiday', 'name' => 'Rizal Day', 'source' => 'Proclamation No. 2',
        ])->assertCreated();

        $data = $this->actingAs($this->amlaUser(['amla:view']))->getJson('/api/amla/calendar?year=2026')->assertOk()->json('data');

        $this->assertSame(2026, $data['year']);
        $this->assertFalse($data['confirmed']);
        $this->assertTrue($data['provisional']);
        $this->assertCount(1, $data['entries']);
        $this->assertSame('2026-12-25', $data['entries'][0]['date']);
        $this->assertSame('Holiday', $data['entries'][0]['kind_words']);
        $this->assertSame('Proclamation No. 1', $data['entries'][0]['source']);
        $this->assertSame($this->checker->id, $data['entries'][0]['approved_by']['id']);
        $this->assertArrayNotHasKey('pending', $data, 'a viewer without the settings key never sees who asked for what, or why');

        $pending = $this->actingAs($this->settingsHolder())->getJson('/api/amla/calendar?year=2026')->assertOk()->json('data.pending');
        $this->assertCount(1, $pending);
        $this->assertSame('Rizal Day.', $pending[0]['reason']);

        $this->actingAs($this->amlaUser(['amla:view', 'amla:settings']))->getJson('/api/amla/calendar?year=2026')->assertOk()
            ->assertJsonMissingPath('data.pending');
    }

    public function test_an_entry_is_removed_only_through_an_approved_change_and_its_row_stays(): void
    {
        $this->holiday('2026-12-28');
        $entry = AmlaCalendarEntry::query()->firstOrFail();

        $this->approved(['type' => 'calendar_remove', 'entry_id' => $entry->id]);

        $this->assertTrue(app(WorkingDayCalendar::class)->isWorkingDay('2026-12-28'));
        $this->assertNotNull($entry->fresh()->removed_at);

        $this->actingAs($this->maker)->postJson('/api/amla/change-requests', [
            'type' => 'calendar_remove', 'reason' => 'Again.', 'entry_id' => $entry->id,
        ])->assertUnprocessable();

        $this->expectException(AmlaRecordIsAppendOnlyException::class);
        $entry->fresh()->delete();
    }

    public function test_duplicates_and_repeat_confirmations_are_refused(): void
    {
        $this->holiday('2026-12-25');

        $this->actingAs($this->maker)->postJson('/api/amla/change-requests', [
            'type' => 'calendar_add', 'reason' => 'Again.', 'date' => '2026-12-25', 'kind' => 'holiday', 'name' => 'Christmas', 'source' => 'x',
        ])->assertUnprocessable()->assertJsonPath('message', 'December 25, 2026 is already on the AMLA calendar as a holiday, or waiting for approval.');

        $this->approved(['type' => 'calendar_confirm_year', 'year' => 2026]);
        $this->actingAs($this->maker)->postJson('/api/amla/change-requests', [
            'type' => 'calendar_confirm_year', 'reason' => 'Again.', 'year' => 2026,
        ])->assertUnprocessable()->assertJsonPath('message', 'The 2026 AMLA calendar is already confirmed, or its confirmation is waiting for approval.');

        $this->expectException(AmlaRecordIsAppendOnlyException::class);
        AmlaCalendarYear::query()->firstOrFail()->delete();
    }

    public function test_an_entry_needs_a_kind_a_name_and_a_source(): void
    {
        $this->actingAs($this->maker)->postJson('/api/amla/change-requests', [
            'type' => 'calendar_add', 'reason' => 'x', 'date' => '2026-02-30', 'kind' => 'party',
        ])->assertUnprocessable()->assertJsonValidationErrors(['date', 'kind', 'name', 'source']);
    }

    public function test_a_stale_copy_of_a_removed_entry_can_never_remove_it_again(): void
    {
        $this->holiday('2026-12-28');
        $this->holiday('2026-12-29');
        [$first, $second] = AmlaCalendarEntry::query()->orderBy('id')->get()->all();
        $stale = AmlaCalendarEntry::query()->findOrFail($first->id);
        $removal = AmlaChangeRequest::query()->where('type', 'calendar_add')->orderBy('id')->firstOrFail();

        $first->forceFill(['active_marker' => null, 'removal_request_id' => $removal->id, 'removed_at' => now()])->save();

        try {
            $stale->forceFill(['active_marker' => null, 'removal_request_id' => $second->request_id, 'removed_at' => now()->addMinute()])->save();
            $this->fail('A stale copy removed the entry a second time.');
        } catch (AmlaRecordIsAppendOnlyException) {
            $this->assertSame($removal->id, $first->fresh()->removal_request_id);
        }
    }
}
