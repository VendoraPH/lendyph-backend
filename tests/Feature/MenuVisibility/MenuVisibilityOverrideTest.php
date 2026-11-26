<?php

use App\Models\AuditLog;
use App\Models\MenuVisibilityOverride;
use App\Services\MenuVisibility\OverrideLockout;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\MenuVisibility\MenuVisibilityTestHelpers;
use Tests\TestCase;

uses(TestCase::class, MenuVisibilityTestHelpers::class);

/*
 * The administrator override in front of Menu Visibility (finish run round 6):
 * the server holds only a bcrypt hash, a right password opens the page for 15
 * minutes on that sign-in's token, wrong tries are audited and locked after 5,
 * and the password never reaches a log, an answer or an audit row.
 */

beforeEach(function () {
    // The deployments' own store: tries are counted in the database, never in
    // one process's memory (the suite's default `array` store).
    config(['cache.default' => 'database']);
    $this->setUpOverrideHash();
});

function auditRowsWithout(string $password): bool
{
    return AuditLog::query()->get()->every(
        fn (AuditLog $row) => ! str_contains(json_encode($row->toArray(), JSON_THROW_ON_ERROR), $password),
    );
}

it('refuses a user without menu_visibility:manage, in words', function () {
    $token = $this->tokenFor($this->staffWithRole('viewer'));

    $this->openOverride($token)
        ->assertForbidden()
        ->assertJsonPath('message', "You don't have access to Menu Visibility.");

    expect(AuditLog::query()->where('action', 'like', 'menu_visibility_override_%')->exists())->toBeFalse();
});

it('answers 503 while no hash is set up, and fails closed on a malformed one', function (?string $hash) {
    config(['menu_visibility.override_password_hash' => $hash]);

    $this->openOverride($this->tokenFor($this->superAdmin()))
        ->assertStatus(503)
        ->assertExactJson(['message' => "The administrator override isn't set up on this server yet."]);

    expect(MenuVisibilityOverride::query()->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'menu_visibility_override_refused')->sole()->description)
        ->toBe('Administrator override for Menu Visibility refused: not set up on this server');
})->with([
    'none' => [null],
    'empty' => [''],
    'not a hash' => ['Throwaway override 2026!'],
    'not bcrypt' => ['$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$RdescudvJCsgt3ub+b+dWRWJTmaaJObG'],
    'cut short' => ['$2y$04$abcdefghijklmnopqrstuu'],
    'a legacy $2a$ hash' => ['$2a$04$'.str_repeat('a', 53)],
]);

it('accepts a $2b$ hash, as other bcrypt tools write it', function () {
    config(['menu_visibility.override_password_hash' => '$2b$'.substr(Hash::driver('bcrypt')->make(self::OVERRIDE_PASSWORD, ['rounds' => 4]), 4)]);

    $this->openOverride($this->tokenFor($this->superAdmin()))->assertOk();
    $this->asToken($this->tokenFor($this->superAdmin()), 'POST', '/api/menu-visibility/override', ['password' => 'Not the one 123'])->assertStatus(422);
});

it('refuses a wrong password with 422 in words, audited without the password', function () {
    $admin = $this->staffWithRole('admin');

    $this->asToken($this->tokenFor($admin), 'POST', '/api/menu-visibility/override', ['password' => 'Not the one 123'])
        ->assertStatus(422)
        ->assertExactJson([
            'message' => "That password isn't right.",
            'errors' => ['password' => ["That password isn't right."]],
        ]);

    $row = AuditLog::query()->where('action', 'menu_visibility_override_refused')->sole();

    expect($row->user_id)->toBe($admin->id)
        ->and($row->auditable_type)->toBeNull()
        ->and($row->description)->toBe('Administrator override for Menu Visibility refused: wrong password')
        ->and(auditRowsWithout('Not the one 123'))->toBeTrue()
        ->and(MenuVisibilityOverride::query()->exists())->toBeFalse();
});

it('treats a blank or non-text password as a wrong try', function (mixed $password) {
    $this->asToken($this->tokenFor($this->superAdmin()), 'POST', '/api/menu-visibility/override', ['password' => $password])
        ->assertStatus(422)
        ->assertJsonPath('errors.password.0', "That password isn't right.");

    expect(AuditLog::query()->where('action', 'menu_visibility_override_refused')->count())->toBe(1);
})->with([
    'blank' => [''],
    'missing' => [null],
    'a list' => [['Throwaway override 2026!']],
]);

