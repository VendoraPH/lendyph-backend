<?php

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Artisan::call('migrate:fresh');
    $this->seed(DatabaseSeeder::class);
    $this->branch = Branch::first();
    $this->admin = User::where('username', 'admin')->first();
    $this->actingAs($this->admin);
    Storage::fake('public');
});

it('rejects duplicate email on create', function () {
    Borrower::factory()->create(['branch_id' => $this->branch->id, 'email' => 'taken@example.com']);

    $this->postJson('/api/borrowers', [
        'first_name' => 'Some',
        'last_name' => 'One',
        'email' => 'taken@example.com',
        'branch_id' => $this->branch->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('rejects pledge_amount above the max cap', function () {
    $this->postJson('/api/borrowers', [
        'first_name' => 'Big',
        'last_name' => 'Pledger',
        'branch_id' => $this->branch->id,
        'pledge_amount' => 99999999,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['pledge_amount']);
});

it('rejects invalid contact_number format', function () {
    $this->postJson('/api/borrowers', [
        'first_name' => 'Bad',
        'last_name' => 'Number',
        'branch_id' => $this->branch->id,
        'contact_number' => 'not-a-phone',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['contact_number']);
});

it('accepts PH mobile and international contact_number formats', function () {
    $this->postJson('/api/borrowers', [
        'first_name' => 'Valid',
        'last_name' => 'One',
        'branch_id' => $this->branch->id,
        'contact_number' => '09171234567',
    ])->assertCreated();

    $this->postJson('/api/borrowers', [
        'first_name' => 'Valid',
        'last_name' => 'Two',
        'branch_id' => $this->branch->id,
        'contact_number' => '+639171234568',
    ])->assertCreated();
});

it('rejects birthdate before 1900', function () {
    $this->postJson('/api/borrowers', [
        'first_name' => 'Ancient',
        'last_name' => 'One',
        'branch_id' => $this->branch->id,
        'birthdate' => '1850-01-01',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['birthdate']);
});

it('accepts pledge_amount on borrower create and uses it for the pledge', function () {
    $response = $this->postJson('/api/borrowers', [
        'first_name' => 'Test',
        'last_name' => 'User',
        'branch_id' => $this->branch->id,
        'pledge_amount' => 1500,
    ])->assertCreated();

    $borrowerId = $response->json('data.id');
    $borrower = Borrower::find($borrowerId);

    expect((float) $borrower->pledge_amount)->toBe(1500.0);
    expect((float) $borrower->shareCapitalPledge->amount)->toBe(1500.0);
});

it('defaults pledge_amount to zero when not provided', function () {
    $response = $this->postJson('/api/borrowers', [
        'first_name' => 'Test',
        'last_name' => 'User',
        'branch_id' => $this->branch->id,
    ])->assertCreated();

    $borrower = Borrower::find($response->json('data.id'));

    expect((float) $borrower->pledge_amount)->toBe(0.0);
    expect((float) $borrower->shareCapitalPledge->amount)->toBe(0.0);
});

it('accepts structured address fields on borrower create', function () {
    $response = $this->postJson('/api/borrowers', [
        'first_name' => 'Test',
        'last_name' => 'User',
        'branch_id' => $this->branch->id,
        'street_address' => '123 Main St',
        'barangay' => 'Poblacion',
        'city' => 'Butuan',
        'province' => 'Agusan del Norte',
    ])->assertCreated();

    $borrower = Borrower::find($response->json('data.id'));

    expect($borrower->street_address)->toBe('123 Main St');
    expect($borrower->barangay)->toBe('Poblacion');
    expect($borrower->city)->toBe('Butuan');
    expect($borrower->province)->toBe('Agusan del Norte');
});

it('exposes new fields in BorrowerResource', function () {
    $borrower = Borrower::factory()->create([
        'branch_id' => $this->branch->id,
        'street_address' => '456 Oak Ave',
        'city' => 'Cebu',
        'pledge_amount' => 750,
    ]);

    $response = $this->getJson("/api/borrowers/{$borrower->id}")->assertSuccessful();

    expect($response->json('data.street_address'))->toBe('456 Oak Ave');
    expect($response->json('data.city'))->toBe('Cebu');
    expect((float) $response->json('data.pledge_amount'))->toBe(750.0);
});

it('uploads valid id with front and back files plus id_number', function () {
    $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    $front = UploadedFile::fake()->image('front.jpg');
    $back = UploadedFile::fake()->image('back.jpg');

    $response = $this->postJson("/api/borrowers/{$borrower->id}/valid-ids", [
        'type' => "Driver's License",
        'id_number' => 'N01-23-456789',
        'front_file' => $front,
        'back_file' => $back,
    ])->assertCreated();

    $documents = $response->json('data');
    expect($documents)->toHaveCount(2);

    $this->assertDatabaseHas('documents', [
        'documentable_type' => Borrower::class,
        'documentable_id' => $borrower->id,
        'type' => 'valid_id',
        'label' => "Driver's License",
        'id_number' => 'N01-23-456789',
        'side' => 'front',
    ]);

    $this->assertDatabaseHas('documents', [
        'documentable_id' => $borrower->id,
        'side' => 'back',
    ]);
});

it('uploads valid id with only front_file (no back)', function () {
    $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

    $response = $this->postJson("/api/borrowers/{$borrower->id}/valid-ids", [
        'type' => 'PhilSys ID',
        'front_file' => UploadedFile::fake()->image('philsys.jpg'),
    ])->assertCreated();

    expect($response->json('data'))->toHaveCount(1);
});

it('still supports legacy single file upload', function () {
    $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

    $response = $this->postJson("/api/borrowers/{$borrower->id}/valid-ids", [
        'type' => 'PhilSys ID',
        'file' => UploadedFile::fake()->image('philsys.jpg'),
    ])->assertCreated();

    // Legacy shape: returns single document object
    expect($response->json('data.type'))->toBe('valid_id');
    expect($response->json('data.label'))->toBe('PhilSys ID');
});
