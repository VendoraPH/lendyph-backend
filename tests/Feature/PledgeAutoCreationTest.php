<?php

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\ShareCapitalPledge;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Artisan::call('migrate:fresh');
    $this->seed(DatabaseSeeder::class);
    $this->branch = Branch::first();
    $this->admin = User::where('username', 'super_admin')->first();
    $this->actingAs($this->admin);
});

it('auto-creates a pledge when a borrower is created', function () {
    $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

    expect($borrower->shareCapitalPledge)->not->toBeNull();
    expect($borrower->shareCapitalPledge->amount)->toBe('0.00');
    expect($borrower->shareCapitalPledge->schedule)->toBe('15/30');
    expect($borrower->shareCapitalPledge->auto_credit)->toBeFalse();
});

it('returns pledges for all borrowers via GET /api/pledges', function () {
    $borrowers = Borrower::factory()->count(3)->create(['branch_id' => $this->branch->id]);

    $response = $this->getJson('/api/pledges?per_page=100')->assertSuccessful();

    $pledgeIds = collect($response->json('data'))->pluck('borrower_id');
    $borrowers->each(fn ($b) => expect($pledgeIds)->toContain($b->id));
});

it('can update an auto-created pledge', function () {
    $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    $pledge = $borrower->shareCapitalPledge;

    $this->putJson("/api/pledges/{$pledge->id}", ['amount' => 750, 'schedule' => '15'])
        ->assertSuccessful()
        ->assertJsonPath('data.amount', 750)
        ->assertJsonPath('data.schedule', '15');
});

it('can toggle auto-credit on an auto-created pledge', function () {
    $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    $pledge = $borrower->shareCapitalPledge;

    $this->patchJson("/api/pledges/{$pledge->id}/auto-credit")
        ->assertSuccessful()
        ->assertJsonPath('auto_credit', true);

    $this->assertDatabaseHas('share_capital_pledges', ['id' => $pledge->id, 'auto_credit' => true]);
});

it('deletes pledge and ledger when borrower is deleted', function () {
    $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    $pledgeId = $borrower->shareCapitalPledge->id;

    // Create a ledger entry to verify cascade cleanup
    $this->postJson('/api/share-capital/ledger', [
        'borrower_id' => $borrower->id,
        'date' => now()->toDateString(),
        'description' => 'Test entry',
        'type' => 'credit',
        'amount' => 100,
    ])->assertCreated();

    $this->deleteJson("/api/borrowers/{$borrower->id}")
        ->assertSuccessful();

    $this->assertDatabaseMissing('share_capital_pledges', ['id' => $pledgeId]);
    $this->assertDatabaseMissing('share_capital_ledger', ['borrower_id' => $borrower->id]);
});

it('backfills pledges for borrowers missing them', function () {
    // Create borrowers then delete their auto-created pledges to simulate pre-fix state
    $b1 = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    $b2 = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    ShareCapitalPledge::where('borrower_id', $b1->id)->delete();
    ShareCapitalPledge::where('borrower_id', $b2->id)->delete();

    expect(ShareCapitalPledge::whereIn('borrower_id', [$b1->id, $b2->id])->count())->toBe(0);

    Artisan::call('pledges:backfill');

    expect(ShareCapitalPledge::whereIn('borrower_id', [$b1->id, $b2->id])->count())->toBe(2);
});

it('backfill dry-run shows count without creating', function () {
    $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    ShareCapitalPledge::where('borrower_id', $borrower->id)->delete();

    Artisan::call('pledges:backfill', ['--dry-run' => true]);

    expect(ShareCapitalPledge::where('borrower_id', $borrower->id)->exists())->toBeFalse();
    expect(Artisan::output())->toContain('1 borrower(s) without pledges');
});
