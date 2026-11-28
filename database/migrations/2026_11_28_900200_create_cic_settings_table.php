<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CIC reporting's settings (one row, CicSetting::current()): the provider code
 * CIC assigned, the format version (fixed at the 2016 CSDF's "1.0", shown and
 * never edited in v1), whether the lender reports as a microfinance
 * institution, and the reporting contact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cic_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider_code', 10)->nullable();
            $table->string('format_version', 12)->default('1.0');
            $table->boolean('is_mfi')->default(false);
            $table->string('institution_name', 160)->nullable();
            $table->string('contact_name', 120)->nullable();
            $table->string('contact_email', 120)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cic_settings');
    }
};
