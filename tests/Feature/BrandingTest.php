<?php

namespace Tests\Feature;

use App\Models\BrandingSetting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Mirrors Tests\Traits\SetupLendyPH::seedAndLogin() seeding, but leaves
        // authentication per-test so the public/unauthenticated path is genuine.
        Artisan::call('migrate:fresh');
        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');

        $this->admin = User::where('username', 'super_admin')->firstOrFail();
    }

    public function test_public_branding_is_reachable_unauthenticated_and_null_on_fresh_deployment(): void
    {
        $this->getJson('/api/branding/public')
            ->assertOk()
            ->assertExactJson(['data' => [
                'logo_url' => null,
                'organization_name' => null,
                'organization_address' => null,
                'organization_contact' => null,
            ]]);
    }

    public function test_admin_can_upload_logo_then_public_endpoint_serves_it(): void
    {
        $this->actingAs($this->admin);

        $response = $this->postJson('/api/settings/branding/logo', [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertOk()
            ->assertJsonPath('data.message', 'Logo updated successfully.');

        $logoUrl = $response->json('data.logo_url');
        $this->assertNotNull($logoUrl);
        $this->assertStringContainsString('/storage/branding/', $logoUrl);

        // The file is physically stored on the public disk.
        $storedPath = BrandingSetting::current()->logo_path;
        $this->assertNotNull($storedPath);
        Storage::disk('public')->assertExists($storedPath);

        // The public, unauthenticated endpoint now serves the same URL.
        $this->getJson('/api/branding/public')
            ->assertOk()
            ->assertJsonPath('data.logo_url', $logoUrl);
    }

    public function test_authed_show_returns_logo_url_for_user_with_settings_view(): void
    {
        $this->actingAs($this->admin);
        $this->postJson('/api/settings/branding/logo', [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertOk();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('settings:view');
        $this->actingAs($viewer);

        $logoUrl = BrandingSetting::current()->logo_url;

        $this->getJson('/api/settings/branding')
            ->assertOk()
            ->assertJsonPath('data.logo_url', $logoUrl);
    }

    public function test_user_with_settings_update_but_not_super_admin_can_upload(): void
    {
        // Proves the settings:update gate itself grants access — not just the
        // super_admin Gate::before bypass.
        $user = User::factory()->create();
        $user->givePermissionTo(['settings:view', 'settings:update']);
        $this->actingAs($user);

        $this->postJson('/api/settings/branding/logo', [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertOk()
            ->assertJsonPath('data.message', 'Logo updated successfully.');

        $this->assertNotNull(BrandingSetting::current()->logo_path);
    }

    public function test_user_without_settings_update_cannot_upload_or_delete(): void
    {
        // A user who can VIEW settings but lacks settings:update is locked out
        // of both mutating endpoints.
        $user = User::factory()->create();
        $user->givePermissionTo('settings:view');
        $this->actingAs($user);

        $this->postJson('/api/settings/branding/logo', [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertForbidden();

        $this->deleteJson('/api/settings/branding/logo')
            ->assertForbidden();

        $this->assertNull(BrandingSetting::current()->logo_path);
    }

    public function test_delete_removes_logo_file_and_nulls_the_url(): void
    {
        $this->actingAs($this->admin);

        $this->postJson('/api/settings/branding/logo', [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertOk();

        $storedPath = BrandingSetting::current()->logo_path;
        Storage::disk('public')->assertExists($storedPath);

        $this->deleteJson('/api/settings/branding/logo')
            ->assertOk()
            ->assertJsonPath('data.logo_url', null)
            ->assertJsonPath('data.message', 'Logo removed successfully.');

        // Column nulled, file gone, and the public endpoint reflects it.
        $this->assertNull(BrandingSetting::current()->logo_path);
        Storage::disk('public')->assertMissing($storedPath);

        $this->getJson('/api/branding/public')
            ->assertOk()
            ->assertJsonPath('data.logo_url', null);
    }

    public function test_replacement_upload_deletes_the_previous_file(): void
    {
        $this->actingAs($this->admin);

        $this->postJson('/api/settings/branding/logo', [
            'logo' => UploadedFile::fake()->image('first.png'),
        ])->assertOk();
        $firstPath = BrandingSetting::current()->logo_path;

        $this->postJson('/api/settings/branding/logo', [
            'logo' => UploadedFile::fake()->image('second.png'),
        ])->assertOk();
        $secondPath = BrandingSetting::current()->logo_path;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertExists($secondPath);
        Storage::disk('public')->assertMissing($firstPath);
    }

    public function test_upload_rejects_a_non_image_file(): void
    {
        $this->actingAs($this->admin);

        $this->postJson('/api/settings/branding/logo', [
            'logo' => UploadedFile::fake()->create('brochure.pdf', 200, 'application/pdf'),
        ])->assertUnprocessable();

        $this->assertNull(BrandingSetting::current()->logo_path);
    }

    public function test_singleton_never_creates_a_second_branding_row(): void
    {
        $this->actingAs($this->admin);

        $this->getJson('/api/branding/public')->assertOk();
        $this->postJson('/api/settings/branding/logo', [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertOk();
        $this->deleteJson('/api/settings/branding/logo')->assertOk();

        $this->assertSame(1, BrandingSetting::count());
    }

    /**
     * The logo is also reachable through the API, not just the public storage
     * path. /storage/** is served by nginx off the public/storage symlink and
     * never reaches PHP, so it carries no CORS headers — which is why the report
     * exporters' cross-origin fetch of the logo silently failed and every PDF
     * and DOCX fell back to a text header. This route is under api/*, so it is
     * covered by config/cors.php and, in the browser, by the frontend's
     * same-origin proxy.
     */
    public function test_logo_route_streams_the_image_unauthenticated(): void
    {
        $this->actingAs($this->admin)
            ->post('/api/settings/branding/logo', ['logo' => UploadedFile::fake()->image('logo.png')])
            ->assertOk();

        // Explicitly unauthenticated — the sign-in page renders this before
        // anyone has logged in.
        auth()->forgetGuards();

        $response = $this->get('/api/branding/logo');

        $response->assertOk();
        expect($response->headers->get('Content-Type'))->toStartWith('image/');
    }

    public function test_logo_route_404s_when_no_logo_is_configured(): void
    {
        $this->get('/api/branding/logo')->assertNotFound();
    }

    public function test_logo_route_is_cacheable_unlike_the_kyc_file_routes(): void
    {
        // FileController sends `private, no-store` because those are identity
        // documents. This is public branding on the login page, so it should be
        // cacheable — asserting the distinction so a copy-paste from
        // FileController does not quietly make every page load re-fetch it.
        $this->actingAs($this->admin)
            ->post('/api/settings/branding/logo', ['logo' => UploadedFile::fake()->image('logo.png')])
            ->assertOk();
        auth()->forgetGuards();

        $cacheControl = $this->get('/api/branding/logo')->headers->get('Cache-Control');

        expect($cacheControl)->toContain('public')
            ->and($cacheControl)->not->toContain('no-store');
    }

    public function test_admin_can_update_all_three_organization_fields(): void
    {
        $this->actingAs($this->admin);

        $this->putJson('/api/settings/branding', [
            'organization_name' => 'Binhs Multi-Purpose Cooperative',
            'organization_address' => '123 Rizal St., Brgy. Poblacion, Bacolod City',
            'organization_contact' => '(034) 123-4567 / info@binhscoop.ph',
        ])->assertOk()
            ->assertJsonPath('data.organization_name', 'Binhs Multi-Purpose Cooperative')
            ->assertJsonPath('data.organization_address', '123 Rizal St., Brgy. Poblacion, Bacolod City')
            ->assertJsonPath('data.organization_contact', '(034) 123-4567 / info@binhscoop.ph');

        // Persisted, not just echoed back.
        $setting = BrandingSetting::current()->fresh();
        $this->assertSame('Binhs Multi-Purpose Cooperative', $setting->organization_name);
        $this->assertSame('123 Rizal St., Brgy. Poblacion, Bacolod City', $setting->organization_address);
        $this->assertSame('(034) 123-4567 / info@binhscoop.ph', $setting->organization_contact);

        $this->assertSame(1, BrandingSetting::count());
    }

    public function test_update_returns_the_same_shape_as_show(): void
    {
        // Reports and printables read one payload shape; PUT and GET must not
        // drift apart or the settings page would render stale fields after save.
        $this->actingAs($this->admin);

        $updateBody = $this->putJson('/api/settings/branding', [
            'organization_name' => 'Alpha Coop',
            'organization_address' => 'Alpha St.',
            'organization_contact' => '0917-000-0000',
        ])->assertOk()->json();

        $showBody = $this->getJson('/api/settings/branding')->assertOk()->json();

        $this->assertSame($updateBody, $showBody);
    }

    public function test_show_returns_organization_identity_alongside_the_logo(): void
    {
        $this->actingAs($this->admin);
        $this->postJson('/api/settings/branding/logo', [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertOk();
        $this->putJson('/api/settings/branding', [
            'organization_name' => 'Binhs Multi-Purpose Cooperative',
            'organization_address' => '123 Rizal St.',
            'organization_contact' => '(034) 123-4567',
        ])->assertOk();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('settings:view');
        $this->actingAs($viewer);

        $this->getJson('/api/settings/branding')
            ->assertOk()
            ->assertExactJson(['data' => [
                'logo_url' => BrandingSetting::current()->logo_url,
                'organization_name' => 'Binhs Multi-Purpose Cooperative',
                'organization_address' => '123 Rizal St.',
                'organization_contact' => '(034) 123-4567',
            ]]);
    }

    public function test_public_endpoint_exposes_the_organization_identity_without_auth_and_nothing_else(): void
    {
        // The sign-in screen names the cooperative before anyone has an account,
        // so this is public on purpose. assertExactJson is the guard: it fails
        // the moment an unrelated column is added to the payload.
        $this->actingAs($this->admin)
            ->putJson('/api/settings/branding', [
                'organization_name' => 'Binhs Multi-Purpose Cooperative',
                'organization_address' => '123 Rizal St.',
                'organization_contact' => '(034) 123-4567',
            ])->assertOk();

        auth()->forgetGuards();

        $this->getJson('/api/branding/public')
            ->assertOk()
            ->assertExactJson(['data' => [
                'logo_url' => null,
                'organization_name' => 'Binhs Multi-Purpose Cooperative',
                'organization_address' => '123 Rizal St.',
                'organization_contact' => '(034) 123-4567',
            ]]);
    }

    public function test_update_rejects_an_over_length_organization_name(): void
    {
        $this->actingAs($this->admin);

        $this->putJson('/api/settings/branding', [
            'organization_name' => str_repeat('a', 256),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('organization_name');

        $this->assertNull(BrandingSetting::current()->fresh()->organization_name);
    }

    public function test_update_rejects_an_over_length_address_and_contact(): void
    {
        // The address column is VARCHAR(500) precisely so this is a 422 and not
        // a driver-level "Data too long" 500.
        $this->actingAs($this->admin);

        $this->putJson('/api/settings/branding', [
            'organization_address' => str_repeat('a', 501),
            'organization_contact' => str_repeat('a', 256),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['organization_address', 'organization_contact']);

        $setting = BrandingSetting::current()->fresh();
        $this->assertNull($setting->organization_address);
        $this->assertNull($setting->organization_contact);
    }

    public function test_update_accepts_an_address_at_the_full_column_length(): void
    {
        $this->actingAs($this->admin);

        $address = str_repeat('a', 500);

        $this->putJson('/api/settings/branding', ['organization_address' => $address])
            ->assertOk()
            ->assertJsonPath('data.organization_address', $address);

        $this->assertSame($address, BrandingSetting::current()->fresh()->organization_address);
    }

    public function test_user_without_settings_update_cannot_change_the_organization_identity(): void
    {
        // Same gate as the logo endpoints: settings:view is not enough.
        $user = User::factory()->create();
        $user->givePermissionTo('settings:view');
        $this->actingAs($user);

        $this->putJson('/api/settings/branding', [
            'organization_name' => 'Impostor Coop',
        ])->assertForbidden();

        $this->assertNull(BrandingSetting::current()->fresh()->organization_name);
    }

    public function test_user_with_settings_update_but_not_super_admin_can_change_the_organization_identity(): void
    {
        // Proves the settings:update gate itself grants access, not the
        // super_admin Gate::before bypass.
        $user = User::factory()->create();
        $user->givePermissionTo(['settings:view', 'settings:update']);
        $this->actingAs($user);

        $this->putJson('/api/settings/branding', ['organization_name' => 'Binhs Coop'])
            ->assertOk()
            ->assertJsonPath('data.organization_name', 'Binhs Coop');
    }

    public function test_unauthenticated_user_cannot_change_the_organization_identity(): void
    {
        $this->putJson('/api/settings/branding', ['organization_name' => 'Impostor Coop'])
            ->assertUnauthorized();

        $this->assertNull(BrandingSetting::current()->fresh()->organization_name);
    }

    public function test_update_writes_only_the_submitted_fields(): void
    {
        $this->actingAs($this->admin);

        $this->putJson('/api/settings/branding', [
            'organization_name' => 'Binhs Coop',
            'organization_address' => '123 Rizal St.',
            'organization_contact' => '(034) 123-4567',
        ])->assertOk();

        // A payload carrying one key must not blank out the other two.
        $this->putJson('/api/settings/branding', ['organization_contact' => '(034) 999-9999'])
            ->assertOk()
            ->assertJsonPath('data.organization_name', 'Binhs Coop')
            ->assertJsonPath('data.organization_address', '123 Rizal St.')
            ->assertJsonPath('data.organization_contact', '(034) 999-9999');
    }

    public function test_a_cleared_field_is_stored_as_null_not_an_empty_string(): void
    {
        // The clients fall back to the app-level name with `?? siteConfig.name`,
        // which an empty string would slip past — the letterhead would then print
        // a blank organization instead of the fallback.
        $this->actingAs($this->admin);

        $this->putJson('/api/settings/branding', ['organization_name' => 'Binhs Coop'])->assertOk();

        $this->putJson('/api/settings/branding', ['organization_name' => ''])
            ->assertOk()
            ->assertJsonPath('data.organization_name', null);

        $this->assertNull(BrandingSetting::current()->fresh()->organization_name);
    }

    public function test_organization_identity_survives_a_logo_upload_and_removal(): void
    {
        // logo_path and the identity columns share one singleton row; neither
        // write may clobber the other.
        $this->actingAs($this->admin);

        $this->putJson('/api/settings/branding', ['organization_name' => 'Binhs Coop'])->assertOk();

        $this->postJson('/api/settings/branding/logo', [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertOk();
        $this->deleteJson('/api/settings/branding/logo')->assertOk();

        $this->getJson('/api/branding/public')
            ->assertOk()
            ->assertJsonPath('data.logo_url', null)
            ->assertJsonPath('data.organization_name', 'Binhs Coop');

        $this->assertSame(1, BrandingSetting::count());
    }
}
