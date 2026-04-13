<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_workflow_settings', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['normal', 'policy_exception']);
            $table->json('steps');
            $table->timestamps();

            $table->unique('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_workflow_settings');
    }
};
