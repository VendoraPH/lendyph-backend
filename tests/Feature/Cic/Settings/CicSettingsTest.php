<?php

use App\Models\AuditLog;
use App\Models\CicSetting;
use App\Services\Cic\CicAccess;
use Tests\Feature\Cic\Settings\CicTestKeys;
use Tests\TestCase;

uses(TestCase::class);

/*
 * GET and PUT /cic/settings: the provider code (8 letters or digits, upper
 * case), MFI status, institution and contact; the fixed format version; an
 * audit row with the old and new values of what changed.
 */

beforeEach(function () {
    config(['cic.enabled' => true]);
    $this->admin = CicTestKeys::staff([CicAccess::SETTINGS, CicAccess::VIEW]);
});

it('shows a new deployment\'s settings with the fixed format version', function () {
    $this->actingAs(CicTestKeys::staff([CicAccess::VIEW]))->getJson('/api/cic/settings')
        ->assertOk()
        ->assertExactJson(['success' => true, 'data' => [
            'provider_code' => null,
            'format_version' => '1.0',
            'is_mfi' => false,
            'institution_name' => null,
            'contact_name' => null,
            'contact_email' => null,
            'contact_phone' => null,
            'updated_by' => null,
            'updated_at' => CicSetting::current()->updated_at?->toJSON(),
        ]]);
});

it('saves the settings, the provider code in upper case, and audits old and new values', function () {
    $this->actingAs($this->admin)->putJson('/api/cic/settings', [
        'provider_code' => ' abcd1234 ',
        'is_mfi' => true,
        'institution_name' => 'Binhs Cooperative',
        'contact_name' => 'Ana Cruz',
        'contact_email' => 'cic@example.com',
        'contact_phone' => '+63 2 8123 4567',
        'format_version' => '1.0',
    ])->assertOk()
        ->assertJsonPath('data.provider_code', 'ABCD1234')
        ->assertJsonPath('data.is_mfi', true)
        ->assertJsonPath('data.contact_email', 'cic@example.com')
        ->assertJsonPath('data.updated_by.id', $this->admin->id);

    $audit = AuditLog::query()->where('auditable_type', CicSetting::class)->sole();
    expect($audit->old_values['provider_code'])->toBeNull()
        ->and($audit->new_values['provider_code'])->toBe('ABCD1234')
        ->and($audit->new_values['is_mfi'])->toBeTrue()
        ->and($audit->description)->toBe('CIC settings changed: provider code, MFI status, institution name, contact name, contact email, contact phone.');

    $this->actingAs($this->admin)->putJson('/api/cic/settings', ['provider_code' => 'ZZZZ9999'])->assertOk();

    $second = AuditLog::query()->where('auditable_type', CicSetting::class)->latest('id')->first();
    expect($second->old_values)->toBe(['provider_code' => 'ABCD1234'])
        ->and($second->new_values)->toBe(['provider_code' => 'ZZZZ9999'])
        ->and(CicSetting::current()->institution_name)->toBe('Binhs Cooperative');
});

it('writes no audit row for a save that changes nothing', function () {
    $this->actingAs($this->admin)->putJson('/api/cic/settings', ['provider_code' => 'ABCD1234'])->assertOk();
    $this->actingAs($this->admin)->putJson('/api/cic/settings', ['provider_code' => 'abcd1234'])->assertOk();

    expect(AuditLog::query()->where('auditable_type', CicSetting::class)->count())->toBe(1);
});

it('refuses a provider code that isn\'t 8 letters or digits', function (string $code) {
    $this->actingAs($this->admin)->putJson('/api/cic/settings', ['provider_code' => $code])
        ->assertUnprocessable()
        ->assertJsonPath('errors.provider_code.0', 'The provider code is the 8 letters or digits CIC assigned to the lender, with no spaces or symbols.');
})->with(['ABC1234', 'ABCD12345', 'ABCD-123', 'ABCD 123', 'ÁBCD1234']);

it('refuses a change to the format version and bad contact details', function () {
    $this->actingAs($this->admin)->putJson('/api/cic/settings', ['format_version' => '2.0'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.format_version.0', 'The format version is fixed at 1.0 (CIC\'s 2016 CSDF) and can\'t be changed.');

    $this->actingAs($this->admin)->putJson('/api/cic/settings', [
        'contact_email' => 'not-an-email',
        'contact_phone' => 'call me',
        'institution_name' => str_repeat('x', 161),
    ])->assertUnprocessable()->assertJsonValidationErrors(['contact_email', 'contact_phone', 'institution_name']);

    expect(CicSetting::current()->format_version)->toBe('1.0');
});

it('needs cic:settings to save and head-office access for both, refused in words', function () {
    $viewer = CicTestKeys::staff([CicAccess::VIEW]);

    $this->actingAs($viewer)->putJson('/api/cic/settings', ['provider_code' => 'ABCD1234'])
        ->assertForbidden()->assertJsonPath('message', 'You don\'t have access to manage CIC settings.');

    $this->actingAs(CicTestKeys::staff([CicAccess::VIEW, CicAccess::SETTINGS], viewAll: false))->getJson('/api/cic/settings')
        ->assertForbidden()->assertJsonPath('message', CicAccess::VIEW_ALL_REFUSAL);

    $this->actingAs(CicTestKeys::staff([CicAccess::PREPARE]))->getJson('/api/cic/settings')->assertForbidden();
});

it('answers 404 while CIC is off', function () {
    config(['cic.enabled' => false]);

    $this->actingAs($this->admin)->getJson('/api/cic/settings')->assertNotFound();
    $this->actingAs($this->admin)->putJson('/api/cic/settings', ['provider_code' => 'ABCD1234'])->assertNotFound();
});
