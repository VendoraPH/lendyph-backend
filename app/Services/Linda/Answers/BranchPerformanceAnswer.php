<?php

namespace App\Services\Linda\Answers;

use App\Services\Linda\LindaIntent;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Which branch collected the most?": the Performance report's branch rows
 * (`by_branch`, every one of the user's branches, ₱0.00 where nothing
 * happened), ranked, with Past Due by the grace rule per branch.
 */
final class BranchPerformanceAnswer extends PerformanceAnswer
{
    public function intent(): LindaIntent
    {
        return LindaIntent::BranchPerformance;
    }

    protected function dimension(): string
    {
        return 'branch';
    }

    protected function plural(): string
    {
        return 'Branches';
    }

    protected function narrowToRow(Builder $loans, array $row): void
    {
        $loans->where('loans.branch_id', (int) $row['branch_id']);
    }

    protected function rowName(array $row): string
    {
        return (string) $row['branch_name'];
    }
}
