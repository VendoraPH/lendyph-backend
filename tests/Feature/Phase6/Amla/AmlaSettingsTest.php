<?php

namespace Tests\Feature\Phase6\Amla;

use App\Exceptions\AmlaRecordIsAppendOnlyException;
use App\Models\AmlaChangeRequest;
use App\Models\AmlaLogEntry;
use App\Models\AmlaSettingsVersion;
use App\Models\AuditLog;
use App\Services\Amla\Foundation\AmlaSettings;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * AMLA settings (AMLA design §4.6, §4.10, §4.13, §4.15): versioned
 * thresholds, every one "to verify" until counsel confirms it. A CTR is one
 * transaction ABOVE ₱500,000.00 in a channel marked cash-equivalent (cash by
 * default), with no same-day sums. A change applies only once a second
 * `amla:settings` holder approves it; each approval adds a version, and no
 * version is ever changed.
 */
class AmlaSettingsTest extends TestCase
{
    use AmlaTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_version_one_holds_the_defaults_every_one_marked_to_verify(): void
    {
        $current = app(AmlaSettings::class)->current();

        $this->assertSame(1, $current->version);
        $this->assertNull($current->request_id);
        // assertEquals: a MySQL JSON column stores object keys in its own order.
        $this->assertEquals([
            'ctr_threshold' => '500000.00',
            'ctr_cash_channels' => ['cash'],
            'ctr_deadline_working_days' => 5,
            'str_deadline_working_days' => 1,
            'review_years_high' => 1,
            'review_years_medium' => 2,
            'review_years_low' => 3,
        ], $current->values);
        $this->assertSame(array_keys(AmlaSettings::DEFINITIONS), $current->to_verify);
    }

    public function test_a_ctr_is_one_cash_transaction_above_the_threshold_never_at_it(): void
    {
        $current = app(AmlaSettings::class)->current();

        $this->assertFalse($current->isCtrCandidate(50_000_000, 'cash'), 'exactly ₱500,000.00 is not above it');
        $this->assertTrue($current->isCtrCandidate(50_000_001, 'cash'));
        $this->assertFalse($current->isCtrCandidate(90_000_000, 'gcash'), 'GCash is not cash-equivalent by default');
        $this->assertFalse($current->isCtrCandidate(90_000_000, 'bank_transfer'));
    }

    public function test_the_settings_endpoint_shows_each_setting_in_words_with_its_verification(): void
    {
        $data = $this->actingAs($this->settingsHolder())->getJson('/api/amla/settings')->assertOk()->json('data');

        $this->assertSame(1, $data['version']);
        $this->assertSame(7, $data['to_verify_count']);
        $this->assertNull($data['pending_request_id']);
        $threshold = collect($data['settings'])->firstWhere('key', 'ctr_threshold');
        $this->assertSame('500000.00', $threshold['value']);
        $this->assertSame('peso', $threshold['unit']);
        $this->assertTrue($threshold['to_verify']);
        $this->assertStringContainsString('above', $threshold['label']);
    }

