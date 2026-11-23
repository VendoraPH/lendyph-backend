<?php

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\User;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->branch = Branch::first();
    $this->admin = User::where('username', 'super_admin')->first();
    $this->actingAs($this->admin);
});

function createBorrower(array $overrides = []): Borrower
{
    /** @var Branch $branch */
    $branch = Branch::first();

    return Borrower::factory()->create(array_merge([
        'first_name' => 'Juan',
        'middle_name' => 'Santos',
        'last_name' => 'Dela Cruz',
        'birthdate' => '1990-01-15',
        'branch_id' => $branch->id,
    ], $overrides));
}

function registerPayload(array $overrides = []): array
{
    /** @var Branch $branch */
    $branch = Branch::first();

    return array_merge([
        'first_name' => 'Juan',
        'middle_name' => 'Santos',
        'last_name' => 'Dela Cruz',
        'birthdate' => '1990-01-15',
        'branch_id' => $branch->id,
        'gender' => 'male',
        'civil_status' => 'single',
        'contact_number' => '09170000222',
        'address' => '12 Mabini St',
        'city' => 'Butuan',
        'province' => 'Agusan del Norte',
    ], $overrides);
}

it('rejects exact first+middle+last duplicate', function () {
    createBorrower();

    $this->postJson('/api/borrowers', registerPayload())
        ->assertStatus(422)
        ->assertJsonPath('errors.first_name.0', fn ($msg) => str_contains($msg, 'similar member already exists')
            && str_contains($msg, 'BRW-'));
});

it('rejects case-insensitive duplicate', function () {
    createBorrower(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);

    $this->postJson('/api/borrowers', registerPayload([
        'first_name' => 'JUAN',
        'last_name' => 'DELA CRUZ',
    ]))->assertStatus(422);
});

it('rejects duplicate with collapsed whitespace', function () {
    createBorrower(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);

    $this->postJson('/api/borrowers', registerPayload([
        'first_name' => '  Juan  ',
        'last_name' => 'Dela  Cruz',
    ]))->assertStatus(422);
});

it('rejects fuzzy spelling match when birthdate matches', function () {
    createBorrower([
        'first_name' => 'Juan',
        'middle_name' => 'Santos',
        'last_name' => 'Dela Cruz',
        'birthdate' => '1990-01-15',
    ]);

    // "Jaun" is 1 edit away from "Juan" — same birthdate → reject
    $this->postJson('/api/borrowers', registerPayload([
        'first_name' => 'Jaun',
        'birthdate' => '1990-01-15',
    ]))->assertStatus(422);
});

it('allows fuzzy spelling match when birthdate differs', function () {
    createBorrower([
        'first_name' => 'Juan',
        'middle_name' => 'Santos',
        'last_name' => 'Dela Cruz',
        'birthdate' => '1990-01-15',
    ]);

    // Same typo but different DOB → allowed (different person)
    $this->postJson('/api/borrowers', registerPayload([
        'first_name' => 'Jaun',
        'birthdate' => '1992-05-20',
    ]))->assertCreated();
});

it('allows clearly different names even on matching birthdate', function () {
    createBorrower([
        'first_name' => 'Juan',
        'middle_name' => 'Santos',
        'last_name' => 'Dela Cruz',
        'birthdate' => '1990-01-15',
    ]);

    // Distance > 2 → not a fuzzy match, allowed
    $this->postJson('/api/borrowers', registerPayload([
        'first_name' => 'Roberto',
        'middle_name' => 'Pedro',
        'last_name' => 'Reyes',
        'birthdate' => '1990-01-15',
    ]))->assertCreated();
});

it('allows duplicate creation when force=true is sent', function () {
    createBorrower();

    $this->postJson('/api/borrowers', registerPayload(['force' => true]))
        ->assertCreated();
});

it('does not store the force flag on the borrower row', function () {
    $response = $this->postJson('/api/borrowers', registerPayload([
        'first_name' => 'Unique',
        'last_name' => 'Person',
        'force' => true,
    ]))->assertCreated();

    $borrower = Borrower::find($response->json('data.id'));

    expect($borrower)->not->toBeNull();
    // `force` is not a column — attempting to read it should return null / missing attribute
    expect($borrower->getAttribute('force'))->toBeNull();
});

it('ignores the current borrower when updating (no self-duplicate)', function () {
    $borrower = createBorrower();

    // Updating the same borrower with the same name must not trigger the duplicate rule
    $this->putJson("/api/borrowers/{$borrower->id}", [
        'first_name' => 'Juan',
        'middle_name' => 'Santos',
        'last_name' => 'Dela Cruz',
        'birthdate' => '1990-01-15',
    ])->assertSuccessful();
});

/*
 * The duplicate check compares text. A name or birthdate sent as a list is
 * left to that field's own rules, which answer 422; reading it as text answered
 * 500 (security review, 2026-11-24).
 */
it('answers 422, never 500, when a public registration sends a name or the birthdate as a list', function (string $field) {
    $this->app['auth']->forgetGuards();

    $this->postJson('/api/borrowers', registerPayload(['status' => 'pending', $field => ['x']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with(['first_name', 'middle_name', 'last_name', 'birthdate']);

it('answers 422, never 500, when a member edit sends a name or the birthdate as a list', function (string $field) {
    $borrower = createBorrower();

    $this->putJson("/api/borrowers/{$borrower->id}", [
        'first_name' => 'Juan',
        'middle_name' => 'Santos',
        'last_name' => 'Dela Cruz',
        'birthdate' => '1990-01-15',
        $field => ['x'],
    ])->assertUnprocessable()->assertJsonValidationErrors($field);
})->with(['first_name', 'middle_name', 'last_name', 'birthdate']);

it('refuses a public registration whose birthdate is sent as a number, so a number cannot slip a duplicate past the check', function (int|float|string $birthdate) {
    createBorrower();
    $this->app['auth']->forgetGuards();
    $before = Borrower::count();

    // The duplicate check steps aside for a number; the birthdate's own rule
    // (written year-month-day) refuses it.
    $this->postJson('/api/borrowers', registerPayload(['status' => 'pending', 'birthdate' => $birthdate]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('birthdate');

    expect(Borrower::count())->toBe($before);
})->with([19900115, 19900115.0, '19900115']);
