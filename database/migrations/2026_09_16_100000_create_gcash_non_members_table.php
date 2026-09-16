<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gcash_non_members', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('mobile_number', 32);
            $table->string('id_type', 64);
            $table->string('id_number', 64);
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Walk-ins are identified at the counter by the number they give.
            // Not unique: the same handset legitimately serves a household, and
            // a hard constraint here would block a real customer at the window
            // with no way for the teller to resolve it.
            $table->index('mobile_number');
            $table->index('full_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gcash_non_members');
    }
};
