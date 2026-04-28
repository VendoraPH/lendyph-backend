<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collateral_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('detail_field_label');
            $table->string('amount_field_label');
            $table->enum('source', ['manual', 'share_capital'])->default('manual');
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->boolean('is_seed')->default(false);
            $table->timestamps();
        });

        $now = now();
        DB::table('collateral_types')->insert([
            [
                'name' => 'Land Title',
                'detail_field_label' => 'Title No.',
                'amount_field_label' => 'Appraised Value',
                'source' => 'manual',
                'display_order' => 1,
                'is_visible' => true,
                'is_seed' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Chattel',
                'detail_field_label' => 'Chattel No.',
                'amount_field_label' => 'Appraised Value',
                'source' => 'manual',
                'display_order' => 2,
                'is_visible' => true,
                'is_seed' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Share Capital',
                'detail_field_label' => 'Pledge Reference',
                'amount_field_label' => 'Amount',
                'source' => 'share_capital',
                'display_order' => 3,
                'is_visible' => true,
                'is_seed' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Stock Certificate',
                'detail_field_label' => 'Stock Certificate No.',
                'amount_field_label' => 'Appraised Value',
                'source' => 'manual',
                'display_order' => 4,
                'is_visible' => true,
                'is_seed' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('collateral_types');
    }
};
