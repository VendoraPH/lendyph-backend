<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\LoanProduct;
use App\Models\OriginationProductSetting;
use App\Models\OriginationRuleRevision;
use App\Models\OriginationSetting;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/*
 * LO-B1: GET and PUT /loan-origination/settings (the frontend's
 * OriginationSettings object, origination decisions 15–18). Reading needs
 * `loan_origination:view` or `:settings`; saving needs `:settings` plus
 * `branches:view_all`, since the settings apply to every branch. A change to
 * the assessment rules is a new immutable revision.
 */
uses(TestCase::class);

beforeEach(function () {
    $this->branch = Branch::query()->firstOrFail();
    $this->admin = User::query()->where('username', 'super_admin')->firstOrFail();
});

function loUserWith(string ...$permissions): User
{
    $role = Role::query()->create(['name' => 'spec-lo:'.implode(',', $permissions).':'.uniqid(), 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    return tap(
        User::factory()->create(['branch_id' => Branch::query()->firstOrFail()->id]),
        fn (User $user) => $user->assignRole($role),
    );
}

function loSettingsPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'general' => [
            'online_applications_enabled' => true, 'default_branch_id' => Branch::query()->firstOrFail()->id,
            'reference_format' => 'OA-YYYY-NNNNNN', 'draft_expiration_days' => 30, 'estimated_minutes' => 15,
        ],
        'products' => [],
        'assessment' => [
            'max_dti' => 40, 'min_coverage' => 1.25, 'min_remaining_income' => 0,
            'income_recognition' => ['employed' => 100, 'self_employed' => 80],
            'require_verified_income' => false, 'min_employment_years' => null,
            'missing_information_rule' => 'provisional', 'product_overrides' => [],
        ],
        'documents' => [
            'accepted_file_types' => ['jpg', 'jpeg', 'png', 'pdf'], 'max_upload_mb' => 10, 'retention_days' => 365,
            'types' => [
                ['type' => 'government_id', 'label' => 'Valid government ID', 'description' => null, 'required' => true],
                ['type' => 'payslip', 'label' => 'Latest payslip', 'description' => 'The last two', 'required' => false],
            ],
        ],
        'notifications' => [
            'application_confirmation' => true, 'incomplete_reminder' => false, 'incomplete_reminder_after_days' => 3,
            'document_request' => true, 'status_change' => true, 'decision' => true,
        ],
    ], $overrides);
}

it('reads the standard settings with the server switches, in the frontend\'s shape', function () {
    config(['loan_origination.enabled' => false, 'loan_origination.public_url' => null]);

    $this->actingAs(loUserWith('loan_origination:view'))
        ->getJson('/api/loan-origination/settings')
        ->assertOk()
        ->assertJsonPath('data.general.online_applications_enabled', false)
        ->assertJsonPath('data.general.reference_format', 'OA-YYYY-NNNNNN')
        ->assertJsonPath('data.general.draft_expiration_days', 30)
        ->assertJsonPath('data.assessment.max_dti', 40)
        ->assertJsonPath('data.assessment.min_coverage', 1.25)
        ->assertJsonPath('data.assessment.min_remaining_income', 0)
        ->assertJsonPath('data.assessment.income_recognition.employed', 100)
        ->assertJsonPath('data.assessment.missing_information_rule', 'provisional')
        ->assertJsonPath('data.documents.accepted_file_types', ['jpg', 'jpeg', 'png', 'pdf'])
        ->assertJsonPath('data.documents.max_upload_mb', 10)
        ->assertJsonPath('data.documents.types.0.type', 'government_id')
        ->assertJsonPath('data.rules_version', 1)
        ->assertJsonPath('data.server.enabled', false)
        ->assertJsonPath('data.server.public_url', null)
        ->assertJsonPath('data.server.public_url_problem', 'The public application address isn\'t set on the server yet, so links have no address.')
        ->assertJsonPath('data.server.scanner', 'Not scanned (no virus scanner is set up)');
});

it('refuses reading to staff without an online applications permission, in words', function () {
    $this->actingAs(loUserWith('loans:view'))
        ->getJson('/api/loan-origination/settings')
        ->assertForbidden()
        ->assertJsonPath('message', 'You don\'t have access to online application settings.');
});

it('refuses saving without settings and all-branch access, in words', function (array $permissions) {
    $this->actingAs(loUserWith(...$permissions))
        ->putJson('/api/loan-origination/settings', loSettingsPayload())
        ->assertForbidden()
        ->assertJsonPath('message', 'Online application settings apply to every branch, so changing them needs the online applications settings permission and access to all branches.');

    expect(OriginationSetting::current()->online_applications_enabled)->toBeFalse();
})->with([
    'settings only' => [['loan_origination:settings']],
    'all branches only' => [['branches:view_all', 'loan_origination:view']],
]);

it('saves the settings, audited, and keeps the assessment revision when the rules are unchanged', function () {
    $user = loUserWith('loan_origination:settings', 'branches:view_all');
    $before = AuditLog::query()->max('id') ?? 0;

    $this->actingAs($user)
        ->putJson('/api/loan-origination/settings', loSettingsPayload(['assessment' => ['income_recognition' => ['self_employed' => 100]]]))
        ->assertOk()
        ->assertJsonPath('data.general.online_applications_enabled', true)
        ->assertJsonPath('data.general.default_branch_id', $this->branch->id)
        ->assertJsonPath('data.documents.types.1.type', 'payslip')
        ->assertJsonPath('data.rules_version', 1);

    expect(OriginationSetting::current()->updated_by)->toBe($user->id)
        ->and(OriginationRuleRevision::query()->count())->toBe(1);

    $row = AuditLog::query()->where('id', '>', $before)->where('auditable_type', OriginationSetting::class)->sole();
    expect($row->action)->toBe('updated')
        ->and($row->user_id)->toBe($user->id);
});

