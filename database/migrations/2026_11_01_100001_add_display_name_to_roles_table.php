<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An editable display name for roles. The Spatie `name` stays the key used in
 * permission checks and approval chains, so renaming a role in Settings changes
 * only this label.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('permission.table_names.roles', 'roles'), function (Blueprint $table) {
            $table->string('display_name', 100)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table(config('permission.table_names.roles', 'roles'), function (Blueprint $table) {
            $table->dropColumn('display_name');
        });
    }
};
