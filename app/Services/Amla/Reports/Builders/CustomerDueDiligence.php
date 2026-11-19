<?php

namespace App\Services\Amla\Reports\Builders;

use App\Models\Borrower;
use App\Models\User;
use App\Services\Amla\Cdd\CddState;
use App\Services\Amla\Cdd\CddWorklist;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Reports\ComplianceReport;
use App\Services\Amla\Reports\ReportFilters;
use App\Services\Amla\Reports\ReportNames;

/**
 * Report 5, Customer due diligence: every member and walk-in in the caller's
 * scope, as of today, with the CDD state worked out now (the worklist's own
 * rule): identification status, missing KYC items, expired documents,
 * enhanced due diligence and review dates.
 */
final class CustomerDueDiligence extends ComplianceReport
{
    public function __construct(private readonly CddWorklist $worklist) {}

    public function key(): string
    {
        return 'customer_due_diligence';
    }

    public function title(): string
    {
        return 'Customer Due Diligence';
    }

    public function description(): string
    {
        return 'Each customer\'s identification status, missing KYC details and documents, enhanced due diligence and review dates.';
    }

    public function permissions(): array
    {
        return [AmlaAccess::CDD];
    }

    public function usesPeriod(): bool
    {
        return false;
    }

    public function columns(): array
    {
        return [
            ['key' => 'customer', 'label' => 'Customer', 'format' => 'text'],
            ['key' => 'branch', 'label' => 'Branch', 'format' => 'text'],
            ['key' => 'cdd_status', 'label' => 'CDD status', 'format' => 'text'],
            ['key' => 'identification', 'label' => 'Identification status', 'format' => 'text'],
            ['key' => 'missing', 'label' => 'Missing KYC details and documents', 'format' => 'text'],
            ['key' => 'expired', 'label' => 'Expired documents', 'format' => 'text'],
            ['key' => 'edd', 'label' => 'Enhanced due diligence', 'format' => 'text'],
            ['key' => 'last_review_on', 'label' => 'Last review', 'format' => 'text'],
            ['key' => 'next_review_on', 'label' => 'Next review', 'format' => 'text'],
        ];
    }

    public function rows(User $viewer, ReportFilters $filters): array
    {
        return array_map(function (array $entry) {
            $subject = $entry['subject'];
            $state = $entry['state'];
            $member = $subject instanceof Borrower ? $subject : null;

            return [
                'customer' => ReportNames::subject($member, $member === null ? $subject : null),
                'branch' => $member === null ? ReportNames::ORGANISATION_WIDE : ReportNames::branch($member->branch),
                'cdd_status' => CddState::STATUSES[$state['status']] ?? $state['status'],
                'identification' => CddState::ID_STATES[$state['id_state']] ?? $state['id_state'],
                'missing' => collect($state['completeness']['missing'])->pluck('words')->implode(', ') ?: 'None',
                'expired' => collect($state['completeness']['expired'])->pluck('words')->implode(', ') ?: 'None',
                'edd' => $state['edd_required'] ? ($state['edd_completed'] ? 'Required, completed' : 'Required') : 'Not required',
                'last_review_on' => $entry['profile']?->last_review_on?->format('M j, Y'),
                'next_review_on' => $entry['profile']?->next_review_on?->format('M j, Y'),
            ];
        }, $this->worklist->subjects($viewer, ['branch_id' => $filters->branchId]));
    }
}
