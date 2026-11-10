<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The regular savings product a time deposit's early termination and
 * post-maturity interest are paid at, snapshotted on the placement
 * (`time_deposits.pretermination_product_id`), so relinking the time deposit
 * product later never changes what an existing placement earns. Added
 * nullable; a placement with none falls back to its product's current link.
 *
 * Existing placements are backfilled from their product's link, once: only
 * rows still empty are filled, each with one system audit row, so a rerun
 * changes and records nothing. No placement exists on any deployment yet;
 * the backfill covers staging and local data. Additive only, and safe to run
 * twice.
 */
return new class extends Migration
{
    private const NAME = '2026_11_10_503000_add_pretermination_product_id_to_time_deposits';

    public function up(): void
    {
        if (! Schema::hasColumn('time_deposits', 'pretermination_product_id')) {
            Schema::table('time_deposits', function (Blueprint $table): void {
                $table->foreignId('pretermination_product_id')->nullable()->after('product_id')
                    ->constrained('savings_products')->restrictOnDelete();
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        if (Schema::hasColumn('time_deposits', 'pretermination_product_id')) {
            Schema::table('time_deposits', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('pretermination_product_id');
            });
        }
    }

    private function backfill(): void
    {
        DB::table('time_deposits AS d')
            ->join('savings_products AS p', 'p.id', '=', 'd.product_id')
            ->whereNull('d.pretermination_product_id')
            ->whereNotNull('p.pretermination_product_id')
            ->orderBy('d.id')
            ->select(['d.id', 'd.certificate_number', 'p.pretermination_product_id AS link'])
            ->chunk(200, function ($rows): void {
                foreach ($rows as $row) {
                    $changed = DB::table('time_deposits')
                        ->where('id', $row->id)
                        ->whereNull('pretermination_product_id')
                        ->update(['pretermination_product_id' => $row->link]);

                    if ($changed === 0) {
                        continue;
                    }

                    DB::table('audit_logs')->insert([
                        'user_id' => null,
                        'action' => 'time_deposit_pretermination_product_backfilled',
                        'auditable_type' => 'App\\Models\\TimeDeposit',
                        'auditable_id' => $row->id,
                        'old_values' => json_encode(['pretermination_product_id' => null]),
                        'new_values' => json_encode(['pretermination_product_id' => (int) $row->link]),
                        'ip_address' => null,
                        'user_agent' => null,
                        'description' => sprintf(
                            'System change: time deposit %s now records the regular savings product its early termination and post-maturity interest are paid at (#%d), copied from its product by migration %s.',
                            $row->certificate_number,
                            $row->link,
                            self::NAME,
                        ),
                        'created_at' => now(),
                    ]);
                }
            });
    }
};
