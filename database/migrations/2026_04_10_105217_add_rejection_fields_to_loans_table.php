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
        Schema::table('loans', function (Blueprint $table) {
            $table->text('rejection_remarks')->nullable()->after('approval_remarks');
            $table->foreignId('rejected_by')->nullable()->after('rejection_remarks')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropForeign(['rejected_by']);
            $table->dropColumn(['rejection_remarks', 'rejected_by', 'rejected_at']);
        });
    }
};
