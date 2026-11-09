<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Borrower;
use App\Services\CreditScoring\Recalculator;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The nightly credit scoring run (00:45 Manila, routes/console/credit-scoring.php):
 * scores every member, in chunks of 200, then re-assesses each member's loan
 * applications still being decided that have been assessed or carry
 * application details.
 *
 * A history row is written only when a score changes (Recalculator). Each
 * member scored drops their change flag if nothing flagged them again since
 * the run began. Advisory only: no loan is ever written.
 *
 * `--dry-run` calculates and compares and writes nothing, listing what would
 * change. `--borrower=` scores one member.
 */
#[Signature('credit-scoring:recalculate
    {--dry-run : Calculate and list what would change, writing nothing}
    {--borrower= : Score only this member (borrower id)}')]
#[Description('Recalculate every member\'s Lendy Credit Score and their open loan assessments (advisory only)')]
class RecalculateCreditScores extends Command
{
    private const CHUNK = 200;

    public function handle(Recalculator $recalculator): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $only = $this->option('borrower');
        $startedAt = Carbon::now();

        if ($only !== null && (! ctype_digit((string) $only) || ! $recalculator->isScorable((int) $only))) {
            $this->error("No member with id {$only} to score.");

            return self::FAILURE;
        }

        $counts = ['members' => 0, 'changed' => 0, 'insufficient_data' => 0, 'assessments' => 0, 'assessments_changed' => 0];

        DB::table('borrowers')
            ->whereNotIn('status', Borrower::NON_MEMBER_STATUSES)
            ->when($only !== null, fn ($q) => $q->where('id', (int) $only))
            ->select('id')
            ->chunkById(self::CHUNK, function ($members) use ($recalculator, $dryRun, $startedAt, &$counts) {
                foreach ($members as $member) {
                    $borrowerId = (int) $member->id;
                    $outcome = $recalculator->scoreMember($borrowerId, 'Nightly review', $dryRun);
                    $counts['members']++;
                    $counts['changed'] += $outcome['changed'] ? 1 : 0;
                    $counts['insufficient_data'] += $outcome['status'] === 'insufficient_data' ? 1 : 0;

                    if ($outcome['changed'] && ($dryRun || $this->output->isVerbose())) {
                        $this->line(sprintf(
                            '  member %d: %s -> %s (%s, %s confidence)',
                            $borrowerId,
                            $outcome['previous_score'] ?? 'none',
                            $outcome['score'] ?? 'insufficient data',
                            $outcome['risk_level'] ?? 'no band',
                            $outcome['confidence'],
                        ));
                    }

                    foreach ($recalculator->openAssessedLoans($borrowerId) as $loan) {
                        $assessment = $recalculator->assessLoan($loan, 'Nightly review', $dryRun);
                        $counts['assessments']++;
                        $counts['assessments_changed'] += $assessment['changed'] ? 1 : 0;
                    }

                    if (! $dryRun) {
                        $recalculator->clearDirty($borrowerId, $startedAt);
                    }
                }
            });

        $this->info(sprintf(
            '%s %d member(s): %d %s, %d with insufficient data; %d loan assessment(s), %d %s.',
            $dryRun ? 'Dry run, nothing written. Checked' : 'Scored',
            $counts['members'],
            $counts['changed'],
            $dryRun ? 'would change' : 'changed',
            $counts['insufficient_data'],
            $counts['assessments'],
            $counts['assessments_changed'],
            $dryRun ? 'would change' : 'changed',
        ));

        return self::SUCCESS;
    }
}
