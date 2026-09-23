<?php

/**
 * POST /loans reads `co_maker_ids` as MEMBER (borrower) ids, and only as those.
 *
 * That is all the loan form's co-maker picker has ever sent: its options are
 * the member list and each slot holds a member's id. The API used to read each
 * id as a co-maker RECORD id first and fall back to a member id. The two are
 * separate sequences, and the loan form itself creates a co-maker record the
 * first time a member is picked, so the numbers collide almost at once: member
 * #7 picked on one loan gets co-maker record #1, and member #1 picked on the
 * next loan was then bound to member #7. A co-maker is jointly liable for the
 * loan, so that is the wrong person owing the money, silently.
 *
 * The restructure form still sends both kinds in one array (it pre-fills the
 * source loan's co-maker RECORD ids and its picker adds MEMBER ids), so
 * restructure keeps reading either until that contract is settled; the specs
 * at the bottom pin that its behaviour did not move.
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
 * Restructure is deliberately unchanged: its form pre-fills the source loan's
 * co-maker RECORD ids, so it still reads either kind. These pin that.
 */

it('still lets restructure carry the source loan\'s co-makers by their record ids', function () {
    // Exactly what the restructure form sends back: the source loan's
    // `co_makers[].id` values, untouched.
    $source = $this->createReleasedLoan();
    $record = CoMaker::findOrFail(
        coMakerLinkApply($this, coMakerLinkMember(), [coMakerLinkMember()->id])->assertCreated()->json('data.co_makers.0.id')
    );
    $source->coMakers()->sync([$record->id]);

    $newLoan = coMakerLinkRestructure($this, $source, ['co_maker_ids' => [$record->id]])->assertCreated()->json('data.id');

    expect(Loan::findOrFail($newLoan)->coMakers()->pluck('co_makers.id')->all())->toBe([$record->id]);
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
