<?php

use App\Models\Branch;
use App\Models\Loan;
use App\Models\OriginationStatusHistory;
use Illuminate\Support\Carbon;
use Tests\TestCase;

require_once __DIR__.'/StaffFixtures.php';

/*
 * LO-B3: the origination dashboard. Every figure comes from the server, for
 * the caller's branches, in Manila days, leaving out applications from links
 * marked "Test" (decision 17). Turnaround on the loans the applications
 * became is EB-D2's ApplicationTurnaround, so it reads the same as the
 * executive dashboard's.
 */
uses(TestCase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-19 10:00:00', 'Asia/Manila'));
    $this->main = Branch::query()->orderBy('id')->firstOrFail();
    $this->other = Branch::factory()->create(['name' => 'North Branch', 'code' => 'NB']);
    $this->user = lo3Staff($this->main, 'loan_origination:view');
});

afterEach(fn () => Carbon::setTestNow());

function lo3Dashboard($test, string $query = 'date_from=2026-11-01&date_to=2026-11-19')
{
    return $test->actingAs($test->user)->getJson('/api/loan-origination/dashboard?'.$query)->assertOk();
}

it('counts the period\'s applications by status, with requested amounts and rates from the server', function () {
    $day = fn (string $date) => Carbon::parse($date.' 09:00:00', 'Asia/Manila');

    lo3Application('draft', ['started_at' => $day('2026-11-10'), 'submitted_at' => null]);
    lo3Application('incomplete', ['started_at' => $day('2026-11-11'), 'submitted_at' => null]);
    lo3Application('submitted', ['started_at' => $day('2026-11-12'), 'submitted_at' => $day('2026-11-12'), 'requested_amount' => '10000.00']);
    lo3Application('under_assessment', ['started_at' => $day('2026-11-12'), 'submitted_at' => $day('2026-11-13'), 'requested_amount' => '15000.50']);
    $rejected = lo3Application('rejected', ['started_at' => $day('2026-11-13'), 'submitted_at' => $day('2026-11-13'), 'decided_at' => $day('2026-11-15'), 'requested_amount' => '5000.00']);
    OriginationStatusHistory::query()->create(['application_id' => $rejected->id, 'from_status' => 'under_assessment', 'to_status' => 'rejected', 'actor_type' => 'staff']);
    lo3Application('submitted', ['started_at' => $day('2026-10-05'), 'submitted_at' => $day('2026-10-05')]);
    lo3Application('submitted', ['started_at' => $day('2026-11-12'), 'submitted_at' => $day('2026-11-12'), 'is_test' => true]);
    lo3Application('submitted', ['started_at' => $day('2026-11-12'), 'submitted_at' => $day('2026-11-12'), 'branch_id' => $this->other->id]);

    $kpis = lo3Dashboard($this)->json('data.kpis');

    expect($kpis['total'])->toBe(5)
        ->and($kpis['new'])->toBe(3)
        ->and($kpis['incomplete'])->toBe(2)
        ->and($kpis['submitted'])->toBe(1)
        ->and($kpis['under_assessment'])->toBe(1)
        ->and($kpis['rejected'])->toBe(1)
        ->and($kpis['amount_requested'])->toBe('30000.50')
        ->and($kpis['completion_rate'])->toEqual(60.0)
        ->and($kpis['approval_rate'])->toEqual(0.0)
        ->and($kpis['avg_processing_hours'])->toEqual(48.0);
});

it('sends the period before for comparison, the status and product breakdowns, and the recent submissions', function () {
    lo3Application('submitted', ['started_at' => Carbon::parse('2026-10-20 09:00', 'Asia/Manila'), 'submitted_at' => Carbon::parse('2026-10-20 09:00', 'Asia/Manila')]);
    $now = lo3Application('submitted', ['started_at' => now()->subDay(), 'submitted_at' => now()->subDay()]);

    $data = lo3Dashboard($this)->json('data');

    expect($data['period'])->toBe(['from' => '2026-11-01', 'to' => '2026-11-19'])
        ->and($data['previous']['new'])->toBe(1)
        ->and(collect($data['by_status'])->firstWhere('status', 'submitted')['count'])->toBe(1)
        ->and($data['recent'][0]['id'])->toBe($now->id)
        ->and($data['trends'])->toHaveCount(19);
});

it('reads the average hours in each stage from the status history', function () {
    $application = lo3Application('document_verification', ['started_at' => now()->subDays(2), 'submitted_at' => now()->subDays(2)]);
    Carbon::setTestNow(Carbon::parse('2026-11-18 08:00:00', 'Asia/Manila'));
    OriginationStatusHistory::query()->create(['application_id' => $application->id, 'from_status' => 'draft', 'to_status' => 'submitted', 'actor_type' => 'applicant']);
    Carbon::setTestNow(Carbon::parse('2026-11-18 14:00:00', 'Asia/Manila'));
    OriginationStatusHistory::query()->create(['application_id' => $application->id, 'from_status' => 'submitted', 'to_status' => 'document_verification', 'actor_type' => 'staff']);
    Carbon::setTestNow(Carbon::parse('2026-11-19 10:00:00', 'Asia/Manila'));

    $stages = collect(lo3Dashboard($this)->json('data.stage_times'));

    expect($stages->firstWhere('status', 'submitted')['avg_hours'])->toEqual(6.0);
});

