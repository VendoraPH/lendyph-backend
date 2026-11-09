<?php

use App\Models\AuditLog;
use App\Models\CreditScoringSetting;
use Tests\Feature\CreditScoring\Api\CreditApiFixtures;
use Tests\TestCase;

/**
 * GET and PUT /credit-scoring/settings: the privacy notice and the six policy
 * figures. The model version and the definition lists are the server's.
 */
uses(TestCase::class, CreditApiFixtures::class);

beforeEach(function () {
    $this->pinToday();
    $this->admin = $this->caller(['credit_scoring:settings']);
    $this->settings = $this->actingAs($this->admin)->getJson('/api/credit-scoring/settings')->assertOk()->json('data');
});

it('refuses score_model_version in a body, as read-only', function () {
    $this->actingAs($this->admin)->putJson('/api/credit-scoring/settings', ['score_model_version' => '9.9'] + $this->settings)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['score_model_version' => 'This field is read-only.']);

    expect(CreditScoringSetting::current()->currentRevision->version)->toBe('1.0');
});

it('bumps the notice version when the privacy notice changes, and audits it', function () {
    $body = $this->settings;
    unset($body['score_model_version']);
    $body['privacy_notice'] = 'A new notice for members.';

    $data = $this->actingAs($this->admin)->putJson('/api/credit-scoring/settings', $body)->assertOk()->json('data');

    $settings = CreditScoringSetting::current();
    expect($data['privacy_notice'])->toBe('A new notice for members.')
        ->and($data['notice_version'])->toBe('1.1')
        ->and($data['score_model_version'])->toBe('1.0')
        ->and($settings->notice_version)->toBe('1.1')
        ->and($settings->updated_by)->toBe($this->admin->id);

    $audit = AuditLog::query()->where('auditable_type', CreditScoringSetting::class)->where('action', 'updated')->sole();
    expect($audit->new_values)->toHaveKeys(['privacy_notice', 'notice_version']);
});

it('saves the policy figures exactly, never rounding a rate, and ignores the definition lists', function () {
    $body = [
        'dsr_manageable_max' => 32.1234,
        'dsr_caution_max' => 45,
        'capacity_share' => 37.5,
        'max_active_loans' => 3,
        'hard_past_due_days' => 45,
        'decline_alert_points' => 15,
        'confidence_definitions' => [['level' => 'high', 'label' => 'Changed', 'description' => 'Changed']],
        'hard_flag_definitions' => [],
    ];

    $data = $this->actingAs($this->admin)->putJson('/api/credit-scoring/settings', $body)->assertOk()->json('data');

    $settings = CreditScoringSetting::current();
    expect($data['dsr_manageable_max'])->toEqual(32.1234)
        ->and($settings->dsr_manageable_max)->toBe('32.1234')
        ->and($settings->capacity_share)->toBe('37.5000')
        ->and($settings->notice_version)->toBe('1.0')
        ->and($data['confidence_definitions'])->toBe($this->settings['confidence_definitions'])
        ->and($data['max_active_loans'])->toBe(3);
});

it('refuses bad policy figures', function (array $body, string $field) {
    $this->actingAs($this->admin)->putJson('/api/credit-scoring/settings', $body)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'five decimals' => [['dsr_manageable_max' => 30.12345], 'dsr_manageable_max'],
    'manageable above caution' => [['dsr_manageable_max' => 41], 'dsr_manageable_max'],
    'share above 100' => [['capacity_share' => 101], 'capacity_share'],
    'zero loans' => [['max_active_loans' => 0], 'max_active_loans'],
    'fractional days' => [['hard_past_due_days' => 1.5], 'hard_past_due_days'],
    'empty notice' => [['privacy_notice' => ''], 'privacy_notice'],
]);

it('writes nothing for an unchanged save', function () {
    $audits = AuditLog::query()->count();
    $body = $this->settings;
    unset($body['score_model_version']);

    $this->actingAs($this->admin)->putJson('/api/credit-scoring/settings', $body)->assertOk()->assertJsonPath('data.notice_version', '1.0');

    expect(AuditLog::query()->count())->toBe($audits)
        ->and(CreditScoringSetting::current()->updated_by)->toBeNull();
});
