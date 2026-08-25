<?php

namespace App\Console\Commands;

use App\Models\Borrower;
use App\Models\BorrowerSubmissionToken;
use App\Services\AuditLogService;
use App\Services\BorrowerPurgeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

#[Signature('registrations:prune
    {--days=14 : Days of inactivity before an incomplete pending submission counts as abandoned}
    {--token-days=1 : Days past expires_at before a submission token is dropped}
    {--dry-run : List what would be deleted without touching anything}')]
#[Description('Delete abandoned anonymous borrower registrations and expired submission tokens')]
class PruneAbandonedRegistrations extends Command
{
    public function handle(BorrowerPurgeService $purge): int
    {
        $days = (int) $this->option('days');
        $tokenDays = (int) $this->option('token-days');
        $dryRun = (bool) $this->option('dry-run');

        $cutoff = now()->subDays($days);

        /*
         * Only submissions that can never be completed.
         *
         * `approveRegistration()` hard-refuses a borrower with no valid ID, and
         * the submission token that would let the applicant upload one lives for
         * 15 minutes. So a pending borrower with no valid_id document, past the
         * window, cannot be finished by the applicant nor approved by an
         * operator — it is provably dead.
         *
         * Pending borrowers that DO have a valid ID are the review queue, not
         * abandonment: binhs-coop production alone holds 30 pending applications
         * carrying 44 documents, the oldest three months old. A rule based on
         * age alone would delete real applications and the KYC files behind them.
         */
        $candidates = Borrower::query()
            ->where('status', 'pending')
            ->whereNull('approved_at')
            ->whereNull('rejected_at')
            ->whereDoesntHave('documents', fn ($q) => $q->where('type', 'valid_id'))
            ->where('updated_at', '<', $cutoff)
            /*
             * A valid-ID upload writes to `documents` via morphMany with no
             * $touches, so it never bumps the borrower's updated_at. Any
             * document at all therefore counts as activity in its own right,
             * or an applicant who uploaded something we do not gate on would
             * look idle.
             */
            ->whereDoesntHave('documents', fn ($q) => $q->where('created_at', '>=', $cutoff))
            /*
             * Never touch a borrower with financial history, whatever `status`
             * says. "Pending" plus a loan is not a contradiction in this data
             * model — it is a real state, and the portfolio database holds nine
             * such loans across four pending borrowers, 30% of its loan book.
             * binhs-coop happens to have none, which is the only reason a
             * status-and-documents rule looked safe when it was first checked.
             *
             * `loans.borrower_id` is restrictOnDelete, so these would throw
             * rather than cascade — but purge() has already deleted their photo
             * and upload directories by then, and the filesystem does not roll
             * back with the transaction. Excluding them here is what actually
             * prevents that; the ordering fix in BorrowerPurgeService is the
             * belt to this pair of braces.
             *
             * NOT share_capital_pledges: Borrower::booted() creates one for
             * every borrower, so gating on it would prune nothing, ever.
             */
            ->whereDoesntHave('loans')
            ->whereDoesntHave('shareCapitalLedger')
            // No gcashTransactions() relation exists on Borrower.
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('gcash_transactions')
                ->whereColumn('gcash_transactions.borrower_id', 'borrowers.id'))
            ->get();

        $expiredTokens = BorrowerSubmissionToken::query()
            ->where('expires_at', '<', now()->subDays($tokenDays));

        $tokenCount = $expiredTokens->count();

        foreach ($candidates as $borrower) {
            $this->line(($dryRun ? '  would prune ' : '  pruning ').
                "{$borrower->borrower_code} (last activity {$borrower->updated_at->toDateString()})");
        }

        if ($dryRun) {
            $this->info("Would prune {$candidates->count()} abandoned registration(s) and {$tokenCount} expired token(s).");

            return self::SUCCESS;
        }

        $pruned = 0;
        $failed = 0;

        foreach ($candidates as $borrower) {
            try {
                /*
                 * One transaction around the audit row AND the purge.
                 *
                 * The log has to happen before the delete so auditable_id still
                 * resolves — but purge() opens its own transaction, so a write
                 * left outside it survives the rollback when the purge throws.
                 * That wrote "pruned" rows for borrowers that still existed:
                 * four of them on the portfolio box, for the loan-holders the
                 * guard above now excludes. Wrapping both means the claim and
                 * the act commit together or not at all. purge()'s transaction
                 * nests as a savepoint.
                 *
                 * It carries only the borrower code. Purging with audit=false
                 * suppresses the Auditable trait, which would otherwise write
                 * the borrower's full attributes — name, birthdate, address,
                 * contact number, income — into audit_logs.old_values and keep
                 * them forever. A retention prune that preserves the personal
                 * data it exists to remove is not a retention prune.
                 */
                DB::transaction(function () use ($purge, $borrower, $days) {
                    AuditLogService::log(
                        action: 'pruned',
                        auditable: $borrower,
                        newValues: ['borrower_code' => $borrower->borrower_code],
                        description: "Abandoned anonymous registration pruned after {$days} days without a valid ID",
                    );

                    $purge->purge($borrower, audit: false);
                });

                $pruned++;
            } catch (\Throwable $e) {
                $this->error("  failed {$borrower->borrower_code}: {$e->getMessage()}");

                /*
                 * The console output goes nowhere: the scheduler runs from root
                 * cron as `schedule:run >> /dev/null 2>&1`, so a failing prune
                 * is invisible and the non-zero exit code is discarded too.
                 * Laravel's log is the only channel anyone can actually read
                 * after the fact.
                 */
                Log::warning('registrations:prune failed to purge a borrower', [
                    'borrower_code' => $borrower->borrower_code,
                    'exception' => $e->getMessage(),
                ]);

                $failed++;
            }
        }

        $tokensDeleted = $expiredTokens->delete();

        $this->info("Pruned {$pruned} registration(s); {$failed} failed; {$tokensDeleted} expired token(s) dropped.");

        // The private disk creates directories 0700 for the owning user. The
        // scheduler runs as root here, which can unlink inside them — but if
        // this ever moves to www-data, a mismatch shows up as files surviving a
        // "successful" prune rather than as an error.
        if ($pruned > 0 && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->newLine();
            $this->warn('Ran as root; verify no root-owned leftovers under storage/app/private.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
