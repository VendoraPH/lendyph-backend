<?php

/**
 * `co_maker_ids` is read as MEMBER (borrower) ids, and only as those, on every
 * loan write that accepts it: POST /loans, restructure, and update.
 *
 * That is all the loan form's co-maker picker has ever sent: its options are
 * the member list and each slot holds a member's id. The API used to read each
 * id as a co-maker RECORD id first and fall back to a member id — fixed on
 * POST /loans first, and by restructure and update in this same change. The
 * two are separate sequences, and the loan form itself creates a co-maker
 * record the first time a member is picked, so the numbers collide almost at
 * once: member #7 picked on one loan gets co-maker record #1, and member #1
 * picked on the next loan was then bound to member #7. A co-maker is jointly
 * liable for the loan, so that is the wrong person owing the money, silently.
 *
 * A second, deeper collision lived in how that co-maker record was found.
 * `coMakerRecordFor()` used to key its `firstOrCreate()` on `borrower_id` +
 * name, with no suffix — but the Co-makers tab (`CoMakerController::store`)
 * writes `borrower_id` as the TAB OWNER, not the person the entry describes. A
 * tab entry for "Juan Dela Cruz" (no suffix) entered under his son Jr.'s own
 * borrower profile therefore collided with Jr. HIMSELF later being picked as a
 * co-maker elsewhere, binding the wrong identity to that loan. `borrower_id`
 * still means "tab owner" on those rows; `member_borrower_id` — a member's own
 * id, unique, and nothing else — is what the picker now keys on instead. See
 * the headline regression specs below.
 *
 * Several specs need a member and a co-maker record that carry the SAME number.
 * The two tables count separately, so the specs build that deliberately: a
 * member created with a given id, or a co-maker record created with one.
 */

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\CoMaker;
use App\Models\Loan;
use App\Models\LoanProduct;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

uses(TestCase::class, SetupLendyPH::class);

beforeEach(function () {
    $this->seedAndLogin();
});

function coMakerLinkMember(array $attributes = []): Borrower
{
    return Borrower::factory()->create(['branch_id' => Branch::first()->id] + $attributes);
}

/**
 * The member whose id is exactly $id: whoever already holds it, or a new
 * member created with it.
 */
function coMakerLinkMemberWithId(int $id): Borrower
{
    return Borrower::find($id) ?? coMakerLinkMember(['id' => $id]);
}

function coMakerLinkApply(TestCase $test, Borrower $principal, array $coMakerIds): TestResponse
{
    return $test->postJson('/api/loans', [
        'borrower_id' => $principal->id,
        'loan_product_id' => LoanProduct::factory()->create()->id,
        'principal_amount' => 60000,
        'start_date' => now()->toDateString(),
        'co_maker_ids' => $coMakerIds,
    ]);
}

function coMakerLinkRestructure(TestCase $test, Loan $source, array $overrides = []): TestResponse
{
    return $test->postJson("/api/loans/{$source->id}/restructure", array_merge([
        'borrower_id' => $source->borrower_id,
        'loan_product_id' => $source->loan_product_id,
        'principal_amount' => 70800.00,
        'start_date' => now()->toDateString(),
    ], $overrides));
}

/**
 * Create a Co-makers-tab entry under $owner's own borrower profile — exactly
 * what CoMakerController::store() persists: `borrower_id` set to $owner (the
 * tab owner), never to the person the entry names.
 */
function coMakerLinkTabEntry(TestCase $test, Borrower $owner, array $attributes = []): TestResponse
{
    return $test->postJson("/api/borrowers/{$owner->id}/co-makers", array_merge([
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
    ], $attributes));
}

/**
 * Who each of the loan's co-makers is, as [member id, name].
 *
 * @return list<array{int, string}>
 */
function coMakerLinkPeopleOn(int $loanId): array
{
    return Loan::findOrFail($loanId)->coMakers()->orderBy('co_makers.id')->get()
        ->map(fn (CoMaker $coMaker) => [$coMaker->borrower_id, "{$coMaker->first_name} {$coMaker->last_name}"])
        ->all();
}

/**
 * @return array{int, string}
 */
