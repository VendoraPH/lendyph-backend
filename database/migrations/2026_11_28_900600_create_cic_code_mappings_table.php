<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lendy values mapped to CIC codes, by domain: `collateral_type` (lendy_value
 * is a collateral_types id) and `id_kind` (a document label or kind). The CIC
 * codes themselves are the read-only lists in resources/cic/2016/domains.json.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cic_code_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 40);
            $table->string('lendy_value', 120);
            $table->string('cic_code', 10);
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['domain', 'lendy_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cic_code_mappings');
    }
};
