<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('borrowers', function (Blueprint $table) {
            $table->string('spouse_first_name')->nullable()->after('monthly_income');
            $table->string('spouse_middle_name')->nullable()->after('spouse_first_name');
            $table->string('spouse_last_name')->nullable()->after('spouse_middle_name');
            $table->string('spouse_contact_number', 20)->nullable()->after('spouse_last_name');
            $table->string('spouse_occupation')->nullable()->after('spouse_contact_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('borrowers', function (Blueprint $table) {
            $table->dropColumn([
                'spouse_first_name',
                'spouse_middle_name',
                'spouse_last_name',
                'spouse_contact_number',
                'spouse_occupation',
            ]);
        });
    }
};
