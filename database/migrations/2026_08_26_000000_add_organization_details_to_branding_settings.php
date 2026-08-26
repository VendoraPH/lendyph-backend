<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branding_settings', function (Blueprint $table) {
            // Organization identity for the single cooperative this deployment
            // serves. Reports and legal printables (promissory note, disclosure
            // statement) read these for their letterhead instead of hardcoding
            // one cooperative's name. All nullable: existing deployments keep
            // working and fall back to the app-level name until an admin fills
            // them in.
            $table->string('organization_name')->nullable()->after('logo_path');
            // Sized to the 500-character validation cap in BrandingController::update();
            // the default VARCHAR(255) would reject a valid address with a driver-level
            // "Data too long" error instead of a 422.
            $table->string('organization_address', 500)->nullable()->after('organization_name');
            $table->string('organization_contact')->nullable()->after('organization_address');
        });
    }

    public function down(): void
    {
        Schema::table('branding_settings', function (Blueprint $table) {
            $table->dropColumn([
                'organization_name',
                'organization_address',
                'organization_contact',
            ]);
        });
    }
};
