<?php

namespace App\Services\Amla\Foundation;

use Illuminate\Support\Facades\DB;

/**
 * Whether AMLA holds any record on a member (AMLA design §4.17): every AMLA
 * table with a `borrower_id`, found from the schema so a later slice's
 * tables count without being listed here. AMLA records are kept forever, so
 * such a member is never purged; the refusal names no table.
 */
final class AmlaMemberRecords
{
    public function holdsMember(int $borrowerId): bool
    {
        $tables = DB::table('information_schema.columns')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->where('table_name', 'like', 'amla\\_%')
            ->where('column_name', 'borrower_id')
            ->selectRaw('table_name as amla_table')
            ->pluck('amla_table');

        foreach ($tables as $table) {
            if (DB::table((string) $table)->where('borrower_id', $borrowerId)->exists()) {
                return true;
            }
        }

        return false;
    }
}
