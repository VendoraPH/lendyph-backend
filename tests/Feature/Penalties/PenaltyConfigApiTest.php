<?php

namespace Tests\Feature\Penalties;

use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\PenaltyConfig;
use App\Models\Role;
use App\Models\User;
use App\Services\Penalties\PenaltyConfigService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Settings → Penalties: the `/penalty-configs` endpoints (contract B2).
 * Validation answers 422 in words, a product sits in one active
 * configuration, a configuration on a loan can't be deleted, each endpoint
 * needs its permission, and every save writes an audit row in words.
 */
class PenaltyConfigApiTest extends TestCase
{
    use SetupLendyPH;

    private const KEVIN = [
        ['days_from' => 1, 'days_to' => 5, 'rate' => 20],
        ['days_from' => 6, 'days_to' => 10, 'rate' => 30],
        ['days_from' => 11, 'days_to' => null, 'rate' => 50],
    ];

    private LoanProduct $salary;

    private LoanProduct $regular;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->salary = LoanProduct::factory()->create(['name' => 'Salary Loan', 'penalty_rate' => 3]);
        $this->regular = LoanProduct::factory()->create(['name' => 'Regular Loan', 'penalty_rate' => 0]);
    }

    public function test_create_answers_201_with_the_configuration_in_the_contracts_shape(): void
    {
        $data = $this->postJson('/api/penalty-configs', $this->body())->assertCreated()->json('data');

        $this->assertSame('Standard Loan Penalty', $data['name']);
        $this->assertSame('active', $data['status']);
        $this->assertSame([$this->salary->id, $this->regular->id], $data['applicable_product_ids']);
        $this->assertSame([['id' => $this->regular->id, 'name' => 'Regular Loan'], ['id' => $this->salary->id, 'name' => 'Salary Loan']], $data['products']);
        $this->assertEquals(self::KEVIN, $data['conditions']);
        $this->assertFalse($data['in_use']);
        $this->assertSame(0, $data['loans_count']);
        $this->assertArrayHasKey('created_at', $data);
    }

    public function test_brackets_are_stored_in_day_order_and_rates_are_never_rounded(): void
    {
        $data = $this->postJson('/api/penalty-configs', $this->body(['conditions' => [
            ['days_from' => 11, 'days_to' => null, 'rate' => 50],
            ['days_from' => 1, 'days_to' => 10, 'rate' => 2.1234],
        ]]))->assertCreated()->json('data');

        $this->assertEquals([
            ['days_from' => 1, 'days_to' => 10, 'rate' => 2.1234],
            ['days_from' => 11, 'days_to' => null, 'rate' => 50],
        ], $data['conditions']);
        $this->assertSame('2.1234', PenaltyConfig::findOrFail($data['id'])->conditions->first()->rate);
    }

    /** @return array<string, array{list<array<string, mixed>>, string, string}> */
    public static function badBrackets(): array
    {
        return [
            'a gap' => [[
                ['days_from' => 1, 'days_to' => 5, 'rate' => 20],
                ['days_from' => 7, 'days_to' => null, 'rate' => 30],
            ], 'conditions.1.days_from', 'Condition 2 leaves a gap after day 5: it must start on day 6, the day after condition 1 ends.'],
            'an overlap' => [[
                ['days_from' => 1, 'days_to' => 5, 'rate' => 20],
                ['days_from' => 5, 'days_to' => null, 'rate' => 30],
            ], 'conditions.1.days_from', 'Condition 2 overlaps condition 1: it must start on day 6, the day after condition 1 ends.'],
            'a first row not at day 1' => [[
                ['days_from' => 2, 'days_to' => null, 'rate' => 20],
            ], 'conditions.0.days_from', 'The first condition must start at day 1, the day after the due date.'],
            'an open row that is not last' => [[
                ['days_from' => 1, 'days_to' => null, 'rate' => 20],
                ['days_from' => 6, 'days_to' => 10, 'rate' => 30],
            ], 'conditions.0.days_to', 'Only the last condition can be open-ended. Condition 1 needs the day it ends on.'],
            'a row ending before it starts' => [[
                ['days_from' => 1, 'days_to' => 5, 'rate' => 20],
                ['days_from' => 6, 'days_to' => 4, 'rate' => 30],
            ], 'conditions.1.days_to', 'Condition 2 ends before it starts: days to must be at least days from.'],
            'a rate over 100' => [[
                ['days_from' => 1, 'days_to' => null, 'rate' => 100.5],
            ], 'conditions.0.rate', 'The rate can be at most 100%.'],
            'a rate with 5 decimals' => [[
                ['days_from' => 1, 'days_to' => null, 'rate' => 2.12345],
            ], 'conditions.0.rate', 'The rate can have at most 4 decimal places.'],
            'a negative rate' => [[
                ['days_from' => 1, 'days_to' => null, 'rate' => -1],
            ], 'conditions.0.rate', 'The rate can\'t be below 0%.'],
            'no brackets' => [[], 'conditions', 'Add at least one condition.'],
        ];
    }

    /** @param list<array<string, mixed>> $conditions */
    #[DataProvider('badBrackets')]
    public function test_bad_brackets_answer_422_in_words(array $conditions, string $field, string $message): void
    {
        $this->postJson('/api/penalty-configs', $this->body(['conditions' => $conditions]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field => $message]);

        $this->assertSame(0, PenaltyConfig::count());
    }

    public function test_21_brackets_answer_422(): void
    {
        $conditions = [];
        for ($day = 1; $day <= 21; $day++) {
            $conditions[] = ['days_from' => $day, 'days_to' => $day === 21 ? null : $day, 'rate' => 1];
        }

        $this->postJson('/api/penalty-configs', $this->body(['conditions' => $conditions]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.conditions.0', 'A configuration can have at most 20 conditions.');

        array_pop($conditions);
        $conditions[19]['days_to'] = null;
        $this->postJson('/api/penalty-configs', $this->body(['conditions' => $conditions]))->assertCreated();
    }

    public function test_the_name_is_required_unique_and_at_most_100_characters(): void
    {
        $this->postJson('/api/penalty-configs', $this->body(['name' => '']))
            ->assertUnprocessable()->assertJsonPath('errors.name.0', 'Enter a name for the configuration.');
        $this->postJson('/api/penalty-configs', $this->body(['name' => str_repeat('a', 101)]))
            ->assertUnprocessable()->assertJsonPath('errors.name.0', 'The name can be at most 100 characters.');

        $this->postJson('/api/penalty-configs', $this->body(['status' => 'inactive']))->assertCreated();
        $this->postJson('/api/penalty-configs', $this->body(['status' => 'inactive']))
            ->assertUnprocessable()->assertJsonPath('errors.name.0', 'Another configuration already has this name.');
    }

    public function test_an_active_configuration_needs_a_product(): void
    {
        $this->postJson('/api/penalty-configs', $this->body(['applicable_product_ids' => []]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.applicable_product_ids.0', 'An active configuration needs at least one product.');

        $id = $this->postJson('/api/penalty-configs', $this->body(['applicable_product_ids' => [], 'status' => 'inactive']))
            ->assertCreated()->json('data.id');

        $this->patchJson("/api/penalty-configs/{$id}/status", ['status' => 'active'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.applicable_product_ids.0', 'An active configuration needs at least one product.');
    }

    public function test_a_product_in_another_active_configuration_answers_422_naming_both(): void
    {
        $this->postJson('/api/penalty-configs', $this->body(['applicable_product_ids' => [$this->salary->id]]))->assertCreated();

        $this->postJson('/api/penalty-configs', $this->body(['name' => 'Second', 'applicable_product_ids' => [$this->salary->id, $this->regular->id]]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.applicable_product_ids.0', 'Salary Loan is already covered by the active configuration "Standard Loan Penalty".');

        // Inactive, it may share the product; activating it is then refused.
        $id = $this->postJson('/api/penalty-configs', $this->body(['name' => 'Second', 'status' => 'inactive', 'applicable_product_ids' => [$this->salary->id]]))
            ->assertCreated()->json('data.id');
        $this->patchJson("/api/penalty-configs/{$id}/status", ['status' => 'active'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.applicable_product_ids.0', 'Salary Loan is already covered by the active configuration "Standard Loan Penalty".');

        // So is an edit that moves an active configuration onto it.
        $other = $this->postJson('/api/penalty-configs', $this->body(['name' => 'Third', 'applicable_product_ids' => [$this->regular->id]]))
            ->assertCreated()->json('data.id');
        $this->putJson("/api/penalty-configs/{$other}", $this->body(['name' => 'Third', 'applicable_product_ids' => [$this->regular->id, $this->salary->id]]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.applicable_product_ids.0', 'Salary Loan is already covered by the active configuration "Standard Loan Penalty".');

        $this->assertSame(1, PenaltyConfig::active()->whereHas('products', fn ($q) => $q->whereKey($this->salary->id))->count());
    }

    public function test_a_save_racing_another_answers_409_never_500(): void
    {
        // The second of two saves with the same new name loses the unique
        // index rather than the validator: 409, nothing half-written.
        PenaltyConfig::factory()->create(['name' => 'Raced', 'status' => 'inactive']);

        try {
            app(PenaltyConfigService::class)->create($this->body(['name' => 'Raced']));
            $this->fail('The colliding save was not refused.');
        } catch (HttpResponseException $e) {
            $this->assertSame(409, $e->getResponse()->getStatusCode());
            $this->assertSame('Another change to penalty configurations was saved at the same time. Reload and try again.', $e->getResponse()->getData(true)['message']);
        }

        $this->assertSame(1, PenaltyConfig::count());
    }

    public function test_an_edit_saves_the_whole_configuration(): void
    {
        $id = $this->postJson('/api/penalty-configs', $this->body())->assertCreated()->json('data.id');

        $data = $this->putJson("/api/penalty-configs/{$id}", $this->body([
            'name' => 'Renamed',
            'applicable_product_ids' => [$this->regular->id],
            'conditions' => [['days_from' => 1, 'days_to' => null, 'rate' => 4.5]],
        ]))->assertOk()->json('data');

        $this->assertSame('Renamed', $data['name']);
        $this->assertSame('active', $data['status']);
        $this->assertSame([$this->regular->id], $data['applicable_product_ids']);
        $this->assertEquals([['days_from' => 1, 'days_to' => null, 'rate' => 4.5]], $data['conditions']);
    }

    public function test_delete_answers_409_while_a_loan_uses_it_and_deletes_it_otherwise(): void
    {
        $config = PenaltyConfig::factory()->withBrackets(self::KEVIN)->forProducts($this->salary)->create();
        $loans = Loan::factory()->count(2)->create(['loan_product_id' => $this->salary->id, 'penalty_config_id' => $config->id, 'penalty_rate' => 0]);

        $this->deleteJson("/api/penalty-configs/{$config->id}")
            ->assertConflict()
            ->assertJsonPath('message', "This configuration is on 2 loans, so it can't be deleted. Deactivate it instead.");

        $this->getJson("/api/penalty-configs/{$config->id}")->assertOk()
            ->assertJsonPath('data.in_use', true)
            ->assertJsonPath('data.loans_count', 2);

        Loan::whereKey($loans->pluck('id'))->update(['penalty_config_id' => null]);

        $this->deleteJson("/api/penalty-configs/{$config->id}")->assertOk();
        $this->assertNull(PenaltyConfig::find($config->id));
        $this->assertSame(0, DB::table('penalty_config_conditions')->where('penalty_config_id', $config->id)->count());
    }

    public function test_the_list_pages_filters_and_searches(): void
    {
        PenaltyConfig::factory()->withBrackets(self::KEVIN)->forProducts($this->salary)->create(['name' => 'Alpha 50% Penalty']);
        PenaltyConfig::factory()->inactive()->withBrackets(self::KEVIN)->create(['name' => 'Beta Penalty']);
        PenaltyConfig::factory()->inactive()->withBrackets(self::KEVIN)->create(['name' => 'Gamma Penalty']);

        $page = $this->getJson('/api/penalty-configs?per_page=2')->assertOk();
        $this->assertSame(['Alpha 50% Penalty', 'Beta Penalty'], array_column($page->json('data'), 'name'));
        $this->assertSame(3, $page->json('meta.total'));

        $this->assertSame(['Beta Penalty', 'Gamma Penalty'], array_column($this->getJson('/api/penalty-configs?status=inactive')->assertOk()->json('data'), 'name'));
        $this->assertSame(['Gamma Penalty'], array_column($this->getJson('/api/penalty-configs?search=gam')->assertOk()->json('data'), 'name'));
        // `%` matches literally.
        $this->assertSame(['Alpha 50% Penalty'], array_column($this->getJson('/api/penalty-configs?search=50%25')->assertOk()->json('data'), 'name'));

        $this->getJson('/api/penalty-configs?per_page=101')->assertUnprocessable();
        $this->getJson('/api/penalty-configs?status=archived')->assertUnprocessable();
    }

    public function test_the_list_reads_a_page_in_a_fixed_number_of_queries(): void
    {
        foreach (range(1, 3) as $n) {
            PenaltyConfig::factory()->inactive()->withBrackets(self::KEVIN)->forProducts($this->salary)->create();
        }
        // The first request also warms the permission cache.
        $this->getJson('/api/penalty-configs')->assertOk();
        $queries = $this->countQueries(fn () => $this->getJson('/api/penalty-configs')->assertOk());

        foreach (range(1, 5) as $n) {
            PenaltyConfig::factory()->inactive()->withBrackets(self::KEVIN)->forProducts($this->regular)->create();
        }
        $this->assertSame($queries, $this->countQueries(fn () => $this->getJson('/api/penalty-configs')->assertOk()));
    }

    public function test_the_product_says_where_a_new_loans_penalty_comes_from(): void
    {
        $none = LoanProduct::factory()->create(['name' => 'No Penalty Loan', 'penalty_rate' => 0]);
        $config = PenaltyConfig::factory()->withBrackets(self::KEVIN)->forProducts($this->regular)->create(['name' => 'Standard Loan Penalty']);
        PenaltyConfig::factory()->inactive()->withBrackets(self::KEVIN)->forProducts($none)->create();

        $sources = collect($this->getJson('/api/loan-products?per_page=100')->assertOk()->json('data'))
            ->mapWithKeys(fn (array $product): array => [$product['name'] => $product['penalty_source']]);

        $this->assertSame(['type' => 'configuration', 'config_id' => $config->id, 'config_name' => 'Standard Loan Penalty', 'rate' => null], $sources['Regular Loan']);
        $this->assertEquals(['type' => 'fixed_rate', 'config_id' => null, 'config_name' => null, 'rate' => 3.0], $sources['Salary Loan']);
        $this->assertSame(['type' => 'none', 'config_id' => null, 'config_name' => null, 'rate' => null], $sources['No Penalty Loan']);

        $this->getJson("/api/loan-products/{$this->regular->id}")->assertOk()->assertJsonPath('data.penalty_source.type', 'configuration');
    }

    /** @return array<string, array{string, string, string}> */
    public static function endpoints(): array
    {
        return [
            'list' => ['get', '/api/penalty-configs', 'penalties:view'],
            'show' => ['get', '/api/penalty-configs/{id}', 'penalties:view'],
            'create' => ['post', '/api/penalty-configs', 'penalties:create'],
            'update' => ['put', '/api/penalty-configs/{id}', 'penalties:update'],
            'status' => ['patch', '/api/penalty-configs/{id}/status', 'penalties:update'],
            'delete' => ['delete', '/api/penalty-configs/{id}', 'penalties:delete'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_each_endpoint_needs_its_permission(string $method, string $path, string $permission): void
    {
        $config = PenaltyConfig::factory()->inactive()->withBrackets(self::KEVIN)->create(['name' => 'Kept']);
        $url = str_replace('{id}', (string) $config->id, $path);
        $body = match ($method) {
            'patch' => ['status' => 'inactive'],
            'post' => $this->body(['name' => 'Added', 'status' => 'inactive']),
            default => $this->body(['name' => 'Kept', 'status' => 'inactive']),
        };

        $others = array_values(array_diff(['penalties:view', 'penalties:create', 'penalties:update', 'penalties:delete'], [$permission]));
        $this->actingAs($this->staff($others));
        $this->json($method, $url, $body)->assertForbidden();

        $this->actingAs($this->staff([$permission]));
        $this->assertContains($this->json($method, $url, $body)->status(), [200, 201]);
    }

    public function test_create_edit_status_and_delete_write_audit_rows_in_words(): void
    {
        $id = $this->postJson('/api/penalty-configs', $this->body())->assertCreated()->json('data.id');
        $this->putJson("/api/penalty-configs/{$id}", $this->body([
            'applicable_product_ids' => [$this->salary->id],
            'conditions' => [['days_from' => 1, 'days_to' => null, 'rate' => 2.5]],
        ]))->assertOk();
        // Saving it unchanged writes nothing.
        $this->putJson("/api/penalty-configs/{$id}", $this->body([
            'applicable_product_ids' => [$this->salary->id],
            'conditions' => [['days_from' => 1, 'days_to' => null, 'rate' => 2.5]],
        ]))->assertOk();
        $this->patchJson("/api/penalty-configs/{$id}/status", ['status' => 'inactive'])->assertOk();
        $this->deleteJson("/api/penalty-configs/{$id}")->assertOk();

        $rows = collect($this->getJson('/api/audit-logs?module=penalties&per_page=50')->assertOk()->json('data'))->sortBy('id')->values();

        $this->assertSame(['created', 'updated', 'updated', 'deleted'], $rows->pluck('action')->all());
        $this->assertSame(['penalties'], $rows->pluck('module')->unique()->values()->all());
        $this->assertSame(
            'Penalty configuration "Standard Loan Penalty" created (active): 1–5 days: 20%; 6–10 days: 30%; 11 days or more: 50%. Products: Regular Loan, Salary Loan',
            $rows[0]['description'],
        );
        // Deleted since, so every row names it by its kind and id.
        $this->assertSame("Penalty configuration #{$id}", $rows[0]['target']['label']);

        $this->assertEqualsCanonicalizing([
            ['field' => 'applicable_product_ids', 'old' => 'Salary Loan, Regular Loan', 'new' => 'Salary Loan'],
            ['field' => 'conditions', 'old' => '1–5 days: 20%; 6–10 days: 30%; 11 days or more: 50%', 'new' => '1 day or more: 2.5%'],
        ], $rows[1]['changes']);
        $this->assertSame('Penalty configuration "Standard Loan Penalty" updated', $rows[1]['description']);

        $this->assertSame([['field' => 'status', 'old' => 'active', 'new' => 'inactive']], $rows[2]['changes']);
        $this->assertSame('Penalty configuration "Standard Loan Penalty" deactivated', $rows[2]['description']);

        $this->assertSame('Penalty configuration "Standard Loan Penalty" deleted: 1 day or more: 2.5%. Products: Salary Loan', $rows[3]['description']);
        $this->assertSame(1, AuditLog::where('auditable_type', PenaltyConfig::class)->where('action', 'deleted')->count());
    }

    public function test_an_audit_row_names_the_configuration_by_its_name_while_it_exists(): void
    {
        $id = $this->postJson('/api/penalty-configs', $this->body())->assertCreated()->json('data.id');
        $this->patchJson("/api/penalty-configs/{$id}/status", ['status' => 'inactive'])->assertOk();

        $rows = $this->getJson('/api/audit-logs?module=penalties')->assertOk()->json('data');

        $this->assertCount(2, $rows);
        $this->assertSame(['Standard Loan Penalty'], array_values(array_unique(array_column(array_column($rows, 'target'), 'label'))));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return [
            'name' => 'Standard Loan Penalty',
            'applicable_product_ids' => [$this->salary->id, $this->regular->id],
            'conditions' => self::KEVIN,
            ...$overrides,
        ];
    }

    /** @param list<string> $permissions */
    private function staff(array $permissions): User
    {
        $role = Role::create(['name' => 'pen_'.Str::lower(Str::random(10)), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole($role);

        return $user;
    }

    private function countQueries(callable $run): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
