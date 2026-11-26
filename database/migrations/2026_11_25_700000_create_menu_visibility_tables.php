<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu Visibility (finish run round 6).
 *
 * `menu_visibility_switches` holds only what was switched: one row per side-menu
 * module an administrator turned off (or back on), with who did it and when. A
 * module with no row is shown. Hiding deletes nothing, so turning a module back
 * on restores it as it was.
 *
 * `menu_visibility_overrides` holds the open administrator overrides, one per
 * sign-in: bound to the Sanctum access token, so signing out (the token's row
 * is deleted) ends it, and another sign-in of the same user never shares it.
 * A row past `expires_at` opens nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_visibility_switches', function (Blueprint $table): void {
            $table->id();
            $table->string('module_key', 64)->unique();
            $table->boolean('hidden');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('menu_visibility_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('personal_access_token_id')->unique()->constrained('personal_access_tokens')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_visibility_overrides');
        Schema::dropIfExists('menu_visibility_switches');
    }
};