function coMakerLinkPerson(Borrower $member): array
{
    return [$member->id, "{$member->first_name} {$member->last_name}"];
}

it('binds the member picked, not the co-maker record that carries the same number', function () {
    // Loan A picks member X, and the loan form creates X's co-maker record.
    $principalA = coMakerLinkMember();
    $principalB = coMakerLinkMember();
    $x = coMakerLinkMember(['first_name' => 'Xavier', 'last_name' => 'Ocampo']);

    $loanA = coMakerLinkApply($this, $principalA, [$x->id])->assertCreated()->json('data.id');
    $xRecord = CoMaker::where('borrower_id', $x->id)->sole();
    expect($xRecord->member_borrower_id)->toBe($x->id);

    // Loan B then picks the member whose id is that record's number.
    $y = coMakerLinkMemberWithId($xRecord->id);
    expect($y->id)->not->toBe($x->id, 'precondition: the shared number must belong to a different member');

    $loanB = coMakerLinkApply($this, $principalB, [$y->id])->assertCreated()->json('data.id');

    expect(coMakerLinkPeopleOn($loanB))->toBe([coMakerLinkPerson($y)])
        ->and(coMakerLinkPeopleOn($loanA))->toBe([coMakerLinkPerson($x)]);
});

it('binds the member picked again, not a co-maker record that later took their number', function () {
    // The other order. Member Y is picked first and gets a co-maker record of
    // their own; only afterwards does a record numbered with Y's id come to
    // exist, here an entry on another member's Co-makers tab. Picking Y again
    // must reuse Y's own record. (The extra member first keeps Y's id clear of
    // Y's own record number on a freshly migrated database.)
    coMakerLinkMember();
    $y = coMakerLinkMember(['first_name' => 'Yolanda', 'last_name' => 'Reyes']);

    $first = coMakerLinkApply($this, coMakerLinkMember(), [$y->id])->assertCreated()->json('data.id');
    $yRecord = CoMaker::where('borrower_id', $y->id)->sole();
    expect($yRecord->id)->not->toBe($y->id, 'precondition: Y\'s own record must carry a different number');

    CoMaker::factory()->create([
        'id' => $y->id,
        'borrower_id' => coMakerLinkMember()->id,
        'first_name' => 'Someone',
        'last_name' => 'Else',
    ]);

    $second = coMakerLinkApply($this, coMakerLinkMember(), [$y->id])->assertCreated()->json('data.id');

    expect(Loan::findOrFail($second)->coMakers()->pluck('co_makers.id')->all())->toBe([$yRecord->id])
        ->and(coMakerLinkPeopleOn($first))->toBe([coMakerLinkPerson($y)]);
});

it('reuses each member\'s own co-maker record across loans', function () {
    $x = coMakerLinkMember();
    $y = coMakerLinkMember();

    $one = coMakerLinkApply($this, coMakerLinkMember(), [$x->id, $y->id])->assertCreated()->json('data.id');
    $two = coMakerLinkApply($this, coMakerLinkMember(), [$y->id, $x->id])->assertCreated()->json('data.id');

    expect(CoMaker::whereIn('borrower_id', [$x->id, $y->id])->count())->toBe(2)
        ->and(Loan::findOrFail($two)->coMakers()->pluck('co_makers.id')->sort()->values()->all())
        ->toBe(Loan::findOrFail($one)->coMakers()->pluck('co_makers.id')->sort()->values()->all());
});