it('opens the page for 15 minutes on the right password, audited without it', function () {
    $this->freezeSecond();
    $admin = $this->staffWithRole('admin');
    $token = $this->tokenFor($admin);

    $response = $this->openOverride($token)->assertOk();

    $expiresAt = now()->addMinutes(15);
    expect($response->json('data.expires_at'))->toBe($expiresAt->toIso8601String())
        ->and(array_keys($response->json('data')))->toBe(['expires_at'])
        ->and(json_encode($response->json(), JSON_THROW_ON_ERROR))->not->toContain(self::OVERRIDE_PASSWORD);

    $row = AuditLog::query()->where('action', 'menu_visibility_override_granted')->sole();

    expect($row->user_id)->toBe($admin->id)
        ->and($row->description)->toBe('Administrator override for Menu Visibility granted until '.$expiresAt->format('M j, Y, g:i A'))
        ->and(auditRowsWithout(self::OVERRIDE_PASSWORD))->toBeTrue();

    $this->asToken($this->tokenFor($this->superAdmin()), 'GET', "/api/audit-logs/{$row->id}")
        ->assertOk()
        ->assertJsonPath('data.changes', [['field' => 'expires_at', 'old' => null, 'new' => $expiresAt->format('M j, Y, g:i A')]]);

    $this->asToken($token, 'GET', '/api/menu-visibility')
        ->assertOk()
        ->assertJsonPath('data.override_expires_at', $expiresAt->toIso8601String());
});

it('reads the length of the override from config', function () {
    $this->freezeSecond();
    config(['menu_visibility.override_minutes' => 5]);

    $this->openOverride($this->tokenFor($this->superAdmin()))
        ->assertOk()
        ->assertJsonPath('data.expires_at', now()->addMinutes(5)->toIso8601String());
});

it('ends the override when its 15 minutes are up', function () {
    $token = $this->tokenFor($this->superAdmin());
    $this->openOverride($token)->assertOk();

    $this->travel(14)->minutes();
    $this->asToken($token, 'GET', '/api/menu-visibility')->assertOk();

    $this->travel(2)->minutes();
    $this->asToken($token, 'GET', '/api/menu-visibility')
        ->assertForbidden()
        ->assertExactJson([
            'message' => 'Enter the administrator password to open Menu Visibility.',
            'code' => 'override_required',
        ]);
});

it('ends the override when the user signs out', function () {
    $admin = $this->staffWithRole('admin');
    $token = $this->tokenFor($admin);
    $this->openOverride($token)->assertOk();

    $this->asToken($token, 'POST', '/api/auth/logout')->assertOk();

    expect(MenuVisibilityOverride::query()->exists())->toBeFalse();

    $this->asToken($this->tokenFor($admin), 'GET', '/api/menu-visibility')
        ->assertForbidden()
        ->assertJsonPath('code', 'override_required');
});

it('ends the override on Lock, audited', function () {
    $admin = $this->staffWithRole('admin');
    $token = $this->tokenFor($admin);
    $this->openOverride($token)->assertOk();

    $this->asToken($token, 'DELETE', '/api/menu-visibility/override')->assertNoContent();

    $this->asToken($token, 'GET', '/api/menu-visibility')->assertForbidden()->assertJsonPath('code', 'override_required');

    $row = AuditLog::query()->where('action', 'menu_visibility_override_ended')->sole();
    expect([$row->user_id, $row->description])->toBe([$admin->id, 'Administrator override for Menu Visibility ended'])
        ->and(auditRowsWithout(self::OVERRIDE_PASSWORD))->toBeTrue();

    // Lock with nothing open ends nothing, and writes nothing.
    $this->asToken($token, 'DELETE', '/api/menu-visibility/override')->assertNoContent();
    expect(AuditLog::query()->where('action', 'menu_visibility_override_ended')->count())->toBe(1);
});

it('refuses Lock without the permission', function () {
    $this->asToken($this->tokenFor($this->staffWithRole('viewer')), 'DELETE', '/api/menu-visibility/override')->assertForbidden();
});

it('never shares the override with another sign-in of the same user', function () {
    $admin = $this->staffWithRole('admin');
    $first = $this->tokenFor($admin);
    $second = $this->tokenFor($admin);

    $this->openOverride($first)->assertOk();

    $this->asToken($second, 'GET', '/api/menu-visibility')->assertForbidden()->assertJsonPath('code', 'override_required');
    $this->asToken($first, 'GET', '/api/menu-visibility')->assertOk();
});

