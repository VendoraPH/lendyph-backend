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
            ->assertExactJson(['data' => ['logo_url' => null]]);
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
}
