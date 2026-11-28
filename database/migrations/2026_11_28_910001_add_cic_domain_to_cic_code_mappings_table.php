<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which CIC code list a mapping's code belongs to. A valid-ID kind maps to
 * either the IDTypeDomain or the IdentificationTypeDomain, whose codes overlap
 * ("10" is a driver's license in one and a TIN in the other), so the code
 * alone can't say which list it came from. Collateral types always map to the
 * GuaranteesDomain. New table, no rows yet: nothing to backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cic_code_mappings', function (Blueprint $table) {
            $table->string('cic_domain', 40)->nullable()->after('lendy_value');
        });
    }

    public function down(): void
    {
        Schema::table('cic_code_mappings', function (Blueprint $table) {
            $table->dropColumn('cic_domain');
        });
    }
};