it('keeps the override, never longer, when the sign-in renews its token', function () {
    $this->freezeSecond();
    $token = $this->tokenFor($this->superAdmin());
    $expiresAt = $this->openOverride($token)->assertOk()->json('data.expires_at');

    $this->travel(5)->minutes();
    $renewed = $this->asToken($token, 'POST', '/api/auth/refresh')->assertOk()->json('token');

    $this->asToken($renewed, 'GET', '/api/menu-visibility')
        ->assertOk()
        ->assertJsonPath('data.override_expires_at', $expiresAt);

    expect(MenuVisibilityOverride::query()->count())->toBe(1);

    $this->travel(11)->minutes();
    $this->asToken($renewed, 'GET', '/api/menu-visibility')->assertForbidden()->assertJsonPath('code', 'override_required');
});

it('binds the override to the token it was opened on', function () {
    $admin = $this->staffWithRole('admin');
    $token = $this->tokenFor($admin);
    $this->openOverride($token)->assertOk();

    $row = MenuVisibilityOverride::query()->sole();

    expect($row->personal_access_token_id)->toBe(PersonalAccessToken::findToken($token)->id)
        ->and($row->user_id)->toBe($admin->id);
});

it('locks the override after 5 wrong tries for 15 minutes, the right password included', function () {
    $token = $this->tokenFor($this->superAdmin());

    foreach (range(1, 5) as $try) {
        $this->asToken($token, 'POST', '/api/menu-visibility/override', ['password' => "wrong {$try}"])->assertStatus(422);
    }

    $this->openOverride($token)
        ->assertStatus(429)
        ->assertJsonPath('message', 'Too many tries. Try again in 15 minutes.');

    $refused = AuditLog::query()->where('action', 'menu_visibility_override_refused')->orderBy('id')->pluck('description')->all();

    expect($refused)->toHaveCount(6)
        ->and(end($refused))->toBe('Administrator override for Menu Visibility refused: too many tries')
        ->and(AuditLog::query()->where('action', 'menu_visibility_override_granted')->exists())->toBeFalse();

    $this->travel(16)->minutes();
    $this->openOverride($token)->assertOk();
});

it('counts only wrong tries toward the lockout', function () {
    $token = $this->tokenFor($this->superAdmin());

    foreach (range(1, 6) as $try) {
        $this->openOverride($token)->assertOk();
    }
});

it('locks per user and per address', function () {
    $first = $this->staffWithRole('admin');
    $second = $this->staffWithRole('admin');
    $home = ['REMOTE_ADDR' => '10.10.0.1'];
    $elsewhere = ['REMOTE_ADDR' => '10.20.0.2'];

    foreach (range(1, 5) as $try) {
        $this->asToken($this->tokenFor($first), 'POST', '/api/menu-visibility/override', ['password' => "wrong {$try}"], $home)->assertStatus(422);
    }

    // The same user from another address: still locked (the user's own key).
    $this->openOverride($this->tokenFor($first), $elsewhere)->assertStatus(429);
    // Another user from the same address: locked too (the address key).
    $this->openOverride($this->tokenFor($second), $home)->assertStatus(429);
    // Another user from another address: free.
    $this->openOverride($this->tokenFor($second), $elsewhere)->assertOk();
});

it('keeps the password field out of flashed input', function () {
    $handler = app(ExceptionHandler::class);
    $dontFlash = (new ReflectionProperty($handler, 'dontFlash'))->getValue($handler);

    expect($dontFlash)->toContain('password');
});

/**
 * Swap the bcrypt driver for one that counts its checks and runs `$during`
 * inside the first, as other tries would arrive while one is being checked.
 */
