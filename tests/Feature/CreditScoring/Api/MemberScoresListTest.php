<?php

use App\Models\Borrower;
use App\Models\CreditScoreMember;
use App\Models\Loan;
use App\Models\LoanProduct;
use Illuminate\Support\Facades\DB;
use Tests\Feature\CreditScoring\Api\CreditApiFixtures;
use Tests\TestCase;

/**
 * GET /credit-scoring/borrowers (task 86d4ayrmt section 13): the score trend,
 * member type and loan product filters, and each row's Outstanding. The loan
 * product filter and Outstanding are loan facts: the filter answers 422
 * without `loans:view`, and Outstanding reads null unless the caller may read
 * the member's figures (CreditScope::seesFinancialsFrom()).
 */
uses(TestCase::class, CreditApiFixtures::class);

beforeEach(function () {
    $this->pinToday();
    $this->loanOf = fn (Borrower $member): Loan => Loan::query()->where('borrower_id', $member->id)->where('status', 'ongoing')->firstOrFail();
    $this->ids = fn (string $query, $user = null) => collect($this->actingAs($user ?? $this->branchAnalyst())
        ->getJson('/api/credit-scoring/borrowers'.$query)->assertOk()->json('data.data'))->pluck('borrower_id')->sort()->values()->all();
});

it('filters by score trend', function () {
    $up = $this->scoredMember();
    $down = $this->scoredMember();
    $flat = $this->scoredMember();
    CreditScoreMember::query()->where('borrower_id', $up->id)->update(['trend' => 'up']);
    CreditScoreMember::query()->where('borrower_id', $down->id)->update(['trend' => 'down']);
    CreditScoreMember::query()->where('borrower_id', $flat->id)->update(['trend' => 'flat']);

    expect(($this->ids)('?trend=up'))->toBe([$up->id])
        ->and(($this->ids)('?trend=down'))->toBe([$down->id])
        ->and(($this->ids)('?trend=flat'))->toBe([$flat->id]);

    $this->actingAs($this->branchAnalyst())->getJson('/api/credit-scoring/borrowers?trend=sideways')
        ->assertUnprocessable()->assertJsonValidationErrors('trend');
});

it('filters by member type, without needing loans:view', function () {
    $regular = $this->scoredMember();
    $associate = $this->scoredMember(null, ['member_type' => 'associate']);
    $viewer = $this->caller(['credit_scoring:view']);

    expect(($this->ids)('?member_type=associate', $viewer))->toBe([$associate->id])
        ->and(($this->ids)('?member_type=regular', $viewer))->toBe([$regular->id]);

    $this->actingAs($viewer)->getJson('/api/credit-scoring/borrowers?member_type=walk_in')
        ->assertUnprocessable()->assertJsonValidationErrors('member_type');
});

it('filters by the product of a visible collectible loan, and needs loans:view for it', function () {
    $product = LoanProduct::factory()->create();
    $holder = $this->scoredMember();
    ($this->loanOf)($holder)->update(['loan_product_id' => $product->id]);
    $defaulted = $this->scoredMember();
    $this->releasedLoan($defaulted, ['loan_product_id' => $product->id, 'status' => 'defaulted']);
    $draftOnly = $this->scoredMember();
    $this->application($draftOnly, ['loan_product_id' => $product->id]);
    $closedOnly = $this->scoredMember();
    $this->releasedLoan($closedOnly, ['loan_product_id' => $product->id, 'status' => 'completed']);
    // A main-branch member whose loan of the product sits in a branch the caller can't see.
    $elsewhere = $this->scoredMember();
    $this->releasedLoan($elsewhere, ['loan_product_id' => $product->id, 'branch_id' => $this->farBranch()->id]);

    expect(($this->ids)("?loan_product_id={$product->id}"))->toBe([$holder->id, $defaulted->id])
        ->and(($this->ids)("?loan_product_id={$product->id}", $this->headOffice()))->toBe([$holder->id, $defaulted->id, $elsewhere->id]);

    $this->actingAs($this->caller(['credit_scoring:view', 'borrowers:view']))
        ->getJson("/api/credit-scoring/borrowers?loan_product_id={$product->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['loan_product_id' => 'Filtering by loan product needs permission to view loans.']);
    $this->actingAs($this->branchAnalyst())->getJson('/api/credit-scoring/borrowers?loan_product_id=999999')
        ->assertUnprocessable()->assertJsonValidationErrors('loan_product_id');
});

it('shows each row\'s Outstanding from its visible collectible loans', function () {
    $owing = $this->scoredMember();
    $this->period(($this->loanOf)($owing), 4, '2026-12-01');
    ($this->loanOf)($owing)->update(['insurance_remaining_balance' => 75.25]);
    $second = $this->releasedLoan($owing, ['status' => 'defaulted']);
    $this->period($second, 1, '2026-09-01', ['principal_due' => 300, 'interest_due' => 0, 'total_due' => 300, 'principal_paid' => 100]);
    $this->period($this->application($owing), 1, '2026-12-10');
    $hidden = $this->releasedLoan($owing, ['branch_id' => $this->farBranch()->id]);
    $this->period($hidden, 1, '2026-12-01');
    $clear = $this->scoredMember();

    $rows = collect($this->actingAs($this->branchAnalyst())->getJson('/api/credit-scoring/borrowers')->assertOk()->json('data.data'))->keyBy('borrower_id');

    // ₱1,100.00 + ₱75.25 premium + ₱200.00 left on the defaulted loan; the far branch's loan is left out.
    expect($rows[$owing->id]['outstanding'])->toBe('1375.25')
        ->and($rows[$clear->id]['outstanding'])->toBe('0.00');

    $headOffice = collect($this->actingAs($this->headOffice())->getJson('/api/credit-scoring/borrowers')->json('data.data'))->keyBy('borrower_id');
    expect($headOffice[$owing->id]['outstanding'])->toBe('2475.25');
});

it('reads Outstanding null for a caller who may not read the member\'s figures', function (array $permissions) {
    $member = $this->scoredMember();
    $this->period(($this->loanOf)($member), 4, '2026-12-01');

    $row = $this->actingAs($this->caller($permissions))->getJson('/api/credit-scoring/borrowers')->assertOk()->json('data.data.0');

    expect($row['borrower_id'])->toBe($member->id)
        ->and($row)->toHaveKey('outstanding')
        ->and($row['outstanding'])->toBeNull();
})->with([
    'no loans:view' => [['credit_scoring:view', 'borrowers:view']],
    'no borrowers:view' => [['credit_scoring:view', 'loans:view']],
]);

it('works out every row\'s Outstanding in one grouped read, not one per row', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->headOffice())->getJson('/api/credit-scoring/borrowers')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    foreach (range(1, 2) as $i) {
        $this->period(($this->loanOf)($this->scoredMember()), 4, '2026-12-01');
    }
    $two = $count();

    foreach (range(1, 4) as $i) {
        $this->period(($this->loanOf)($this->scoredMember($this->farBranch())), 4, '2026-12-01');
    }

    expect($count())->toBe($two);
});

it('adds Outstanding to the risk monitoring rows too', function () {
    $member = $this->scoredMember();
    CreditScoreMember::query()->where('borrower_id', $member->id)->update(['risk_level' => 'high', 'score' => 45]);
    $this->period(($this->loanOf)($member), 4, '2026-12-01');

    expect($this->actingAs($this->branchAnalyst())->getJson('/api/credit-scoring/risk-monitoring')->json('data.affected_borrowers.0.outstanding'))->toBe('1100.00');
});
