<?php

use App\Exceptions\OriginationRecordIsAppendOnlyException;
use App\Models\Branch;
use App\Models\OriginationApplication;
use App\Models\OriginationConsent;
use App\Models\OriginationNote;
use App\Models\OriginationNotice;
use App\Models\OriginationRuleRevision;
use App\Models\OriginationSetting;
use App\Models\OriginationStatusHistory;
use App\Models\User;
use App\Services\AuditLogModules;
use App\Services\TimezoneShift;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
 * LO-B1 (Phase 6, loan origination foundation): the tables, the seeded
 * settings row and rule revision 1, encrypted drafts with plain list columns,
 * one open draft per mobile, and the append-only history rows.
 */
uses(TestCase::class);

const LO_TABLES = [
    'origination_settings', 'origination_rule_revisions', 'origination_product_settings', 'origination_notices',
    'origination_links', 'origination_link_products', 'origination_applications', 'origination_consents',
    'origination_documents', 'origination_document_versions', 'origination_notes', 'origination_status_history',
];

it('creates every origination table', function () {
    foreach (LO_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} is missing");
    }
});

it('lists every new timestamp column as excluded from the one-shot timezone shift', function () {
    foreach (LO_TABLES as $table) {
        expect(array_key_exists($table, TimezoneShift::COLUMNS))->toBeFalse("{$table} must not be shifted");
    }

    expect(TimezoneShift::EXCLUDED_COLUMNS['origination_applications'])
        ->toContain('submitted_at', 'last_saved_at', 'created_at', 'updated_at');
});

it('seeds one settings row with online applications off and rule revision 1 with the standard limits', function () {
    $settings = OriginationSetting::current();

    expect(OriginationSetting::query()->count())->toBe(1)
        ->and($settings->online_applications_enabled)->toBeFalse()
        ->and($settings->reference_prefix)->toBe('OA')
        ->and($settings->max_upload_mb)->toBe(10)
        ->and($settings->accepted_file_types)->toBe(['jpg', 'jpeg', 'png', 'pdf']);

    $rules = OriginationRuleRevision::current();

    expect($rules->version)->toBe(1)
        ->and($rules->rules['max_dti'])->toBe('40')
        ->and($rules->rules['min_coverage'])->toBe('1.25')
        ->and($rules->rules['min_remaining_income'])->toBe('0.00')
        ->and($rules->rules['income_recognition']['employed'])->toBe('100');
});

it('stores the draft, mobile and email encrypted and keeps a keyed hash of the mobile', function () {
    $application = OriginationApplication::query()->create([
        'status' => 'draft',
        'applicant_name' => 'Juana Dela Cruz',
        'mobile' => '09171234567',
        'mobile_hash' => OriginationApplication::mobileHash('0917 123 4567'),
        'email' => 'juana@example.test',
        'draft' => ['personal' => ['first_name' => 'Juana']],
    ]);

    $raw = DB::table('origination_applications')->where('id', $application->id)->first();

    expect($raw->draft)->not->toContain('Juana')
        ->and($raw->mobile)->not->toContain('0917')
        ->and($raw->email)->not->toContain('example')
        ->and($raw->applicant_name)->toBe('Juana Dela Cruz')
        ->and($raw->mobile_hash)->toBe(OriginationApplication::mobileHash('+639171234567'))
        ->and($raw->mobile_hash)->not->toBe(hash('sha256', '639171234567'))
        ->and($application->fresh()->draft['personal']['first_name'])->toBe('Juana');
});

it('allows one open draft per mobile, and a new one once the earlier is submitted', function () {
    $hash = OriginationApplication::mobileHash('09171234567');
    $first = OriginationApplication::query()->create(['status' => 'draft', 'mobile_hash' => $hash]);

    expect(fn () => OriginationApplication::query()->create(['status' => 'incomplete', 'mobile_hash' => $hash]))
        ->toThrow(UniqueConstraintViolationException::class);

    DB::table('origination_applications')->where('id', $first->id)->update(['status' => 'submitted']);

    expect(OriginationApplication::query()->create(['status' => 'draft', 'mobile_hash' => $hash])->exists)->toBeTrue();
});

it('refuses to change or delete notices, consents, notes, status history and rule revisions', function (string $model) {
    $application = OriginationApplication::query()->create(['status' => 'draft']);
    $user = User::query()->where('username', 'super_admin')->firstOrFail();

    $row = match ($model) {
        OriginationNotice::class => OriginationNotice::query()->create([
            'type' => 'terms', 'version' => '1', 'body' => 'Terms', 'body_hash' => hash('sha256', 'Terms'), 'published_at' => now(),
        ]),
        OriginationConsent::class => OriginationConsent::query()->create([
            'application_id' => $application->id, 'type' => 'privacy_notice', 'accepted' => true, 'stage' => 'verify', 'accepted_at' => now(),
        ]),
        OriginationNote::class => OriginationNote::query()->create(['application_id' => $application->id, 'user_id' => $user->id, 'body' => 'Called']),
        OriginationStatusHistory::class => OriginationStatusHistory::query()->create([
            'application_id' => $application->id, 'to_status' => 'draft', 'actor_type' => 'applicant',
        ]),
        OriginationRuleRevision::class => OriginationRuleRevision::current(),
    };

    expect(fn () => $row->forceFill(['id' => $row->id])->setAttribute('created_at', now()->addDay())->save())
        ->toThrow(OriginationRecordIsAppendOnlyException::class)
        ->and(fn () => $row->delete())->toThrow(OriginationRecordIsAppendOnlyException::class)
        ->and(fn () => $model::query()->whereKey($row->id)->update(['created_at' => now()]))->toThrow(OriginationRecordIsAppendOnlyException::class)
        ->and(fn () => $model::query()->whereKey($row->id)->delete())->toThrow(OriginationRecordIsAppendOnlyException::class);
})->with([
    'notice' => OriginationNotice::class,
    'consent' => OriginationConsent::class,
    'note' => OriginationNote::class,
    'status history' => OriginationStatusHistory::class,
    'rule revision' => OriginationRuleRevision::class,
]);

it('files the origination records under their own Audit Trail module', function () {
    expect(AuditLogModules::for('updated', OriginationSetting::class))->toBe('loan_origination')
        ->and(AuditLogModules::for('created', OriginationApplication::class))->toBe('loan_origination')
        ->and(AuditLogModules::all())->toContain('loan_origination');
});

it('keeps an application with no branch out of a branch user\'s reach', function () {
    $branch = Branch::query()->firstOrFail();
    $mine = OriginationApplication::query()->create(['status' => 'submitted', 'branch_id' => $branch->id]);
    $none = OriginationApplication::query()->create(['status' => 'submitted']);

    $user = User::factory()->create(['branch_id' => $branch->id]);

    expect(OriginationApplication::query()->visibleTo($user)->pluck('id')->all())->toContain($mine->id)->not->toContain($none->id);
});
