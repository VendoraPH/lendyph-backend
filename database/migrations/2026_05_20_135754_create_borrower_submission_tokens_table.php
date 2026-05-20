<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('borrower_submission_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrower_id')->constrained('borrowers')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->ipAddress('ip_address')->nullable();
            $table->timestamps();

            $table->index(['borrower_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('borrower_submission_tokens');
    }
};