it('measures turnaround only on the loans online applications became, through ApplicationTurnaround', function () {
    $loan = Loan::factory()->create(['branch_id' => $this->main->id, 'status' => 'released', 'approved_at' => Carbon::parse('2026-11-10 09:00', 'Asia/Manila'), 'released_at' => Carbon::parse('2026-11-12 09:00', 'Asia/Manila')]);
    Loan::factory()->create(['branch_id' => $this->main->id, 'status' => 'released', 'approved_at' => Carbon::parse('2026-11-10 09:00', 'Asia/Manila'), 'released_at' => Carbon::parse('2026-11-11 09:00', 'Asia/Manila')]);
    lo3Application('converted', ['loan_id' => $loan->id, 'started_at' => now()->subDays(12), 'submitted_at' => now()->subDays(12)]);
    $this->user = lo3Staff($this->main, 'loan_origination:view', 'loans:view');

    $turnaround = lo3Dashboard($this)->json('data.turnaround');

    expect($turnaround['approved_to_released']['loans'])->toBe(1)
        ->and($turnaround['approved_to_released']['average_days'])->toEqual(2.0);
});

it('refuses without loan_origination:view, a branch outside the caller\'s, and a range over a year', function () {
    $this->actingAs(lo3Staff($this->main, 'loans:view'))->getJson('/api/loan-origination/dashboard')->assertForbidden();
    $this->actingAs($this->user)->getJson('/api/loan-origination/dashboard?branch_id='.$this->other->id)->assertForbidden();
    $this->actingAs($this->user)->getJson('/api/loan-origination/dashboard?date_from=2024-01-01&date_to=2026-11-19')->assertUnprocessable();
    $this->actingAs($this->user)->getJson('/api/loan-origination/dashboard')->assertOk()->assertJsonPath('data.period.from', '2026-11-01');
});

describe('the change from the period before (the executive dashboard\'s rule)', function () {
    beforeEach(function () {
        $day = fn (string $date) => Carbon::parse($date.' 09:00:00', 'Asia/Manila');

        lo3Application('submitted', ['started_at' => $day('2026-10-20'), 'submitted_at' => $day('2026-10-20'), 'requested_amount' => '10000.00']);
        foreach (['2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24'] as $date) {
            lo3Application('draft', ['started_at' => $day($date), 'submitted_at' => null]);
        }
        foreach (['2026-11-10', '2026-11-11', '2026-11-12'] as $date) {
            lo3Application('submitted', ['started_at' => $day($date), 'submitted_at' => $day($date), 'requested_amount' => '5000.00']);
        }

        foreach (['2026-10-25' => 10000, '2026-11-14' => 12000] as $date => $principal) {
            $loan = Loan::factory()->create(['branch_id' => $this->main->id, 'status' => 'approved', 'principal_amount' => $principal, 'approved_at' => $day($date)]);
            lo3Application('pending_approval', ['loan_id' => $loan->id, 'started_at' => $day('2026-09-01'), 'submitted_at' => $day('2026-09-01')]);
        }
    });

    it('sends a server-worked change for every figure with a previous figure: a percentage, or points for a rate', function () {
        $this->user = lo3Staff($this->main, 'loan_origination:view', 'loans:view');
        $data = lo3Dashboard($this)->json('data');
        $changes = $data['changes'];

        expect(array_keys($changes))->toBe(array_keys($data['kpis']))
            ->and($data['previous']['new'])->toBe(1)
            ->and($data['kpis']['new'])->toBe(3)
            // a rise
            ->and($changes['new'])->toEqual(['change' => 200.0, 'change_unit' => 'percent'])
            ->and($changes['amount_requested'])->toEqual(['change' => 50.0, 'change_unit' => 'percent'])
            ->and($changes['amount_approved'])->toEqual(['change' => 20.0, 'change_unit' => 'percent'])
            ->and($changes['completion_rate'])->toEqual(['change' => 80.0, 'change_unit' => 'points'])
            // a fall
            ->and($changes['total'])->toEqual(['change' => -40.0, 'change_unit' => 'percent'])
            ->and($changes['incomplete'])->toEqual(['change' => -100.0, 'change_unit' => 'percent'])
            // a previous figure of zero, or none
            ->and($changes['under_assessment'])->toBe(['change' => null, 'change_unit' => 'percent'])
            ->and($changes['approval_rate'])->toBe(['change' => null, 'change_unit' => 'points'])
            ->and($changes['avg_processing_hours'])->toBe(['change' => null, 'change_unit' => 'percent']);
    });

    it('keeps a withheld figure\'s change withheld', function () {
        $data = lo3Dashboard($this)->json('data');

        expect($data['kpis']['amount_approved'])->toBeNull()
            ->and($data['previous']['amount_approved'])->toBeNull()
            ->and($data['changes']['amount_approved'])->toBe(['change' => null, 'change_unit' => 'percent'])
            ->and($data['changes']['new'])->toEqual(['change' => 200.0, 'change_unit' => 'percent']);
    });
});
