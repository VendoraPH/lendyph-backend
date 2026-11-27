<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings → Linda (Linda v1 plan): one company-wide row holding the model
 * Linda uses and who last changed it. With no row the model is `LINDA_MODEL`
 * (config `linda.model`), so nothing is seeded here. The key, the cap and the
 * switch stay in the server's config, never in this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('linda_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('model', 100);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linda_settings');
    }
};
