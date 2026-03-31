<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->after('id');
            $table->string('last_name')->after('first_name');
            $table->string('username')->unique()->after('last_name');
            $table->string('mobile_number', 20)->nullable()->after('email');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete()->after('mobile_number');
            $table->enum('status', ['active', 'deactivated'])->default('active')->after('branch_id');
            $table->timestamp('last_login_at')->nullable()->after('status');
            $table->dropColumn('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->after('id');
            $table->dropForeign(['branch_id']);
            $table->dropColumn([
                'first_name', 'last_name', 'username',
                'mobile_number', 'branch_id', 'status', 'last_login_at',
            ]);
        });
    }
};
