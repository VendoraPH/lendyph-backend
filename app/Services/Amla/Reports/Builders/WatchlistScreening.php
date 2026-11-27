<?php

namespace App\Services\Amla\Reports\Builders;

use App\Models\AmlaScreeningMatch;
use App\Models\AmlaWatchlistVersion;
use App\Models\Borrower;
use App\Models\CoMaker;
use App\Models\GCashNonMember;
use App\Models\User;
use App\Services\Amla\Detection\Finding;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Reports\ComplianceReport;
use App\Services\Amla\Reports\ReportFilters;
use App\Services\Amla\Reports\ReportNames;

/**
 * Report 6, Watchlist and sanctions screening: the period's possible matches
 * (when found), their list and version, status, reviewer and resolution.
 * Head office only, and restricted: the listed person's details and the
 * reviewer's written reason are never shown, only that a finding was
 * recorded.
 */
final class WatchlistScreening extends ComplianceReport
{
    private const STATUS_WORDS = ['pending' => 'Pending review', 'confirmed' => 'Confirmed match', 'cleared' => 'Cleared (not a match)'];

    public function key(): string
    {
        return 'watchlist_screening';
    }

    public function title(): string
    {
        return 'Watchlist and Sanctions Screening';
    }

    public function description(): string
    {
        return 'Possible watchlist matches, their list version, review status, reviewer and resolution date.';
    }

    public function permissions(): array
    {
        return [AmlaAccess::SCREENING];
    }

    public function headOffice(): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [
            ['key' => 'reference', 'label' => 'Screening reference', 'format' => 'text'],
            ['key' => 'subject', 'label' => 'Subject', 'format' => 'text'],
            ['key' => 'source', 'label' => 'Source list', 'format' => 'text'],
            ['key' => 'list_version', 'label' => 'List version', 'format' => 'text'],
            ['key' => 'score', 'label' => 'Match score', 'format' => 'number'],
            ['key' => 'status', 'label' => 'Match status', 'format' => 'text'],
            ['key' => 'findings', 'label' => 'Review findings', 'format' => 'text'],
            ['key' => 'reviewer', 'label' => 'Reviewer', 'format' => 'text'],
            ['key' => 'resolved_on', 'label' => 'Resolution date', 'format' => 'text'],
        ];
    }

    public function rows(User $viewer, ReportFilters $filters): array
    {
        $matches = AmlaScreeningMatch::query()->whereBetween('created_at', $filters->moments())->orderBy('id')->get();
        $versions = AmlaWatchlistVersion::query()->whereIn('id', $matches->pluck('version_id'))->pluck('version_label', 'id');
        $reviewers = User::query()->whereIn('id', $matches->pluck('decided_by')->filter())->get()->keyBy('id');

        return $matches->map(fn (AmlaScreeningMatch $match) => [
            'reference' => 'SCR-'.str_pad((string) $match->id, 6, '0', STR_PAD_LEFT),
            'subject' => $this->subject($match),
            'source' => AmlaWatchlistVersion::SOURCES[$match->source]['words'] ?? $match->source,
            'list_version' => $versions[$match->version_id] ?? 'Version #'.$match->version_id,
            'score' => (int) $match->score,
            'status' => self::STATUS_WORDS[$match->status] ?? ucfirst((string) $match->status),
            'findings' => $match->status === AmlaScreeningMatch::PENDING ? null : 'Recorded',
            'reviewer' => $match->decided_by === null ? null : ReportNames::user($reviewers->get($match->decided_by)),
            'resolved_on' => $match->decided_at?->format('M j, Y'),
        ])->values()->all();
    }

    private function subject(AmlaScreeningMatch $match): string
    {
        return match ($match->subject_type) {
            'member' => trim((string) Borrower::query()->find($match->subject_id)?->full_name) ?: Finding::NOT_AVAILABLE,
            'walk_in' => (trim((string) GCashNonMember::query()->withTrashed()->find($match->subject_id)?->full_name) ?: Finding::NOT_AVAILABLE).' (walk-in)',
            'co_maker' => (trim((string) CoMaker::query()->find($match->subject_id)?->full_name) ?: Finding::NOT_AVAILABLE).' (co-maker)',
            default => Finding::NOT_AVAILABLE,
        };
    }
}
