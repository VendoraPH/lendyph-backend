<?php

namespace Tests\Feature\Phase6\Amla\Reports;

use App\Models\AmlaScreeningMatch;
use App\Models\AmlaWatchlistEntry;
use App\Models\AmlaWatchlistVersion;
use App\Models\Borrower;
use Tests\Feature\Phase6\Amla\Cases\CaseTestHelpers;

/**
 * Shared set-up for the AMLA reports and exports tests (AMLA-B7): B6's case
 * helpers, plus a watchlist match written straight into B5's tables.
 */
trait ReportTestHelpers
{
    use CaseTestHelpers;

    /** @return array<string, mixed> the report's JSON `data` */
    protected function report(string $key, array $query = []): array
    {
        return $this->getJson("/api/amla/compliance-reports/{$key}?".http_build_query($query))->assertOk()->json('data');
    }

    /** @return list<array<string, string>> rows keyed by column key, as displayed */
    protected function rows(string $key, array $query = []): array
    {
        return $this->report($key, $query)['rows'];
    }

    protected function pendingMatch(Borrower $member): AmlaScreeningMatch
    {
        $version = AmlaWatchlistVersion::query()->create([
            'source' => 'internal', 'version_label' => 'Internal list 2026-11', 'file_sha256' => str_repeat('a', 64), 'entry_count' => 1,
            'status' => 'active', 'rescreen_status' => 'done', 'uploaded_by' => $this->admin->id,
        ]);
        $entry = AmlaWatchlistEntry::query()->create([
            'version_id' => $version->id, 'reference' => 'INT-1', 'kind' => 'person', 'full_name' => 'Listed Person',
            'aliases' => [], 'birth_years' => [], 'nationalities' => [], 'fingerprint' => str_repeat('b', 64),
        ]);

        return AmlaScreeningMatch::query()->create([
            'subject_type' => 'member', 'subject_id' => $member->id, 'entry_id' => $entry->id, 'version_id' => $version->id,
            'source' => 'internal', 'match_key' => "member:{$member->id}:{$entry->id}", 'score' => 92, 'threshold' => 85,
            'reasons' => ['Name matches'], 'status' => AmlaScreeningMatch::PENDING,
        ]);
    }
}