    public function test_a_change_waits_for_a_second_holder_and_then_adds_a_version(): void
    {
        $maker = $this->settingsHolder();
        $checker = $this->settingsHolder();
        $audit = AuditLog::query()->count();

        $request = $this->actingAs($maker)->postJson('/api/amla/change-requests', [
            'type' => 'settings',
            'reason' => 'Counsel confirmed the threshold.',
            'values' => ['ctr_threshold' => '600000.00'],
            'verified' => ['ctr_threshold' => 'Counsel memo, 2026-12-01'],
        ])->assertCreated()->json('data');

        $this->assertSame('pending', $request['status']);
        $this->assertSame(1, app(AmlaSettings::class)->current()->version, 'nothing applies before approval');

        $this->actingAs($maker)->postJson("/api/amla/change-requests/{$request['id']}/approve")
            ->assertForbidden()
            ->assertJsonPath('message', 'You asked for this change, so another AMLA settings holder must approve it.');

        $this->actingAs($checker)->postJson("/api/amla/change-requests/{$request['id']}/approve")->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $current = app(AmlaSettings::class)->current();
        $this->assertSame(2, $current->version);
        $this->assertSame('600000.00', $current->values['ctr_threshold']);
        $this->assertNotContains('ctr_threshold', $current->to_verify);
        $this->assertSame('Counsel memo, 2026-12-01', $current->sources['ctr_threshold']);
        $this->assertSame($checker->id, $current->approved_by);
        $this->assertSame($maker->id, $current->created_by);
        $this->assertSame(5, $current->values['ctr_deadline_working_days'], 'untouched settings carry over');

        $this->assertSame(
            ['change_requested', 'access_denied', 'change_approved'],
            AmlaLogEntry::query()->orderBy('id')->pluck('action')->all(),
        );
        $approved = AmlaLogEntry::query()->where('action', 'change_approved')->firstOrFail();
        $this->assertEquals(['from' => '500000.00', 'to' => '600000.00'], $approved->details['changed']['ctr_threshold']);
        $this->assertSame(2, $approved->details['settings_version']);
        $this->assertSame($audit, AuditLog::query()->count(), 'AMLA writes nothing to the main audit trail');
    }

    public function test_the_version_in_force_at_a_moment_is_the_last_one_approved_by_then(): void
    {
        Carbon::setTestNow('2026-12-01 09:00:00');
        $request = $this->actingAs($this->settingsHolder())->postJson('/api/amla/change-requests', [
            'type' => 'settings', 'reason' => 'Raise it.', 'values' => ['ctr_threshold' => '700000.00'],
        ])->json('data');
        Carbon::setTestNow('2026-12-02 09:00:00');
        $this->actingAs($this->settingsHolder())->postJson("/api/amla/change-requests/{$request['id']}/approve")->assertOk();
        Carbon::setTestNow();

        $settings = app(AmlaSettings::class);
        $this->assertSame(1, $settings->at(Carbon::parse('2026-12-02 08:59:59'))->version);
        $this->assertSame(2, $settings->at(Carbon::parse('2026-12-02 09:00:00'))->version);
    }

    public function test_only_one_settings_change_waits_at_a_time_and_a_no_op_is_refused(): void
    {
        $maker = $this->settingsHolder();

        $this->actingAs($maker)->postJson('/api/amla/change-requests', [
            'type' => 'settings', 'reason' => 'Same.', 'values' => ['ctr_threshold' => '500000.00'],
        ])->assertUnprocessable()->assertJsonPath('message', 'This changes nothing in the current AMLA settings.');

        $this->actingAs($maker)->postJson('/api/amla/change-requests', [
            'type' => 'settings', 'reason' => 'One.', 'values' => ['str_deadline_working_days' => 2],
        ])->assertCreated();

        $this->actingAs($maker)->postJson('/api/amla/change-requests', [
            'type' => 'settings', 'reason' => 'Two.', 'values' => ['ctr_deadline_working_days' => 4],
        ])->assertUnprocessable()->assertJsonPath('message', 'A change to the AMLA settings is already waiting for approval.');
    }

    public function test_settings_values_are_checked_in_words(): void
    {
        $maker = $this->settingsHolder();

        $this->actingAs($maker)->postJson('/api/amla/change-requests', [
            'type' => 'settings', 'reason' => 'Bad.', 'values' => ['ctr_threshold' => '0', 'ctr_cash_channels' => ['cash', 'auto_pay'], 'unknown' => 1],
        ])->assertUnprocessable()->assertJsonValidationErrors(['values.ctr_threshold', 'values.ctr_cash_channels.1', 'values']);

        $this->actingAs($maker)->postJson('/api/amla/change-requests', [
            'type' => 'settings', 'reason' => 'Bad order.', 'values' => ['review_years_high' => 4],
        ])->assertUnprocessable()->assertJsonPath('errors.values.0', 'High risk is reviewed at least as often as medium risk, and medium at least as often as low.');

        $this->actingAs($maker)->postJson('/api/amla/change-requests', [
            'type' => 'settings', 'values' => ['str_deadline_working_days' => 2],
        ])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    }

