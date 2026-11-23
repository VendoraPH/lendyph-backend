<?php

use App\Models\ApprovalWorkflowSetting;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\OriginationStatusHistory;
use App\Models\User;
use App\Services\LoanOrigination\Observers\LoanStatusObserver;
use App\Services\LoanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Phase6\LoanOrigination\OriginationFixtures;
use Tests\TestCase;

/*
 * LO-B4: once handed off, an application follows its loan (origination §3.1):
 * for approval → For Approval, approved → Approved, rejected → Rejected,
 * released → Converted to Loan, voided before release → Withdrawn, sent back
 * from approved → For Approval, back to draft → Under Assessment. The
 * observer is registered on Loan with #[ObservedBy].
 */
uses(TestCase::class, OriginationFixtures::class);

beforeEach(function () {
    $this->pinOriginationToday();
    Storage::fake('private');
    $this->admin = User::query()->where('username', 'super_admin')->firstOrFail();
    $this->actingAs($this->admin);
});

/** A submitted loan and the application handed off to it, at pending_approval. */
function lo4Followed(TestCase $test, string $applicationStatus = 'pending_approval'): array
{
    $product = LoanProduct::factory()->create(['interest_rate' => 2, 'term' => 6, 'frequency' => 'monthly', 'min_amount' => 1000, 'max_amount' => 100000]);
    $member = Borrower::factory()->create(['status' => 'active', 'branch_id' => Branch::query()->value('id')]);
    $loans = app(LoanService::class);
    $loan = $loans->createLoan(['borrower_id' => $member->id, 'loan_product_id' => $product->id, 'principal_amount' => 30000, 'start_date' => '2026-11-19'], $test->admin);
    $application = $test->originationApplication($applicationStatus, $product, ['borrower_id' => $member->id]);
    DB::table('origination_applications')->where('id', $application->id)->update(['loan_id' => $loan->id]);
    $loans->submitForReview($loan, $test->admin);

    return [$loan->fresh(), $application->fresh()];
}

function lo4ShortChain(): void
{
    ApprovalWorkflowSetting::query()->updateOrCreate(['type' => ApprovalWorkflowSetting::TYPE_NORMAL], ['steps' => [
        ['id' => 'submit', 'name' => 'Submit', 'role' => 'super_admin', 'kind' => 'submit'],
        ['id' => 'release', 'name' => 'Release', 'role' => 'super_admin', 'kind' => 'release'],
    ]]);
}

it('is registered on Loan by attribute', function () {
    $attributes = (new ReflectionClass(Loan::class))->getAttributes(Illuminate\Database\Eloquent\Attributes\ObservedBy::class);

    expect($attributes)->toHaveCount(1)
        ->and($attributes[0]->getArguments()[0])->toContain(LoanStatusObserver::class);
});

it('leaves an application already at For Approval as it is when its loan is submitted', function () {
    [, $application] = lo4Followed($this);

    expect($application->status)->toBe('pending_approval')
        ->and(OriginationStatusHistory::query()->where('application_id', $application->id)->count())->toBe(0);
});

it('follows its loan\'s rejection', function () {
    [$loan, $application] = lo4Followed($this);

    app(LoanService::class)->reject($loan, $this->admin, 'Short on income');

    $history = OriginationStatusHistory::query()->where('application_id', $application->id)->sole();
    expect($application->fresh()->status)->toBe('rejected')
        ->and($application->fresh()->decided_at)->not->toBeNull()
        ->and($history->actor_type)->toBe('loan')
        ->and($history->from_status)->toBe('pending_approval')
        ->and($history->note)->toBe("Loan {$loan->application_number} was rejected.");
});

it('follows its loan\'s void before release as Withdrawn', function () {
    [$loan, $application] = lo4Followed($this);

    app(LoanService::class)->voidLoan($loan->fresh());

    expect($application->fresh()->status)->toBe('withdrawn');
});

it('follows its loan\'s approval and release, ending as Converted to Loan', function () {
    lo4ShortChain();
    [$loan, $application] = lo4Followed($this);

    expect($loan->status)->toBe('approved')
        ->and($application->fresh()->status)->toBe('approved');

    app(LoanService::class)->release($loan->fresh(), $this->admin);

    expect($application->fresh()->status)->toBe('converted')
        ->and(OriginationStatusHistory::query()->where('application_id', $application->id)->orderBy('id')->pluck('to_status')->all())->toBe(['approved', 'converted']);
});

it('follows an approved loan sent back for approval', function () {
    lo4ShortChain();
    [$loan, $application] = lo4Followed($this);

    $loan->fresh()->update(['status' => 'for_review']);
    expect($application->fresh()->status)->toBe('pending_approval');
});

it('leaves the application For Approval when its loan goes back to draft, and follows the loan once it is submitted again', function () {
    [$loan, $application] = lo4Followed($this);

    $loan->fresh()->update(['status' => 'draft']);
    expect($application->fresh()->status)->toBe('pending_approval')
        ->and($application->fresh()->loan_id)->toBe($loan->id);

    $loan->fresh()->update(['status' => 'for_review']);
    app(LoanService::class)->reject($loan->fresh(), $this->admin, 'Short on income');

    expect($application->fresh()->status)->toBe('rejected')
        ->and(OriginationStatusHistory::query()->where('application_id', $application->id)->pluck('to_status')->all())->toBe(['rejected']);
});

it('reads the application under a row lock before moving it', function () {
    [$loan, $application] = lo4Followed($this);
    $reads = [];
    DB::listen(function ($query) use (&$reads): void {
        if (str_contains($query->sql, 'from `origination_applications` where `loan_id`')) {
            $reads[] = $query->sql;
        }
    });

    app(LoanService::class)->reject($loan, $this->admin, 'Short on income');

    expect($reads)->toHaveCount(1)
        ->and($reads[0])->toEndWith('for update')
        ->and($application->fresh()->status)->toBe('rejected');
});

it('touches no application for a loan that didn\'t come from one', function () {
    $product = LoanProduct::factory()->create(['interest_rate' => 2, 'term' => 6, 'frequency' => 'monthly', 'min_amount' => 1000]);
    $member = Borrower::factory()->create(['status' => 'active', 'branch_id' => Branch::query()->value('id')]);
    $loans = app(LoanService::class);
    $loan = $loans->createLoan(['borrower_id' => $member->id, 'loan_product_id' => $product->id, 'principal_amount' => 30000, 'start_date' => '2026-11-19'], $this->admin);

    $loans->submitForReview($loan, $this->admin);
    $loans->voidLoan($loan->fresh());

    expect(OriginationStatusHistory::query()->count())->toBe(0);
});

it('never blocks the loan when the application can\'t follow, and logs it without personal data', function () {
    [$loan, $application] = lo4Followed($this);
    DB::table('origination_applications')->where('id', $application->id)->update(['status' => 'withdrawn']);
    Log::spy();

    app(LoanService::class)->reject($loan, $this->admin, null);

    expect($loan->fresh()->status)->toBe('rejected')
        ->and($application->fresh()->status)->toBe('withdrawn');
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => str_contains($message, 'Loan origination')
        && $context === ['application_id' => $application->id, 'loan_id' => $loan->id, 'application_status' => 'withdrawn', 'loan_status' => 'rejected']);
});