it('records a change to the assessment rules as a new revision, never rounding decimals', function () {
    $product = LoanProduct::factory()->create();

    $this->actingAs($this->admin)
        ->putJson('/api/loan-origination/settings', loSettingsPayload(['assessment' => [
            'max_dti' => 35.5, 'min_coverage' => 1.3333, 'min_remaining_income' => 2500.5,
            'product_overrides' => [['loan_product_id' => $product->id, 'max_dti' => 30]],
        ]]))
        ->assertOk()
        ->assertJsonPath('data.rules_version', 2)
        ->assertJsonPath('data.assessment.max_dti', 35.5)
        ->assertJsonPath('data.assessment.min_coverage', 1.3333)
        ->assertJsonPath('data.assessment.product_overrides.0.loan_product_id', $product->id)
        ->assertJsonPath('data.assessment.product_overrides.0.max_dti', 30);

    $revision = OriginationRuleRevision::current();
    expect($revision->version)->toBe(2)
        ->and($revision->rules['max_dti'])->toBe('35.5')
        ->and($revision->rules['min_coverage'])->toBe('1.3333')
        ->and($revision->rules['min_remaining_income'])->toBe('2500.50')
        ->and($revision->created_by)->toBe($this->admin->id)
        ->and(OriginationRuleRevision::query()->where('version', 1)->sole()->rules['max_dti'])->toBe('40');
});

it('saves the products offered online and replaces the list', function () {
    [$a, $b] = LoanProduct::factory()->count(2)->create();

    $this->actingAs($this->admin)->putJson('/api/loan-origination/settings', loSettingsPayload(['products' => [
        ['loan_product_id' => $a->id, 'online' => true, 'required_fields' => ['personal.postal_code'], 'eligibility' => 'Members only', 'required_documents' => ['government_id'], 'instructions' => null],
        ['loan_product_id' => $b->id, 'online' => false, 'required_fields' => [], 'eligibility' => null, 'required_documents' => [], 'instructions' => null],
    ]]))->assertOk()
        ->assertJsonCount(2, 'data.products')
        ->assertJsonPath('data.products.0.required_documents', ['government_id']);

    $this->actingAs($this->admin)->putJson('/api/loan-origination/settings', loSettingsPayload(['products' => [
        ['loan_product_id' => $b->id, 'online' => true, 'required_fields' => [], 'eligibility' => null, 'required_documents' => [], 'instructions' => null],
    ]]))->assertOk()->assertJsonCount(1, 'data.products');

    expect(OriginationProductSetting::query()->pluck('loan_product_id')->all())->toBe([$b->id]);
});

it('refuses settings outside their limits, in words', function (array $overrides, string $key, string $message) {
    $this->actingAs($this->admin)
        ->putJson('/api/loan-origination/settings', loSettingsPayload($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$key => $message]);
})->with([
    'lending prefix' => [['general' => ['reference_format' => 'LA-YYYY-NNNNNN']], 'general.reference_format', 'LA is already used by other Lendy records. Choose another prefix.'],
    'bad format' => [['general' => ['reference_format' => 'OA-NNNN']], 'general.reference_format', 'The reference format must read like OA-YYYY-NNNNNN: 2 to 5 capital letters, then -YYYY-NNNNNN.'],
    'word files' => [['documents' => ['accepted_file_types' => ['pdf', 'docx']]], 'documents.accepted_file_types.1', 'Online applications take JPEG, PNG and PDF files only (.jpg, .jpeg, .jpe, .jfif, .png or .pdf).'],
    'too large' => [['documents' => ['max_upload_mb' => 25]], 'documents.max_upload_mb', 'The largest file can be at most 10 MB.'],
    'dti above 100' => [['assessment' => ['max_dti' => 120]], 'assessment.max_dti', 'The maximum debt-to-income ratio must be from 0% to 100%.'],
    'five decimals' => [['assessment' => ['max_dti' => 40.12345]], 'assessment.max_dti', 'The maximum debt-to-income ratio can have at most 4 decimal places.'],
    'centavos' => [['assessment' => ['min_remaining_income' => 10.555]], 'assessment.min_remaining_income', 'The minimum remaining income must be in pesos with at most two decimals, ₱0.00 or more.'],
    'unknown document' => [['products' => [['loan_product_id' => 1, 'online' => true, 'required_fields' => [], 'eligibility' => null, 'required_documents' => ['passport'], 'instructions' => null]]], 'products.0.required_documents.0', 'Required documents must be one of the document types below.'],
    'unknown field' => [['products' => [['loan_product_id' => 1, 'online' => true, 'required_fields' => ['personal.password'], 'eligibility' => null, 'required_documents' => [], 'instructions' => null]]], 'products.0.required_fields.0', 'Required fields must be fields of the application form.'],
    'unknown branch' => [['general' => ['default_branch_id' => 999999]], 'general.default_branch_id', 'Choose an active branch.'],
]);
