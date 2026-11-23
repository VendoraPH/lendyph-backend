<?php

use App\Exceptions\OriginationRecordIsAppendOnlyException;
use App\Models\AmortizationSchedule;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\OriginationApplication;
use App\Models\OriginationAssessment;
use App\Models\User;
use App\Services\LoanOrigination\Assessment\AssessmentPdf;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Phase6\LoanOrigination\OriginationFixtures;
use Tests\TestCase;

/*
 * LO-B4: the assessment endpoints (#615's assessment screens). Running one
 * needs `loan_origination:assess`; reading one `loan_origination:view`. Each
 * run and each change of the officer's notes is a new immutable revision.
 */
uses(TestCase::class, OriginationFixtures::class);

beforeEach(fn () => $this->pinOriginationToday());

function lo4AssessUrl(int $id, string $suffix = ''): string
{
    return "/api/loan-origination/applications/{$id}/assessment{$suffix}";
}

it('runs an assessment with the verified figures and answers the frontend\'s shape', function () {
    $application = $this->originationApplication();
    $staff = $this->originationStaff('loan_origination:view', 'loan_origination:assess');

    $response = $this->actingAs($staff)->postJson(lo4AssessUrl($application->id, '/rerun'), [
        'verified_monthly_income' => '40000',
        'verified_debt_payments' => '1500.00',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.application_id', $application->id)
        ->assertJsonPath('data.result', 'within_capacity')
        ->assertJsonPath('data.result_label', 'Within capacity')
        ->assertJsonPath('data.provisional', false)
        ->assertJsonPath('data.figures.verified_monthly_income', 40000)
        ->assertJsonPath('data.thresholds.max_dti', 40)
        ->assertJsonPath('data.policy_exception', false)
        ->assertJsonPath('data.rules_version', '1')
        ->assertJsonPath('data.assessed_by.id', $staff->id)
        ->assertJsonStructure(['data' => ['figures' => ['eligible_monthly_income', 'proposed_monthly_installment', 'dti_ratio', 'coverage_ratio', 'remaining_income'], 'explanation', 'missing_information', 'conflicts', 'document_status', 'assessed_at']]);

    expect(AuditLog::query()->where('auditable_type', OriginationApplication::class)->where('auditable_id', $application->id)->where('action', 'assessed')->exists())->toBeTrue();
});

it('needs the assess permission to run, and the view permission to read, in words', function () {
    $application = $this->originationApplication();

    $this->actingAs($this->originationStaff('loan_origination:view'))->postJson(lo4AssessUrl($application->id, '/rerun'))
        ->assertForbidden()->assertJsonPath('message', 'Running an assessment needs the permission to assess online applications.');
    $this->actingAs($this->originationStaff('loans:view'))->getJson(lo4AssessUrl($application->id))
        ->assertForbidden()->assertJsonPath('message', 'You don\'t have access to online applications.');
});

it('answers 404 for another branch\'s application', function () {
    $other = Branch::factory()->create();
    $application = $this->originationApplication('under_assessment', null, ['branch_id' => $other->id]);
    $staff = $this->originationStaff('loan_origination:view', 'loan_origination:assess');

    $this->actingAs($staff)->postJson(lo4AssessUrl($application->id, '/rerun'))->assertNotFound();
    $this->actingAs($staff)->getJson(lo4AssessUrl($application->id, '/history'))->assertNotFound();
    $this->actingAs($staff)->get(lo4AssessUrl($application->id, '/pdf'))->assertNotFound();
});

it('keeps every run as its own revision, newest first in the history, and carries the verified figures forward', function () {
    $application = $this->originationApplication();
    $staff = $this->originationStaff('loan_origination:view', 'loan_origination:assess');

    $this->actingAs($staff)->postJson(lo4AssessUrl($application->id, '/rerun'), ['verified_monthly_income' => '40000', 'verified_debt_payments' => '1500'])->assertCreated();
    $this->actingAs($staff)->postJson(lo4AssessUrl($application->id, '/rerun'))->assertCreated()
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.figures.verified_monthly_income', 40000);
    $this->actingAs($staff)->postJson(lo4AssessUrl($application->id, '/rerun'), ['verified_monthly_income' => null])->assertCreated()
        ->assertJsonPath('data.version', 3)
        ->assertJsonPath('data.figures.verified_monthly_income', null)
        ->assertJsonPath('data.provisional', true);

    $this->actingAs($staff)->getJson(lo4AssessUrl($application->id, '/history'))->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.version', 3)
        ->assertJsonPath('data.2.version', 1);
    $this->actingAs($staff)->getJson(lo4AssessUrl($application->id))->assertOk()->assertJsonPath('data.version', 3);
});

it('saves the officer\'s findings as a new revision with the same figures, and never edits one', function () {
    $application = $this->originationApplication();
    $staff = $this->originationStaff('loan_origination:view', 'loan_origination:assess');
    $this->actingAs($staff)->postJson(lo4AssessUrl($application->id, '/rerun'), ['verified_monthly_income' => '40000', 'verified_debt_payments' => '0'])->assertCreated();
    $first = OriginationAssessment::query()->where('application_id', $application->id)->sole();

    $this->actingAs($staff)->patchJson(lo4AssessUrl($application->id), ['officer_findings' => 'Payslips match.', 'recommendations' => 'Approve.'])
        ->assertOk()
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.officer_findings', 'Payslips match.')
        ->assertJsonPath('data.recommendations', 'Approve.')
        ->assertJsonPath('data.figures.dti_ratio', fn ($v) => (float) $v === (float) $first->figures['dti_ratio']);

    $second = OriginationAssessment::query()->where('application_id', $application->id)->where('version', 2)->sole();
    expect($second->figures)->toBe($first->figures)
        ->and($second->kind)->toBe('notes')
        ->and(fn () => $first->update(['result' => 'above_capacity']))->toThrow(OriginationRecordIsAppendOnlyException::class)
        ->and(fn () => $first->delete())->toThrow(OriginationRecordIsAppendOnlyException::class);
});

it('can\'t save notes before there is an assessment', function () {
    $application = $this->originationApplication();

    $this->actingAs($this->originationStaff('loan_origination:view', 'loan_origination:assess'))
        ->patchJson(lo4AssessUrl($application->id), ['officer_findings' => 'x'])
        ->assertUnprocessable()->assertJsonPath('errors.assessment.0', 'Run the assessment first.');
});

it('stores the figures, the notes and the verified amounts encrypted', function () {
    $application = $this->originationApplication();
    $staff = $this->originationStaff('loan_origination:view', 'loan_origination:assess');
    $this->actingAs($staff)->postJson(lo4AssessUrl($application->id, '/rerun'), ['verified_monthly_income' => '40000'])->assertCreated();
    $this->actingAs($staff)->patchJson(lo4AssessUrl($application->id), ['officer_findings' => 'Payslips match.'])->assertOk();

    $raw = DB::table('origination_assessments')->where('application_id', $application->id)->orderByDesc('id')->first();

    expect($raw->figures)->not->toContain('40000')
        ->and($raw->inputs)->not->toContain('40000')
        ->and($raw->officer_findings)->not->toContain('Payslips');
});

it('refuses to assess an application already handed off, closed or not yet submitted', function (string $status) {
    $application = $this->originationApplication($status);

    $this->actingAs($this->originationStaff('loan_origination:view', 'loan_origination:assess'))
        ->postJson(lo4AssessUrl($application->id, '/rerun'))
        ->assertUnprocessable()
        ->assertJsonPath('errors.status.0', fn (string $m) => str_contains($m, 'can\'t be assessed'));
})->with(['draft', 'pending_approval', 'rejected', 'withdrawn']);

it('runs at the start date sent, today by default, and validates it as a date', function () {
    $application = $this->originationApplication();
    $staff = $this->originationStaff('loan_origination:view', 'loan_origination:assess');

    $this->actingAs($staff)->postJson(lo4AssessUrl($application->id, '/rerun'))->assertCreated()->assertJsonPath('data.start_date', '2026-11-19');
    $this->actingAs($staff)->postJson(lo4AssessUrl($application->id, '/rerun'), ['start_date' => '2026-12-01'])->assertCreated()->assertJsonPath('data.start_date', '2026-12-01');
    $this->actingAs($staff)->postJson(lo4AssessUrl($application->id, '/rerun'), ['start_date' => '12/01/2026'])
        ->assertUnprocessable()->assertJsonPath('errors.start_date.0', 'Enter the start date as a date, like 2026-11-19.');
});

/** An application linked to a matching member of $branch who has a loan being repaid, assessed on verified figures. */
function lo4LinkedAssessed(TestCase $test, ?Branch $branch = null): OriginationApplication
{
    $branch ??= Branch::query()->orderBy('id')->firstOrFail();
    $application = $test->originationApplication();
    $personal = $application->draft['personal'];
    $member = Borrower::factory()->create([
        'status' => 'active', 'branch_id' => $branch->id, 'first_name' => $personal['first_name'], 'last_name' => $personal['last_name'],
        'birthdate' => $personal['date_of_birth'], 'contact_number' => '09171234567',
    ]);
    $loan = Loan::factory()->create([
        'borrower_id' => $member->id, 'branch_id' => $branch->id, 'status' => 'ongoing', 'principal_amount' => 12000,
        'released_at' => now()->subMonths(2), 'created_by' => User::query()->value('id'),
    ]);
    AmortizationSchedule::factory()->create([
        'loan_id' => $loan->id, 'period_number' => 3, 'due_date' => '2026-12-10',
        'principal_due' => 2000, 'interest_due' => 240, 'total_due' => 2240,
        'principal_paid' => 0, 'interest_paid' => 0, 'penalty_paid' => 0, 'status' => 'pending',
    ]);
    DB::table('origination_applications')->where('id', $application->id)->update(['borrower_id' => $member->id]);
    $test->actingAs($test->originationStaff('loan_origination:view', 'loan_origination:assess'))
        ->postJson(lo4AssessUrl($application->id, '/rerun'), ['verified_monthly_income' => '40000', 'verified_debt_payments' => '1500'])
        ->assertCreated()->assertJsonPath('data.result', 'within_capacity');

    return $application->fresh();
}

describe('a linked member\'s loans', function () {
    it('shows the figures that count the member\'s loans to a viewer who may see their loans in their branch', function () {
        $application = lo4LinkedAssessed($this);

        $this->actingAs($this->originationStaff('loan_origination:view', 'loans:view', 'borrowers:view'))->getJson(lo4AssessUrl($application->id))->assertOk()
            ->assertJsonPath('data.member_figures_hidden', false)
            ->assertJsonPath('data.figures.lendy_debt_payments', fn ($v) => $v > 0)
            ->assertJsonPath('data.figures.monthly_debt_payments', fn ($v) => $v > 1500)
            ->assertJsonPath('data.explanation', fn (string $text) => str_contains($text, '%'));
    });

    it('withholds them, and every figure worked out from them, from a viewer without the loan or member permission or the member\'s branch', function (array $permissions, bool $elsewhere) {
        $application = lo4LinkedAssessed($this, $elsewhere ? Branch::factory()->create() : null);
        $viewer = $this->originationStaff('loan_origination:view', ...$permissions);

        $response = $this->actingAs($viewer)->getJson(lo4AssessUrl($application->id))->assertOk()
            ->assertJsonPath('data.member_figures_hidden', true)
            ->assertJsonPath('data.figures.verified_monthly_income', 40000)
            ->assertJsonPath('data.figures.outside_debt_payments', 1500)
            ->assertJsonPath('data.result', 'within_capacity')
            ->assertJsonPath('data.checks.dti', true);
        foreach (['lendy_debt_payments', 'monthly_debt_payments', 'disposable_income', 'dti_ratio', 'coverage_ratio', 'remaining_income'] as $figure) {
            expect($response->json("data.figures.{$figure}"))->toBeNull();
        }
        $withoutFigures = 'Within capacity: the debt-to-income ratio is within the 40% maximum, the coverage ratio meets the 1.25 minimum and the remaining income meets the ₱0.00 minimum, on verified figures.';
        expect($response->json('data.explanation'))->toBe($withoutFigures);

        $this->actingAs($viewer)->getJson(lo4AssessUrl($application->id, '/history'))->assertOk()->assertJsonPath('data.0.figures.lendy_debt_payments', null);
        $this->actingAs($viewer)->getJson('/api/loan-origination/assessments')->assertOk()->assertJsonPath('data.0.figures.lendy_debt_payments', null);
        $pdf = app(AssessmentPdf::class)->content(OriginationAssessment::currentFor($application), $application, $viewer, 'No documents are required');
        $printed = collect($pdf->sections)->flatMap(fn (array $section) => $section['fields'])->pluck('display', 'label');
        expect($printed['Debt payments on Lendy loans'])->toBe('—')
            ->and($printed['Disposable income'])->toBe('—')
            ->and($printed['Explanation'])->toBe($withoutFigures);
    })->with([
        'without loans:view' => [['borrowers:view'], false],
        'without borrowers:view' => [['loans:view'], false],
        'with both, the member in another branch' => [['loans:view', 'borrowers:view'], true],
    ]);
});

it('validates the verified amounts as pesos with at most two decimals', function () {
    $application = $this->originationApplication();

    $this->actingAs($this->originationStaff('loan_origination:view', 'loan_origination:assess'))
        ->postJson(lo4AssessUrl($application->id, '/rerun'), ['verified_monthly_income' => '100.123', 'verified_debt_payments' => '-1'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['verified_monthly_income', 'verified_debt_payments']);
});

it('prints the current assessment as a PDF and logs the download', function () {
    $application = $this->originationApplication();
    $staff = $this->originationStaff('loan_origination:view', 'loan_origination:assess');
    $this->actingAs($staff)->postJson(lo4AssessUrl($application->id, '/rerun'), ['verified_monthly_income' => '40000'])->assertCreated();

    $response = $this->actingAs($staff)->get(lo4AssessUrl($application->id, '/pdf'));

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(str_starts_with((string) $response->getContent(), '%PDF'))->toBeTrue()
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')
        ->and(AuditLog::query()->where('auditable_id', $application->id)->where('action', 'exported')->exists())->toBeTrue();
});

it('lists the current assessment of each visible application, with counts by result', function () {
    $staff = $this->originationStaff('loan_origination:view', 'loan_origination:assess');
    $mine = $this->originationApplication();
    $hidden = $this->originationApplication('under_assessment', null, ['branch_id' => Branch::factory()->create()->id]);
    $this->actingAs($staff)->postJson(lo4AssessUrl($mine->id, '/rerun'), ['verified_monthly_income' => '40000', 'verified_debt_payments' => '0'])->assertCreated();
    $this->actingAs($staff)->postJson(lo4AssessUrl($mine->id, '/rerun'))->assertCreated();
    $this->actingAs($this->originationStaff('loan_origination:assess', 'loan_origination:view', 'branches:view_all'))->postJson(lo4AssessUrl($hidden->id, '/rerun'))->assertCreated();

    $this->actingAs($staff)->getJson('/api/loan-origination/assessments')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.application_id', $mine->id)
        ->assertJsonPath('data.0.version', 2)
        ->assertJsonPath('meta.stats.within_capacity', 1)
        ->assertJsonPath('meta.stats.total', 1);
    $this->actingAs($staff)->getJson('/api/loan-origination/assessments?result=above_capacity')->assertOk()->assertJsonCount(0, 'data');
});
