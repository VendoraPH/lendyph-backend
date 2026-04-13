<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->decimal('scb_amount', 12, 2)->default(0)->after('net_proceeds');
            $table->boolean('policy_exception')->default(false)->after('grace_period_days');
            $table->text('policy_exception_details')->nullable()->after('policy_exception');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['scb_amount', 'policy_exception', 'policy_exception_details']);
        });
    }
};
