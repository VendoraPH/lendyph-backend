<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CIC's public keys, the key history: nothing is ever deleted. A key is
 * uploaded pending, made active when a second settings holder verifies its
 * fingerprint with CIC, and retired with a reason.
 *
 * At most one key is active, held by the database too: `active_key` is 1 only
 * on the active row (NULL otherwise) and unique, so two verifications can't
 * both leave a key active. A verification retires the previous key before it
 * activates the new one, in one transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cic_public_keys', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->string('key_id', 16);
            $table->string('algorithm', 40);
            $table->json('user_ids');
            $table->dateTime('key_created_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->text('armored');
            $table->enum('status', ['pending', 'active', 'retired'])->default('pending');
            $table->unsignedTinyInteger('active_key')->nullable()->storedAs("case when `status` = 'active' then 1 else null end");
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->foreignId('retired_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('retired_at')->nullable();
            $table->string('retire_reason', 500)->nullable();
            $table->timestamps();

            $table->unique('active_key');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cic_public_keys');
    }
};