    public function test_a_change_can_be_rejected_by_another_holder_or_withdrawn_by_its_maker_only(): void
    {
        $maker = $this->settingsHolder();
        $other = $this->settingsHolder();

        $first = $this->actingAs($maker)->postJson('/api/amla/change-requests', [
            'type' => 'settings', 'reason' => 'One.', 'values' => ['str_deadline_working_days' => 2],
        ])->json('data.id');

        $this->actingAs($other)->postJson("/api/amla/change-requests/{$first}/withdraw")->assertForbidden();
        $this->actingAs($other)->postJson("/api/amla/change-requests/{$first}/reject")->assertUnprocessable()->assertJsonValidationErrors(['note']);
        $this->actingAs($other)->postJson("/api/amla/change-requests/{$first}/reject", ['note' => 'Not yet.'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->actingAs($other)->postJson("/api/amla/change-requests/{$first}/approve")->assertConflict();

        $second = $this->actingAs($maker)->postJson('/api/amla/change-requests', [
            'type' => 'settings', 'reason' => 'Two.', 'values' => ['str_deadline_working_days' => 2],
        ])->assertCreated()->json('data.id');
        $this->actingAs($maker)->postJson("/api/amla/change-requests/{$second}/withdraw")->assertOk()->assertJsonPath('data.status', 'withdrawn');

        $this->assertSame(1, app(AmlaSettings::class)->current()->version);
    }

    public function test_versions_and_decided_requests_are_never_changed_or_deleted(): void
    {
        $version = AmlaSettingsVersion::query()->firstOrFail();

        foreach ([
            fn () => $version->forceFill(['version' => 9])->save(),
            fn () => $version->delete(),
            fn () => AmlaSettingsVersion::query()->update(['version' => 9]),
        ] as $write) {
            try {
                $write();
                $this->fail('A settings version was changed.');
            } catch (AmlaRecordIsAppendOnlyException) {
                $this->assertSame(1, $version->fresh()->version);
            }
        }

        $request = AmlaChangeRequest::query()->create([
            'type' => 'settings', 'status' => 'pending', 'payload' => ['values' => []], 'reason' => 'x', 'requested_by' => $this->admin->id,
        ]);

        try {
            $request->forceFill(['payload' => ['values' => ['ctr_threshold' => '1.00']]])->save();
            $this->fail('A request payload was changed.');
        } catch (AmlaRecordIsAppendOnlyException) {
        }

        $request->refresh()->forceFill(['status' => 'withdrawn', 'decided_by' => $this->admin->id, 'decided_at' => now()])->save();

        try {
            $request->refresh()->forceFill(['status' => 'approved'])->save();
            $this->fail('A decided request was changed again.');
        } catch (AmlaRecordIsAppendOnlyException) {
            $this->assertSame('withdrawn', $request->fresh()->status);
        }

        $this->expectException(AmlaRecordIsAppendOnlyException::class);
        $request->delete();
    }

    public function test_the_history_lists_every_version_newest_first(): void
    {
        $request = $this->actingAs($this->settingsHolder())->postJson('/api/amla/change-requests', [
            'type' => 'settings', 'reason' => 'One.', 'values' => ['str_deadline_working_days' => 2],
        ])->json('data.id');
        $this->actingAs($this->settingsHolder())->postJson("/api/amla/change-requests/{$request}/approve")->assertOk();

        $versions = $this->actingAs($this->settingsHolder())->getJson('/api/amla/settings/versions')->assertOk()->json('data');

        $this->assertSame([2, 1], array_column($versions, 'version'));
        $this->assertSame('system_default', $versions[1]['source']);
        $this->assertSame('approved_change', $versions[0]['source']);
    }
}