function countPasswordChecks(?Closure $during = null): stdClass
{
    $seen = new stdClass;
    $seen->checks = 0;

    Hash::forgetDrivers();
    Hash::extend('bcrypt', fn () => new class($seen, $during) extends BcryptHasher
    {
        public function __construct(private stdClass $seen, private ?Closure $during)
        {
            parent::__construct(['rounds' => 4]);
        }

        public function check(#[SensitiveParameter] $value, $hashedValue, array $options = []): bool
        {
            if (++$this->seen->checks === 1 && $this->during !== null) {
                ($this->during)();
            }

            return parent::check($value, $hashedValue, $options);
        }
    });

    return $seen;
}

it('lets at most 5 tries reach the password check, however many are sent at once', function () {
    $admin = $this->staffWithRole('admin');
    $lockout = app(OverrideLockout::class);
    $admittedMeanwhile = 0;

    // 19 more tries arrive while the first one's password is being checked.
    $seen = countPasswordChecks(function () use ($lockout, $admin, &$admittedMeanwhile) {
        foreach (range(1, 19) as $try) {
            $admittedMeanwhile += $lockout->take($admin, '127.0.0.1') === null ? 1 : 0;
        }
    });

    $this->asToken($this->tokenFor($admin), 'POST', '/api/menu-visibility/override', ['password' => 'wrong'])->assertStatus(422);

    expect($admittedMeanwhile)->toBe(4)
        ->and($seen->checks)->toBe(1);

    $this->openOverride($this->tokenFor($admin))->assertStatus(429);
    expect($seen->checks)->toBe(1);
});

it('counts each try before its password is checked, so a sixth is refused unchecked and audited', function () {
    $seen = countPasswordChecks();
    $token = $this->tokenFor($this->superAdmin());

    foreach (range(1, 5) as $try) {
        $this->asToken($token, 'POST', '/api/menu-visibility/override', ['password' => "wrong {$try}"])->assertStatus(422);
    }

    $this->asToken($token, 'POST', '/api/menu-visibility/override', ['password' => 'wrong 6'])
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('message', 'Too many tries. Try again in 15 minutes.');

    expect($seen->checks)->toBe(5)
        ->and(AuditLog::query()->where('description', 'Administrator override for Menu Visibility refused: too many tries')->count())->toBe(1);
});

it('gives a right password its try back', function () {
    $token = $this->tokenFor($this->superAdmin());

    foreach (range(1, 4) as $try) {
        $this->asToken($token, 'POST', '/api/menu-visibility/override', ['password' => "wrong {$try}"])->assertStatus(422);
    }

    $this->openOverride($token)->assertOk();
    $this->asToken($token, 'POST', '/api/menu-visibility/override', ['password' => 'wrong 5'])->assertStatus(422);
    $this->asToken($token, 'POST', '/api/menu-visibility/override', ['password' => 'wrong 6'])->assertStatus(429);
});

it('never lets more than 5 tries reach the check in any 15 minutes, across a window\'s edge', function () {
    $this->freezeSecond();
    $token = $this->tokenFor($this->superAdmin());
    $wrong = fn (int $try) => $this->asToken($token, 'POST', '/api/menu-visibility/override', ['password' => "wrong {$try}"]);

    $wrong(1)->assertStatus(422);
    $this->travel(14)->minutes();
    foreach (range(2, 5) as $try) {
        $wrong($try)->assertStatus(422);
    }

    // The first try has left the 15 minutes; the other four haven't.
    $this->travel(61)->seconds();
    $wrong(6)->assertStatus(422);
    $wrong(7)->assertStatus(429)->assertJsonPath('message', 'Too many tries. Try again in 14 minutes.');

    // Still five in the last 15 minutes until the four at minute 14 leave.
    $this->travel(13)->minutes();
    $wrong(8)->assertStatus(429);
    $this->travel(1)->minutes();
    $wrong(9)->assertStatus(422);
});

it('refuses a try, unchecked, while it can\'t take its turn to be counted', function () {
    $seen = countPasswordChecks();
    $held = Cache::lock(OverrideLockout::LOCK, 30);
    expect($held->get())->toBeTrue();

    try {
        $this->openOverride($this->tokenFor($this->superAdmin()))
            ->assertStatus(429)
            ->assertJsonPath('message', 'Too many tries. Try again in 1 minute.');
    } finally {
        $held->release();
    }

    expect($seen->checks)->toBe(0)
        ->and(MenuVisibilityOverride::query()->exists())->toBeFalse();
});

it('stays closed, unchecked and audited, while the cache can\'t count tries between requests', function (string $driver) {
    config(['cache.stores.this_process' => ['driver' => $driver], 'cache.default' => 'this_process']);
    $seen = countPasswordChecks();

    $this->openOverride($this->tokenFor($this->superAdmin()))
        ->assertStatus(503)
        ->assertExactJson(['message' => "The administrator override needs a shared cache on this server (array or null caches can't count tries)."]);

    expect($seen->checks)->toBe(0)
        ->and(MenuVisibilityOverride::query()->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'menu_visibility_override_refused')->sole()->description)
        ->toBe('Administrator override for Menu Visibility refused: no shared cache on this server to count tries')
        ->and(auditRowsWithout(self::OVERRIDE_PASSWORD))->toBeTrue();
})->with(['array', 'null']);

it('counts tries in the database store, which a fresh store instance still reads', function () {
    $token = $this->tokenFor($this->superAdmin());

    foreach (range(1, 5) as $try) {
        $this->asToken($token, 'POST', '/api/menu-visibility/override', ['password' => "wrong {$try}"])->assertStatus(422);
        // Nothing in this process's memory carries over to the next request.
        Cache::forgetDriver('database');
    }

    $this->openOverride($token)->assertStatus(429);
});
