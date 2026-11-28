<?php

use App\Models\Borrower;
use App\Models\CicSubject;
use App\Models\CoMaker;
use App\Services\BorrowerPurgeService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

/*
 * A member in the CIC subject register keeps their record (the CIC build plan,
 * decision 3): a purge is refused in words, before anything is touched, as for
 * AMLA records. That covers a member subject and a co-maker subject that is
 * theirs. A member the register doesn't hold is purged as before.
 */

beforeEach(function () {
    Storage::fake('private');
});

function cicPurgeRefusal(Borrower $member): ?array
{
    try {
        app(BorrowerPurgeService::class)->purge($member->fresh());
    } catch (ValidationException $refused) {
        return $refused->errors()['borrower'] ?? [];
    }

    return null;
}

it('refuses to purge a member in the subject register', function () {
    $member = Borrower::factory()->create();
    CicSubject::factory()->forMember($member)->create();

    expect(cicPurgeRefusal($member))->toBe([BorrowerPurgeService::CIC_REPORTED])
        ->and(BorrowerPurgeService::CIC_REPORTED)->not->toContain('cic_')
        ->and(Borrower::query()->whereKey($member->id)->exists())->toBeTrue()
        ->and($member->fresh()->cicSubject)->not->toBeNull();
});

it('refuses to purge a member whose co-maker, or who as a co-maker, is in the register', function () {
    $borrower = Borrower::factory()->create();
    CicSubject::factory()->coMaker(CoMaker::factory()->create(['borrower_id' => $borrower->id]))->create();

    $member = Borrower::factory()->create();
    CicSubject::factory()->coMaker(CoMaker::factory()->create(['member_borrower_id' => $member->id]))->create();

    expect(cicPurgeRefusal($borrower))->toBe([BorrowerPurgeService::CIC_REPORTED])
        ->and(cicPurgeRefusal($member))->toBe([BorrowerPurgeService::CIC_REPORTED])
        ->and(Borrower::query()->whereKey([$borrower->id, $member->id])->count())->toBe(2);
});

it('purges a member the register doesn\'t hold, as before', function () {
    CicSubject::factory()->create();
    $member = Borrower::factory()->create();

    expect(cicPurgeRefusal($member))->toBeNull()
        ->and(Borrower::query()->whereKey($member->id)->exists())->toBeFalse();
});
