<?php

namespace Tests\Feature\Linda;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\LindaSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\Linda\LindaSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Settings → Linda (Linda v1 plan): the model Linda uses, chosen from the
 * price table, company-wide, so reading and changing it needs `linda:settings`
 * and `branches:view_all`. It works while Linda is off. Each change writes one
 * audit row in words.
 */
class LindaSettingsTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('linda.enabled', false);
        config()->set('linda.driver', 'none');
        config()->set('linda.model', 'claude-haiku-5-5');
        config()->set('linda.usd_to_php', '62.00');
    }

    public function test_with_no_row_the_model_is_the_configured_default(): void
    {
        $this->assertSame('claude-haiku-5-5', app(LindaSettings::class)->model());

        config()->set('linda.model', 'claude-opus-5-5');

        $this->assertSame('claude-opus-5-5', app(LindaSettings::class)->model());
    }

    public function test_a_stored_model_wins_while_the_price_table_still_has_it(): void
    {
        LindaSetting::query()->forceCreate(['id' => LindaSettings::ROW_ID, 'model' => 'claude-sonnet-5-5']);

        $this->assertSame('claude-sonnet-5-5', app(LindaSettings::class)->model());

        LindaSetting::query()->update(['model' => 'claude-retired-1']);

        $this->assertSame('claude-haiku-5-5', app(LindaSettings::class)->model());
    }

    public function test_a_change_writes_one_audit_row_in_words_and_saving_the_same_model_writes_none(): void
    {
        $admin = $this->staff(['linda:settings', BranchAccess::VIEW_ALL]);
        $before = AuditLog::query()->max('id') ?? 0;

        app(LindaSettings::class)->update('claude-sonnet-5-5', $admin);
        app(LindaSettings::class)->update('claude-sonnet-5-5', $admin);

        $rows = AuditLog::query()->where('id', '>', $before)->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame('Linda model changed from Claude Haiku 5.5 to Claude Sonnet 5.5', $row->description);
        $this->assertSame($admin->id, $row->user_id);
        $this->assertSame(LindaSetting::class, $row->auditable_type);
        $this->assertSame(['model' => 'claude-haiku-5-5'], $row->old_values);
        $this->assertSame(['model' => 'claude-sonnet-5-5'], $row->new_values);
        $this->assertSame(1, LindaSetting::query()->count());
        $this->assertSame($admin->id, LindaSetting::query()->first()->updated_by);
        $this->assertSame('claude-sonnet-5-5', app(LindaSettings::class)->model());
    }

    /** @return array<string, array{0: bool, 1: string, 2: ?string, 3: array{on: bool, reason: ?string}}> */
    public static function aiStatuses(): array
    {
        return [
            'switch off' => [false, 'anthropic', 'set', ['on' => false, 'reason' => 'linda_off']],
            'no model' => [true, 'none', 'set', ['on' => false, 'reason' => 'model_off']],
            'test driver' => [true, 'fake', null, ['on' => false, 'reason' => 'test_driver']],
            'no key' => [true, 'anthropic', null, ['on' => false, 'reason' => 'no_key']],
            'blank key' => [true, 'anthropic', '', ['on' => false, 'reason' => 'no_key']],
            'on' => [true, 'anthropic', 'set', ['on' => true, 'reason' => null]],
        ];
    }

    /** @param array{on: bool, reason: ?string} $expected */
    #[DataProvider('aiStatuses')]
    public function test_the_ai_status_says_why_the_model_is_off(bool $enabled, string $driver, ?string $key, array $expected): void
    {
        config()->set('linda.enabled', $enabled);
        config()->set('linda.driver', $driver);
        config()->set('linda.anthropic.api_key', $key === 'set' ? 'test-placeholder' : $key);

        $this->assertSame($expected, app(LindaSettings::class)->aiStatus());
    }

    public function test_get_answers_the_contract_shape_while_linda_is_off(): void
    {
        $this->seedAndLogin();

        $response = $this->getJson('/api/linda/settings')->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('data.model', 'claude-haiku-5-5')
            ->assertJsonPath('data.ai', ['on' => false, 'reason' => 'linda_off'])
            ->assertJsonPath('data.updated_at', null)
            ->assertJsonPath('data.updated_by', null);

        $this->assertSame(['claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5'], array_column($response->json('data.models'), 'id'));
        $this->assertSame([
            'id' => 'claude-haiku-5-5',
            'label' => 'Claude Haiku 5.5',
            'description' => 'Fastest and lowest cost. Suits Linda\'s job: sorting a question and reading its dates and branch.',
            'input_per_million' => '6.20',
            'output_per_million' => '31.00',
        ], array_intersect_key($response->json('data.models.0'), array_flip(['id', 'label', 'description', 'input_per_million', 'output_per_million'])));
        $this->assertSame(['124.00', '620.00'], [$response->json('data.models.1.input_per_million'), $response->json('data.models.1.output_per_million')]);
        $this->assertSame(['248.00', '1240.00'], [$response->json('data.models.2.input_per_million'), $response->json('data.models.2.output_per_million')]);
    }

    public function test_peso_prices_follow_the_exchange_rate_exactly(): void
    {
        $this->seedAndLogin();
        config()->set('linda.usd_to_php', '58.125');

        $response = $this->getJson('/api/linda/settings')->assertOk();

        // 0.10 × 58.125 = 5.8125 → 5.81; 0.50 × 58.125 = 29.0625 → 29.06.
        $this->assertSame(['5.81', '29.06'], [$response->json('data.models.0.input_per_million'), $response->json('data.models.0.output_per_million')]);
    }

    public function test_put_saves_the_model_and_returns_who_changed_it_and_when(): void
    {
        $admin = $this->staff(['linda:settings', BranchAccess::VIEW_ALL], ['first_name' => 'Jane', 'last_name' => 'Admin']);
        $this->travelTo(now()->setDate(2026, 11, 16)->setTime(9, 0));

        $this->actingAs($admin)->putJson('/api/linda/settings', ['model' => 'claude-sonnet-5-5'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.model', 'claude-sonnet-5-5')
            ->assertJsonPath('data.updated_by', 'Jane Admin')
            ->assertJsonPath('data.updated_at', '2026-11-16T09:00:00+08:00');

        $this->actingAs($admin)->getJson('/api/linda/settings')->assertJsonPath('data.model', 'claude-sonnet-5-5');
    }

    public function test_put_refuses_a_model_outside_the_list(): void
    {
        $this->seedAndLogin();

        $this->putJson('/api/linda/settings', ['model' => 'gpt-5'])->assertUnprocessable()->assertJsonValidationErrors('model');
        $this->putJson('/api/linda/settings', [])->assertUnprocessable()->assertJsonValidationErrors('model');
        $this->putJson('/api/linda/settings', ['model' => ['claude-haiku-5-5']])->assertUnprocessable()->assertJsonValidationErrors('model');

        $this->assertSame(0, LindaSetting::query()->count());
    }

    /** @return array<string, array{0: list<string>}> */
    public static function missingAccess(): array
    {
        return [
            'no linda:settings' => [[BranchAccess::VIEW_ALL, 'linda:use']],
            'no branches:view_all' => [['linda:settings', 'linda:use']],
        ];
    }

    /** @param list<string> $permissions */
    #[DataProvider('missingAccess')]
    public function test_both_need_linda_settings_and_every_branch(array $permissions): void
    {
        $user = $this->staff($permissions);

        $this->actingAs($user)->getJson('/api/linda/settings')->assertForbidden();
        $this->actingAs($user)->putJson('/api/linda/settings', ['model' => 'claude-sonnet-5-5'])->assertForbidden();

        $this->assertSame(0, LindaSetting::query()->count());
    }

    public function test_both_need_a_signed_in_user(): void
    {
        $this->getJson('/api/linda/settings')->assertUnauthorized();
        $this->putJson('/api/linda/settings', ['model' => 'claude-sonnet-5-5'])->assertUnauthorized();
    }

    /** @return array<string, array{string, string, bool}> */
    public static function brokenPrices(): array
    {
        return [
            'exchange rate' => ['linda.usd_to_php', 'sixty-two', false],
            'a price' => ['linda.models.claude-sonnet-5-5.output', 'ten', false],
            'the cap' => ['linda.monthly_cap', 'a thousand', true],
        ];
    }

    #[DataProvider('brokenPrices')]
    public function test_a_broken_price_rate_or_cap_shows_no_prices_instead_of_failing(string $key, string $value, bool $capBroken): void
    {
        $this->seedAndLogin();
        config()->set('linda.enabled', true);
        config()->set('linda.driver', 'anthropic');
        config()->set('linda.anthropic.api_key', 'test-placeholder');
        config()->set($key, $value);

        $response = $this->getJson('/api/linda/settings')->assertOk();

        $response->assertJsonPath('data.ai', ['on' => false, 'reason' => 'no_prices']);
        $this->assertSame(['claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5'], array_column($response->json('data.models'), 'id'));

        if ($capBroken) {
            $this->assertNull($response->json('data.month.cap'));
            $this->assertSame('6.20', $response->json('data.models.0.input_per_million'));
        } else {
            $this->assertSame('1000.00', $response->json('data.month.cap'));
            foreach ($response->json('data.models') as $model) {
                $this->assertNull($model['input_per_million']);
                $this->assertNull($model['output_per_million']);
                $this->assertNull($model['per_question']);
                $this->assertIsString($model['label']);
            }
        }

        $this->assertSame('0.00', $response->json('data.month.cost'));
        $this->putJson('/api/linda/settings', ['model' => 'claude-sonnet-5-5'])->assertOk()->assertJsonPath('data.ai.reason', 'no_prices');
    }

    public function test_the_setting_is_always_row_1_so_two_first_saves_cant_make_two_rows(): void
    {
        $admin = $this->staff(['linda:settings', BranchAccess::VIEW_ALL]);
        LindaSetting::query()->forceCreate(['model' => 'claude-haiku-5-5'])->delete();

        app(LindaSettings::class)->update('claude-sonnet-5-5', $admin);
        app(LindaSettings::class)->update('claude-opus-5-5', $admin);

        $this->assertSame([1], LindaSetting::query()->pluck('id')->all());
        $this->assertSame('claude-opus-5-5', app(LindaSettings::class)->model());
    }

    public function test_a_first_save_that_finds_the_row_another_save_just_wrote_updates_it(): void
    {
        $admin = $this->staff(['linda:settings', BranchAccess::VIEW_ALL]);
        DB::table('linda_settings')->insert(['id' => 1, 'model' => 'claude-sonnet-5-5', 'created_at' => now(), 'updated_at' => now()]);
        $before = AuditLog::query()->max('id') ?? 0;

        app(LindaSettings::class)->update('claude-opus-5-5', $admin);

        $this->assertSame(1, LindaSetting::query()->count());
        $this->assertSame(
            ['Linda model changed from Claude Sonnet 5.5 to Claude Opus 5.5'],
            AuditLog::query()->where('id', '>', $before)->pluck('description')->all(),
        );
    }

    /**
     * @param  list<string>  $permissions
     * @param  array<string, mixed>  $attributes
     */
    private function staff(array $permissions, array $attributes = []): User
    {
        $role = Role::create(['name' => 'linda_'.Str::lower(Str::random(10)), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->inBranches(Branch::query()->orderBy('id')->firstOrFail())->create($attributes);
        $user->assignRole($role);

        return $user;
    }
}
