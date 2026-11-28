<?php

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\CicCodeMapping;
use App\Models\CollateralType;
use App\Models\Document;
use App\Services\Cic\CicAccess;
use App\Services\Cic\Settings\CicIdKinds;
use Tests\Feature\Cic\Settings\CicTestKeys;
use Tests\TestCase;

uses(TestCase::class);

/*
 * GET and PUT /cic/code-mappings: collateral types to guarantee codes, valid-ID
 * kinds to ID or identification type codes, suggested defaults that are never
 * saved by themselves, and audit rows in words.
 */

beforeEach(function () {
    config(['cic.enabled' => true]);
    $this->admin = CicTestKeys::staff([CicAccess::SETTINGS, CicAccess::VIEW]);
});

function cicValidId(string $label, ?string $customName = null): void
{
    Document::query()->create([
        'documentable_type' => (new Borrower)->getMorphClass(),
        'documentable_id' => Borrower::factory()->create()->id,
        'type' => 'valid_id',
        'label' => $label,
        'custom_type_name' => $customName,
        'file_path' => 'documents/test.jpg',
        'original_filename' => 'test.jpg',
    ]);
}

/** @return array<string, array<string, mixed>> */
function cicCodeRows(object $test, string $domain): array
{
    return collect($test->actingAs($test->admin)->getJson('/api/cic/code-mappings')->assertOk()->json("data.{$domain}"))
        ->keyBy('lendy_value')->all();
}

it('suggests guarantee codes for the seeded collateral types, unsaved', function () {
    $rows = collect(cicCodeRows($this, 'collateral_type'))->keyBy('name');

    expect($rows['Land Title']['suggested_code'])->toBe('201')
        ->and($rows['Chattel']['suggested_code'])->toBe('202')
        ->and($rows['Share Capital']['suggested_code'])->toBe('234')
        ->and($rows['Stock Certificate']['suggested_code'])->toBe('217')
        ->and($rows['Land Title']['suggested_label'])->toBe('Real Estate Mortgage - Not insured by HGC')
        ->and($rows['Land Title']['cic_code'])->toBeNull()
        ->and(CicCodeMapping::query()->count())->toBe(0);
});

it('suggests ID kind codes from the right code lists, and lists "others" names without digits', function () {
    cicValidId('others', 'GSIS ID');
    cicValidId('others', 'GSIS ID');
    cicValidId('others', 'Company ID 12345');
    cicValidId('others', null);
    cicValidId('passport');

    $rows = cicCodeRows($this, 'id_kind');

    expect($rows['tin_id'])->toMatchArray(['suggested_domain' => 'IdentificationTypeDomain', 'suggested_code' => '10', 'suggested_label' => 'TIN', 'standard' => true])
        ->and($rows['sss']['suggested_code'])->toBe('11')
        ->and($rows['umid']['suggested_code'])->toBe('15')
        ->and($rows['philippine_id'])->toMatchArray(['suggested_domain' => 'IDTypeDomain', 'suggested_code' => '32'])
        ->and($rows['drivers_license'])->toMatchArray(['suggested_domain' => 'IDTypeDomain', 'suggested_code' => '10', 'suggested_label' => 'Driver’s License'])
        ->and($rows['passport']['suggested_code'])->toBe('12')
        ->and($rows['prc_id']['suggested_code'])->toBe('13')
        ->and($rows['postal_id']['suggested_code'])->toBe('16')
        ->and($rows['voters_id']['suggested_code'])->toBe('11')
        ->and($rows['GSIS ID'])->toMatchArray(['standard' => false, 'suggested_code' => null])
        ->and(array_keys($rows))->not->toContain('others', 'Company ID 12345')
        ->and(CicIdKinds::valueOf('others', 'GSIS ID'))->toBe('GSIS ID')
        ->and(CicIdKinds::valueOf('others', 'ID 123'))->toBeNull();
});

