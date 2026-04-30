<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->boolean('auto_pay')->default(false)->after('grace_period_days');
            $table->string('cbs_reference', 100)->nullable()->after('auto_pay');
            $table->timestamp('auto_pay_enabled_at')->nullable()->after('cbs_reference');
            $table->foreignId('auto_pay_enabled_by')
                ->nullable()
                ->after('auto_pay_enabled_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->index('auto_pay');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropForeign(['auto_pay_enabled_by']);
            $table->dropIndex(['auto_pay']);
            $table->dropColumn(['auto_pay', 'cbs_reference', 'auto_pay_enabled_at', 'auto_pay_enabled_by']);
        });
    }
};
