<?php

namespace Database\Factories;

use App\Models\CicPublicKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A key row with made-up material (no real key). Tests that encrypt generate a
 * real key pair in the test and upload its public half instead.
 *
 * @extends Factory<CicPublicKey>
 */
class CicPublicKeyFactory extends Factory
{
    protected $model = CicPublicKey::class;

    public function definition(): array
    {
        $fingerprint = strtoupper(bin2hex(random_bytes(20)));

        return [
            'fingerprint' => $fingerprint,
            'key_id' => substr($fingerprint, -16),
            'algorithm' => 'RSA 4096',
            'user_ids' => ['CIC Test <keys@example.test>'],
            'key_created_at' => now()->subMonth(),
            'expires_at' => now()->addYear(),
            'armored' => "-----BEGIN PGP PUBLIC KEY BLOCK-----\n\nnot a real key\n-----END PGP PUBLIC KEY BLOCK-----\n",
            'status' => CicPublicKey::STATUS_PENDING,
            'uploaded_by' => User::factory(),
        ];
    }

    /** Verified and active. Only one key may be active at a time. */
    public function active(): static
    {
        return $this->state(fn () => [
            'status' => CicPublicKey::STATUS_ACTIVE,
            'verified_by' => User::factory(),
            'verified_at' => now(),
        ]);
    }

    public function retired(): static
    {
        return $this->state(fn () => [
            'status' => CicPublicKey::STATUS_RETIRED,
            'retired_by' => User::factory(),
            'retired_at' => now(),
            'retire_reason' => 'Replaced by CIC.',
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['key_created_at' => now()->subYears(2), 'expires_at' => now()->subDay()]);
    }
}