it('saves mappings with their code list, shows them instead of suggestions, and audits in words', function () {
    cicValidId('others', 'GSIS ID');
    $land = CollateralType::query()->where('name', 'Land Title')->firstOrFail();

    $this->actingAs($this->admin)->putJson('/api/cic/code-mappings', ['mappings' => [
        ['domain' => 'collateral_type', 'lendy_value' => (string) $land->id, 'cic_code' => '201'],
        ['domain' => 'id_kind', 'lendy_value' => 'GSIS ID', 'cic_domain' => 'IdentificationTypeDomain', 'cic_code' => '12'],
        ['domain' => 'id_kind', 'lendy_value' => 'drivers_license', 'cic_domain' => 'IDTypeDomain', 'cic_code' => '10'],
    ]])->assertOk();

    expect(CicCodeMapping::query()->where('lendy_value', (string) $land->id)->sole()->cic_domain)->toBe('GuaranteesDomain')
        ->and(CicCodeMapping::query()->where('lendy_value', 'GSIS ID')->sole()->only(['cic_domain', 'cic_code']))
        ->toBe(['cic_domain' => 'IdentificationTypeDomain', 'cic_code' => '12']);

    $rows = cicCodeRows($this, 'id_kind');
    expect($rows['GSIS ID'])->toMatchArray(['cic_code' => '12', 'cic_label' => 'GSIS', 'suggested_code' => null])
        ->and($rows['tin_id']['suggested_code'])->toBe('10');

    $audit = AuditLog::query()->where('auditable_type', CicCodeMapping::class)->where('new_values->value', 'Land Title')->sole();
    expect($audit->action)->toBe('created')
        ->and($audit->description)->toBe('CIC code for collateral type "Land Title": none → 201 Real Estate Mortgage - Not insured by HGC (Guarantees).');

    $this->actingAs($this->admin)->putJson('/api/cic/code-mappings', ['mappings' => [
        ['domain' => 'collateral_type', 'lendy_value' => (string) $land->id, 'cic_code' => null],
        ['domain' => 'id_kind', 'lendy_value' => 'drivers_license', 'cic_domain' => 'IDTypeDomain', 'cic_code' => '10'],
    ]])->assertOk();

    expect(CicCodeMapping::query()->where('domain', 'collateral_type')->count())->toBe(0)
        ->and(AuditLog::query()->where('auditable_type', CicCodeMapping::class)->count())->toBe(4)
        ->and(AuditLog::query()->where('auditable_type', CicCodeMapping::class)->where('action', 'deleted')->sole()->description)
        ->toBe('CIC code for collateral type "Land Title" removed (was 201 Real Estate Mortgage - Not insured by HGC (Guarantees)).');
});

it('refuses codes outside their list, unknown values and an ID kind with no code list', function () {
    $land = CollateralType::query()->where('name', 'Land Title')->firstOrFail();

    $this->actingAs($this->admin)->putJson('/api/cic/code-mappings', ['mappings' => [
        ['domain' => 'collateral_type', 'lendy_value' => (string) $land->id, 'cic_code' => '999'],
        ['domain' => 'collateral_type', 'lendy_value' => '999999', 'cic_code' => '201'],
        ['domain' => 'id_kind', 'lendy_value' => 'tin_id', 'cic_code' => '10'],
        ['domain' => 'id_kind', 'lendy_value' => 'umid', 'cic_domain' => 'IdentificationTypeDomain', 'cic_code' => '32'],
        ['domain' => 'id_kind', 'lendy_value' => 'Made Up ID', 'cic_domain' => 'IDTypeDomain', 'cic_code' => '10'],
        ['domain' => 'collateral_type', 'lendy_value' => (string) $land->id, 'cic_domain' => 'IDTypeDomain', 'cic_code' => '10'],
        ['domain' => 'branch', 'lendy_value' => '1', 'cic_code' => '1'],
    ]])->assertUnprocessable()
        ->assertJsonValidationErrors(['mappings.6.domain' => 'A mapping is for a collateral type or an ID kind.']);

    $this->actingAs($this->admin)->putJson('/api/cic/code-mappings', ['mappings' => [
        ['domain' => 'collateral_type', 'lendy_value' => (string) $land->id, 'cic_code' => '999'],
        ['domain' => 'collateral_type', 'lendy_value' => '999999', 'cic_code' => '201'],
        ['domain' => 'id_kind', 'lendy_value' => 'tin_id', 'cic_code' => '10'],
        ['domain' => 'id_kind', 'lendy_value' => 'umid', 'cic_domain' => 'IdentificationTypeDomain', 'cic_code' => '32'],
        ['domain' => 'id_kind', 'lendy_value' => 'Made Up ID', 'cic_domain' => 'IDTypeDomain', 'cic_code' => '10'],
        ['domain' => 'collateral_type', 'lendy_value' => (string) $land->id, 'cic_domain' => 'IDTypeDomain', 'cic_code' => '10'],
    ]])->assertUnprocessable()
        ->assertJsonValidationErrors(['mappings.0.cic_code' => '"999" isn\'t one of CIC\'s guarantee codes.'])
        ->assertJsonValidationErrors(['mappings.1.lendy_value' => 'That collateral type doesn\'t exist. Reload the page.'])
        ->assertJsonValidationErrors(['mappings.2.cic_domain' => 'Choose whether this ID kind maps to CIC\'s ID types or its identification types.'])
        ->assertJsonValidationErrors(['mappings.3.cic_code' => '"32" isn\'t one of CIC\'s identification type codes.'])
        ->assertJsonValidationErrors(['mappings.4.lendy_value' => 'That ID kind isn\'t one Lendy stores. Reload the page.'])
        ->assertJsonValidationErrors(['mappings.5.lendy_value' => 'A value is listed twice.']);

    expect(CicCodeMapping::query()->count())->toBe(0);
});

it('needs cic:settings to save and cic:view to read', function () {
    $this->actingAs(CicTestKeys::staff([CicAccess::VIEW]))->putJson('/api/cic/code-mappings', ['mappings' => [
        ['domain' => 'id_kind', 'lendy_value' => 'tin_id', 'cic_domain' => 'IdentificationTypeDomain', 'cic_code' => '10'],
    ]])->assertForbidden()->assertJsonPath('message', 'You don\'t have access to manage CIC settings.');

    $this->actingAs(CicTestKeys::staff([CicAccess::SETTINGS]))->getJson('/api/cic/code-mappings')->assertForbidden();
});
