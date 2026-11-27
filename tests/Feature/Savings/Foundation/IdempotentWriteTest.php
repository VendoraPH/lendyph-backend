<?php

namespace Tests\Feature\Savings\Foundation;

use App\Models\SavingsIdempotencyKey;
use App\Models\SavingsTaxRule;
use App\Models\User;
use App\Services\Savings\IdempotentWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The Idempotency-Key contract every savings money post uses (design §4):
 * a replay answers the stored response once, the same key with another body
 * is 422, a key that isn't a UUID is 422, and a failure stores nothing.
 */
class IdempotentWriteTest extends TestCase
{
    use SetupLendyPH;

    private const URI = '/api/savings-idempotency-probe/{id}';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        Route::middleware(['api', 'auth:sanctum'])->post(self::URI, function (Request $request, int $id) {
            return app(IdempotentWrite::class)->run($request, function () use ($request, $id) {
                if ($request->input('fail') === 'validation') {
                    throw ValidationException::withMessages(['amount' => ['The amount is too large.']]);
                }

                $rule = SavingsTaxRule::factory()->create(['rate' => (string) $request->input('rate', '1.0000')]);

                if ($request->input('fail') === 'response') {
                    return response()->json(['message' => 'Refused.'], 422);
                }

                return response()->json(['success' => true, 'data' => ['id' => $rule->id, 'probe' => $id]], 201);
            });
        })->whereNumber('id');
    }

    private function probe(string $key, array $body = ['rate' => '1.0000'], int $id = 7)
    {
        return $this->withHeaders(['Idempotency-Key' => $key])->postJson("/api/savings-idempotency-probe/{$id}", $body);
    }

    public function test_a_replay_returns_the_stored_response_and_writes_once(): void
    {
        $key = (string) Str::uuid();
        $before = SavingsTaxRule::query()->count();

        $first = $this->probe($key)->assertCreated();
        $first->assertHeaderMissing(IdempotentWrite::REPLAY_HEADER);

        $replay = $this->probe($key)->assertCreated();
        $replay->assertHeader(IdempotentWrite::REPLAY_HEADER, 'true');

        // The stored body is a JSON column, which keeps its own key order.
        $this->assertEquals($first->json(), $replay->json());
        $this->assertSame($before + 1, SavingsTaxRule::query()->count());
        $this->assertSame(1, SavingsIdempotencyKey::query()->where('key', $key)->count());
    }

    public function test_the_same_key_with_another_body_or_path_is_422(): void
    {
        $key = (string) Str::uuid();
        $this->probe($key)->assertCreated();

        $this->probe($key, ['rate' => '2.0000'])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->probe($key, ['rate' => '1.0000'], 8)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    }

    public function test_the_body_is_compared_in_canonical_order(): void
    {
        $key = (string) Str::uuid();
        $this->probe($key, ['rate' => '1.0000', 'note' => 'a'])->assertCreated();

        $this->probe($key, ['note' => 'a', 'rate' => '1.0000'])->assertCreated()->assertHeader(IdempotentWrite::REPLAY_HEADER, 'true');
    }

    public function test_a_missing_or_malformed_key_is_422(): void
    {
        $this->postJson('/api/savings-idempotency-probe/7', ['rate' => '1.0000'])
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->probe('not-a-uuid')->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $this->assertSame(0, SavingsIdempotencyKey::query()->count());
    }

    public function test_a_failure_stores_nothing_and_the_key_can_be_used_again(): void
    {
        $key = (string) Str::uuid();
        $before = SavingsTaxRule::query()->count();

        $this->probe($key, ['fail' => 'validation'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->probe($key, ['fail' => 'response'])->assertUnprocessable();

        $this->assertSame(0, SavingsIdempotencyKey::query()->count());
        $this->assertSame($before, SavingsTaxRule::query()->count());

        $this->probe($key, ['fail' => 'none'])->assertCreated();
        $this->assertSame($before + 1, SavingsTaxRule::query()->count());
    }

    public function test_keys_belong_to_their_user(): void
    {
        $key = (string) Str::uuid();
        $this->probe($key)->assertCreated();

        $other = User::factory()->create(['branch_id' => $this->branch->id]);
        $other->assignRole('admin');
        $this->actingAs($other);

        $this->probe($key, ['rate' => '3.0000'])->assertCreated()->assertHeaderMissing(IdempotentWrite::REPLAY_HEADER);
        $this->assertSame(2, SavingsIdempotencyKey::query()->where('key', $key)->count());
    }
}
