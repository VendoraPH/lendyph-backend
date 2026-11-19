<?php

namespace Tests\Feature\Phase6\Amla\Cases;

use App\Models\AmlaAlert;
use App\Models\AmlaCase;
use App\Models\AmlaCtrCandidate;
use App\Models\Borrower;
use App\Models\User;
use Tests\Feature\Phase6\Amla\AmlaTestHelpers;
use Tests\Feature\Phase6\Amla\Detection\DetectionTestHelpers;

/**
 * Shared set-up for the AMLA cases and filings tests (AMLA-B6): alerts and
 * CTR candidates made by the real detector from AMLA events, compliance
 * users with exactly the keys named, and calendar days written straight into
 * B1's tables.
 */
trait CaseTestHelpers
{
    use AmlaTestHelpers, DetectionTestHelpers;

    protected function startCases(): void
    {
        $this->seedAndLogin();
        $this->startDetection();
    }

    /** A compliance user with these keys and head-office access. */
    protected function headOffice(array $permissions): User
    {
        return $this->amlaUser([...$permissions, 'branches:view_all']);
    }

    /** A same-day structuring alert for a member of `$branchId` (the seeded branch by default). */
    protected function structuringAlert(?int $branchId = null): AmlaAlert
    {
        $member = $this->member($branchId);
        $this->event($member, '300000.00');
        $this->event($member, '300000.00');
        $this->detect();

        return AmlaAlert::query()->where('borrower_id', $member->id)->where('rule_code', 'A')->sole();
    }

    protected function ctrCandidate(?Borrower $member = null, string $occurredOn = '2026-11-20'): AmlaCtrCandidate
    {
        $event = $this->event($member ?? $this->member(), '650000.00', ['occurred_on' => $occurredOn]);
        $this->detect();

        return AmlaCtrCandidate::query()->where('event_id', $event->id)->sole();
    }

    /** An open case on a member, made through the API by a head-office case officer. */
    protected function openCase(?User $officer = null): AmlaCase
    {
        $officer ??= $this->headOffice(['amla:cases']);
        $member = $this->member();
        $id = $this->actingAs($officer)->postJson('/api/amla/cases', ['title' => 'Cash deposits below the CTR line', 'borrower_id' => $member->id])
            ->assertCreated()->json('data.id');

        return AmlaCase::query()->findOrFail($id);
    }

    /** A holiday through B1's approved calendar change. */
    protected function holiday(string $date): void
    {
        $this->calendarChange(['type' => 'calendar_add', 'date' => $date, 'kind' => 'holiday', 'name' => 'Holiday '.$date, 'source' => 'Proclamation No. 1']);
    }

    /** A confirmed calendar year through B1's approved calendar change. */
    protected function confirmYear(int $year): void
    {
        $this->calendarChange(['type' => 'calendar_confirm_year', 'year' => $year]);
    }

    /** @param  array<string, mixed>  $body */
    private function calendarChange(array $body): void
    {
        $previous = auth()->user();
        $id = $this->actingAs($this->settingsHolder())->postJson('/api/amla/change-requests', ['reason' => 'Official proclamation.', ...$body])->assertCreated()->json('data.id');
        $this->actingAs($this->settingsHolder())->postJson("/api/amla/change-requests/{$id}/approve")->assertOk();
        $this->actingAs($previous);
    }
}
