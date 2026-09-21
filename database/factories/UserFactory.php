<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * Put every generated user in the pivot, not just in the column.
     *
     * `branch_id` below is a mass-assignment attribute, and under
     * `branch_user` that now fills in only HALF of an assignment. Nothing about
     * it would fail: the column gets its value, the insert succeeds, and the
     * user comes back with an empty `branches` relation. Roughly a hundred
     * `User::factory()` call sites — forty-seven of which pass `branch_id`
     * explicitly, precisely to say which branch the user is in — would have
     * gone on producing branchless users, and every one of them would have
     * agreed with a `branch_id`-based assertion while disagreeing with the API
     * they model. That is the shape of fixture bug that makes a green suite
     * mean nothing.
     *
     * Idempotent rather than a bare `attach()`, because `inBranches()` runs its
     * own sync after this one and the unique index would refuse a second row.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            if ($user->branch_id === null) {
                return;
            }

            $user->branches()->syncWithoutDetaching([$user->branch_id]);
        });
    }

    /**
     * A user assigned to several branches.
     *
     * The first id also lands in `users.branch_id`, which is the invariant the
     * whole dual contract rests on: the legacy column always names a branch the
     * user is actually in. See App\Http\Requests\Concerns\ResolvesBranchAssignment.
     */
    public function inBranches(Branch|int ...$branches): static
    {
        $ids = array_values(array_unique(array_map(
            static fn (Branch|int $branch): int => $branch instanceof Branch ? $branch->id : $branch,
            $branches,
        )));

        return $this->state(['branch_id' => $ids[0] ?? null])
            ->afterCreating(fn (User $user) => $user->branches()->sync($ids));
    }

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            // `alpha_dash` is the app's own rule on this column
            // (StoreUserRequest / UpdateUserRequest), and Faker's userName()
            // returns dotted forms like "shawna.sporer" for well over half its
            // output — 22 of 40 in a sample. So a factory user could not be
            // saved back through the API it models, and any spec that round-trips
            // a generated username was a coin flip on the faker sequence. One
            // such spec flipped the day two unrelated tests were added ahead of
            // it. Keep the generator inside the rule.
            'username' => str_replace('.', '_', fake()->unique()->userName()),
            'email' => fake()->unique()->safeEmail(),
            'mobile_number' => fake()->numerify('09#########'),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            // Still the column, still hardcoded — BranchSeeder makes branch 1
            // before any test runs, and ~30 call sites rely on the default. The
            // matching `branch_user` row is created by configure() above;
            // setting it here would not work, because a pivot is not an
            // attribute and `create()` would silently discard it.
            'branch_id' => 1,
            'status' => 'active',
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}