it('refuses a rejected member even when a co-maker record carries their number', function () {
    // The old reading let the shared number walk straight past the
    // rejected-member rule: it passed validation as "an existing co-maker",
    // and the loan was bound to whoever that record was.
    $rejected = coMakerLinkMember(['status' => 'rejected']);
    CoMaker::factory()->create(['id' => $rejected->id, 'borrower_id' => coMakerLinkMember()->id]);
    $principal = coMakerLinkMember();

    coMakerLinkApply($this, $principal, [$rejected->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['co_maker_ids.0']);

    expect(Loan::where('borrower_id', $principal->id)->exists())->toBeFalse();
});

it('still accepts a pending member as a co-maker', function () {
    $pending = coMakerLinkMember(['status' => 'pending']);

    $loan = coMakerLinkApply($this, coMakerLinkMember(), [$pending->id])->assertCreated()->json('data.id');

    expect(coMakerLinkPeopleOn($loan))->toBe([coMakerLinkPerson($pending)]);
});

/*
 * The deeper root-cause bug: `coMakerRecordFor()` used to find-or-create on
 * `borrower_id` + name, with no suffix, and the Co-makers tab writes
 * `borrower_id` as the TAB OWNER. A tab entry describing a member's relative
 * by name — no suffix — collided with that member's OWN co-maker record the
 * moment they were picked as a co-maker themselves. `member_borrower_id` (a
 * member's own id, unique, and nothing else) is what closes it. Both orders
 * must be safe: whichever entry is created first, the other must not reuse it.
 */

it('does not reuse a same-named Co-makers-tab entry when that member is later picked as a co-maker', function () {
    // Jr. has a Co-makers-tab entry, filed under his OWN borrower profile,
    // describing his father Sr. by name only — no suffix, so nothing in the
    // old lookup key distinguished it from Jr. himself.
    $jr = coMakerLinkMember(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);
    $tabEntryId = coMakerLinkTabEntry($this, $jr)->assertCreated()->json('data.id');

    // Jr. is independently picked as a co-maker on someone else's loan.
    $loan = coMakerLinkApply($this, coMakerLinkMember(), [$jr->id])->assertCreated()->json('data.id');

    $ownRecord = CoMaker::where('member_borrower_id', $jr->id)->sole();

    expect($ownRecord->id)->not->toBe($tabEntryId)
        ->and(CoMaker::findOrFail($tabEntryId)->member_borrower_id)->toBeNull()
        ->and(CoMaker::count())->toBe(2)
        ->and(Loan::findOrFail($loan)->coMakers()->pluck('co_makers.id')->all())->toBe([$ownRecord->id]);
});

it('does not let a later Co-makers-tab entry collide with an existing member co-maker record', function () {
    // Same collision, order reversed: Jr. is picked as a co-maker FIRST, and
    // only afterwards does the same-named tab entry get filed under him.
    $jr = coMakerLinkMember(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);

    $loan = coMakerLinkApply($this, coMakerLinkMember(), [$jr->id])->assertCreated()->json('data.id');
    $ownRecord = CoMaker::where('member_borrower_id', $jr->id)->sole();

    $tabEntryId = coMakerLinkTabEntry($this, $jr)->assertCreated()->json('data.id');

    expect($tabEntryId)->not->toBe($ownRecord->id)
        ->and(CoMaker::findOrFail($tabEntryId)->member_borrower_id)->toBeNull()
        ->and(CoMaker::count())->toBe(2)
        ->and(Loan::findOrFail($loan)->coMakers()->pluck('co_makers.id')->all())->toBe([$ownRecord->id]);
});

/*
 * Restructure now reads `co_maker_ids` the same way POST /loans does: member
 * ids only. These pin that a bare co-maker record id no longer resolves, and
 * that a member id still does.
 */

it('no longer lets restructure carry a bare co-maker record id', function () {
    // The restructure form used to pre-fill the source loan's `co_makers[].id`
    // values directly; that reading is gone.
    $source = $this->createReleasedLoan();
    $record = CoMaker::findOrFail(
        coMakerLinkApply($this, coMakerLinkMember(), [coMakerLinkMember()->id])->assertCreated()->json('data.co_makers.0.id')
    );
    $source->coMakers()->sync([$record->id]);

    coMakerLinkRestructure($this, $source, ['co_maker_ids' => [$record->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['co_maker_ids.0']);
});

it('still inherits the source loan\'s co-makers when restructure omits the field', function () {
    $source = $this->createReleasedLoan();
    $record = CoMaker::findOrFail(
        coMakerLinkApply($this, coMakerLinkMember(), [coMakerLinkMember()->id])->assertCreated()->json('data.co_makers.0.id')
    );
    $source->coMakers()->sync([$record->id]);

    $newLoan = coMakerLinkRestructure($this, $source)->assertCreated()->json('data.id');

    expect(Loan::findOrFail($newLoan)->coMakers()->pluck('co_makers.id')->all())->toBe([$record->id]);
});

it('still drops the co-makers when restructure sends an empty list or null', function (?array $coMakerIds) {
    $source = $this->createReleasedLoan();
    $record = CoMaker::factory()->create(['borrower_id' => $source->borrower_id]);
    $source->coMakers()->sync([$record->id]);

    $newLoan = coMakerLinkRestructure($this, $source, ['co_maker_ids' => $coMakerIds])->assertCreated()->json('data.id');

    expect(Loan::findOrFail($newLoan)->coMakers()->count())->toBe(0);
})->with([
    'an empty list' => [[]],
    'null' => [null],
]);

it('still resolves a restructure member id to that member\'s own co-maker record', function () {
    $source = $this->createReleasedLoan();
    $member = coMakerLinkMember();

    $newLoan = coMakerLinkRestructure($this, $source, ['co_maker_ids' => [$member->id]])->assertCreated()->json('data.id');

    expect(coMakerLinkPeopleOn($newLoan))->toBe([coMakerLinkPerson($member)]);
});

/*
 * PATCH /loans/{id} previously left `co_maker_ids` almost entirely
 * unresolved: a raw sync() against bare co-maker record ids, validated only as
 * `exists:co_makers,id`. It now reads member ids exactly like POST /loans.
 */

it('update binds the member picked, not a co-maker record that carries the same number', function () {
    $principal = coMakerLinkMember();
    $x = coMakerLinkMember(['first_name' => 'Xavier', 'last_name' => 'Ocampo']);
    $loanId = coMakerLinkApply($this, $principal, [$x->id])->assertCreated()->json('data.id');
    $xRecord = CoMaker::where('borrower_id', $x->id)->sole();

    $y = coMakerLinkMemberWithId($xRecord->id);
    expect($y->id)->not->toBe($x->id, 'precondition: the shared number must belong to a different member');

    $this->patchJson("/api/loans/{$loanId}", ['co_maker_ids' => [$y->id]])->assertOk();

    expect(coMakerLinkPeopleOn($loanId))->toBe([coMakerLinkPerson($y)]);
});

it('refuses a bare co-maker record id on update', function () {
    $loanId = coMakerLinkApply($this, coMakerLinkMember(), [])->assertCreated()->json('data.id');
    $coMaker = CoMaker::factory()->create(['borrower_id' => coMakerLinkMember()->id]);

    $this->patchJson("/api/loans/{$loanId}", ['co_maker_ids' => [$coMaker->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['co_maker_ids.0']);

    expect(Loan::findOrFail($loanId)->coMakers()->count())->toBe(0);
});

it('refuses a rejected member as an update co-maker even when a co-maker record carries their number', function () {
    $rejected = coMakerLinkMember(['status' => 'rejected']);
    CoMaker::factory()->create(['id' => $rejected->id, 'borrower_id' => coMakerLinkMember()->id]);
    $loanId = coMakerLinkApply($this, coMakerLinkMember(), [])->assertCreated()->json('data.id');

    $this->patchJson("/api/loans/{$loanId}", ['co_maker_ids' => [$rejected->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['co_maker_ids.0']);

    expect(Loan::findOrFail($loanId)->coMakers()->count())->toBe(0);
});

it('update drops the co-makers when sent an empty list', function () {
    $member = coMakerLinkMember();
    $loanId = coMakerLinkApply($this, coMakerLinkMember(), [$member->id])->assertCreated()->json('data.id');
    expect(Loan::findOrFail($loanId)->coMakers()->count())->toBe(1);

    $this->patchJson("/api/loans/{$loanId}", ['co_maker_ids' => []])->assertOk();

    expect(Loan::findOrFail($loanId)->coMakers()->count())->toBe(0);
});

it('update leaves the co-makers untouched when the key is omitted', function () {
    $member = coMakerLinkMember();
    $loanId = coMakerLinkApply($this, coMakerLinkMember(), [$member->id])->assertCreated()->json('data.id');

    $this->patchJson("/api/loans/{$loanId}", ['purpose' => 'Updated purpose'])->assertOk();

    expect(coMakerLinkPeopleOn($loanId))->toBe([coMakerLinkPerson($member)]);
});
