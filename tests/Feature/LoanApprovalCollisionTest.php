<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanApprovalStep;
use App\Models\LoanProduct;
use App\Models\User;
use App\Notifications\StaffNotification;
use App\Services\LoanApprovalChainService;
use App\Services\LoanService;
use Closure;
use Exception;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PDOException;
use ReflectionProperty;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Deciding a loan in review when another decision collides with it: the
 * direct approve and reject (PATCH /loans/{id}/approve and /reject) and the
 * approval chain's step sign-off and send-back. Each locks the loan row
 * first, reads the loan (and the step) again under that lock, and answers a
 * 409 the client can reload from when another decision got there first. Never
 * two outcomes, never a 500, and only the decision that commits notifies.
 */
class LoanApprovalCollisionTest extends TestCase
{
    use SetupLendyPH;

    /**
     * Opted out of the per-test transaction, as LoanWriteCollisionTest is:
     * inside one, the decision's own transaction is a savepoint that Laravel
     * does not roll back on a deadlock, and nothing would ever commit, so no
     * notification would be sent.
     */
    protected bool $wrapsEachTestInTransaction = false;

    private const DEADLOCK = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];

    private const LOCK_WAIT_TIMEOUT = ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'];

    private const CONFLICT = 'Another change to this loan was saved at the same time. Reload and try again.';

    private User $creator;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->creator = $this->staff('loan_processor');
        $this->officer = $this->staff('loan_officer');
    }

    // ── direct approve and reject ────────────────────────────────────────

    public function test_a_reject_that_read_the_loan_before_an_approve_committed_answers_409(): void
    {
        $loan = $this->loanInReview();
        $service = app(LoanService::class);

        $rejectRequestsCopy = Loan::findOrFail($loan->id);
        $service->approve(Loan::findOrFail($loan->id), $this->admin, 'ok');
        $afterApprove = $this->state();

        $this->assertConflict(fn () => $service->reject($rejectRequestsCopy, $this->admin, 'too late'));

        $this->assertSame($afterApprove, $this->state());
        $this->assertSame('approved', $loan->fresh()->status);
        $this->assertSame($this->ids($this->creator, $this->officer), $this->recipients(StaffNotification::LOAN_APPROVED, $loan));
        $this->assertSame([], $this->recipients(StaffNotification::LOAN_REJECTED, $loan));
    }

    public function test_an_approve_that_read_the_loan_before_a_reject_committed_answers_409(): void
    {
        $loan = $this->loanInReview();
        $service = app(LoanService::class);

        $approveRequestsCopy = Loan::findOrFail($loan->id);
        $service->reject(Loan::findOrFail($loan->id), $this->admin, 'no');
        $afterReject = $this->state();

        $this->assertConflict(fn () => $service->approve($approveRequestsCopy, $this->admin, 'too late'));

        $this->assertSame($afterReject, $this->state());
        $this->assertSame('rejected', $loan->fresh()->status);
        $this->assertSame($this->ids($this->creator, $this->officer), $this->recipients(StaffNotification::LOAN_REJECTED, $loan));
        $this->assertSame([], $this->recipients(StaffNotification::LOAN_APPROVED, $loan));
    }

    public function test_a_second_approve_from_the_same_reading_answers_409_and_notifies_once(): void
    {
        $loan = $this->loanInReview();
        $service = app(LoanService::class);

        $secondRequestsCopy = Loan::findOrFail($loan->id);
        $service->approve(Loan::findOrFail($loan->id), $this->admin, 'first');
        $afterFirst = $this->state();

        $this->assertConflict(fn () => $service->approve($secondRequestsCopy, $this->admin, 'second'));

        $this->assertSame($afterFirst, $this->state());
        $this->assertSame('first', $loan->fresh()->approval_remarks);
        $this->assertSame($this->ids($this->creator, $this->officer), $this->recipients(StaffNotification::LOAN_APPROVED, $loan));
    }

    public function test_a_second_reject_from_the_same_reading_answers_409_and_notifies_once(): void
    {
        $loan = $this->loanInReview();
        $service = app(LoanService::class);

        $secondRequestsCopy = Loan::findOrFail($loan->id);
        $service->reject(Loan::findOrFail($loan->id), $this->admin, 'first');
        $afterFirst = $this->state();

        $this->assertConflict(fn () => $service->reject($secondRequestsCopy, $this->admin, 'second'));

        $this->assertSame($afterFirst, $this->state());
        $this->assertSame('first', $loan->fresh()->rejection_remarks);
        $this->assertSame($this->ids($this->creator, $this->officer), $this->recipients(StaffNotification::LOAN_REJECTED, $loan));
    }

    public function test_an_approve_locks_the_loan_first(): void
    {
        $loan = $this->loanInReview();

        $reads = $this->lockingReads(fn () => $this->patchJson("/api/loans/{$loan->id}/approve", ['approval_remarks' => 'ok'])->assertOk());

        $this->assertStringContainsString('from `loans`', $reads[0]['sql']);
        $this->assertSame([$loan->id], $this->lockedIds($reads[0]));
    }

    public function test_a_reject_locks_the_loan_first(): void
    {
        $loan = $this->loanInReview();

        $reads = $this->lockingReads(fn () => $this->patchJson("/api/loans/{$loan->id}/reject", ['approval_remarks' => 'no'])->assertOk());

        $this->assertStringContainsString('from `loans`', $reads[0]['sql']);
        $this->assertSame([$loan->id], $this->lockedIds($reads[0]));
    }

    public function test_an_approve_that_deadlocks_answers_409_writes_nothing_and_notifies_nobody(): void
    {
        $loan = $this->loanInReview();
        $before = $this->state();
        $this->failOn(fn (string $sql, array $bindings): bool => str_starts_with($sql, 'update `loan_approval_steps`') && in_array('approved', $bindings, true), self::DEADLOCK);

        $this->patchJson("/api/loans/{$loan->id}/approve", ['approval_remarks' => 'ok'])->assertStatus(409)->assertExactJson(['message' => self::CONFLICT]);

        $this->assertSame($before, $this->state());
    }

    public function test_a_reject_whose_lock_wait_times_out_answers_409_and_writes_nothing(): void
    {
        $loan = $this->loanInReview();
        $before = $this->state();
        $this->failOn(fn (string $sql, array $bindings): bool => str_starts_with($sql, 'update `loans`') && in_array('rejected', $bindings, true), self::LOCK_WAIT_TIMEOUT);

        $this->patchJson("/api/loans/{$loan->id}/reject", ['approval_remarks' => 'no'])->assertStatus(409)->assertExactJson(['message' => self::CONFLICT]);

        $this->assertSame($before, $this->state());
    }

    public function test_approving_or_rejecting_a_loan_that_was_not_in_review_when_read_is_still_a_422(): void
    {
        $loan = $this->loanInReview();
        app(LoanService::class)->approve(Loan::findOrFail($loan->id), $this->admin, 'ok');
        $before = $this->state();

        $this->patchJson("/api/loans/{$loan->id}/approve", ['approval_remarks' => 'again'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->patchJson("/api/loans/{$loan->id}/reject", ['approval_remarks' => 'no'])->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertSame($before, $this->state());
    }

    public function test_approve_and_reject_still_record_the_decision_and_notify(): void
    {
        $approved = $this->loanInReview();
        $rejected = $this->loanInReview();

        $this->patchJson("/api/loans/{$approved->id}/approve", ['approval_remarks' => 'ok'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->patchJson("/api/loans/{$rejected->id}/reject", ['approval_remarks' => 'no'])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertSame(['approved', $this->admin->id, 'ok'], [$approved->fresh()->status, $approved->fresh()->approved_by, $approved->fresh()->approval_remarks]);
        $this->assertSame(['rejected', $this->admin->id, 'no'], [$rejected->fresh()->status, $rejected->fresh()->rejected_by, $rejected->fresh()->rejection_remarks]);
        $this->assertSame($this->ids($this->creator, $this->officer), $this->recipients(StaffNotification::LOAN_APPROVED, $approved));
        $this->assertSame($this->ids($this->creator, $this->officer), $this->recipients(StaffNotification::LOAN_REJECTED, $rejected));
    }

    // ── the approval chain ───────────────────────────────────────────────

    public function test_a_second_sign_off_of_the_same_step_answers_409_and_advances_the_chain_once(): void
    {
        $loan = $this->loanInReview();
        $chair = $this->staff('bod1');
        $chain = app(LoanApprovalChainService::class);
        $step = $this->pendingStep($loan);
        $firstManager = $this->staff('manager');
        $secondManager = $this->staff('manager');

        // Two managers both read the manager step as pending; the first signs.
        $secondLoan = Loan::findOrFail($loan->id);
        $secondStep = LoanApprovalStep::findOrFail($step->id);
        $chain->approve(Loan::findOrFail($loan->id), LoanApprovalStep::findOrFail($step->id), $firstManager, 'first');
        $afterFirst = $this->state();

        $this->assertConflict(fn () => $chain->approve($secondLoan, $secondStep, $secondManager, 'second'));

        $this->assertSame($afterFirst, $this->state());
        $this->assertSame('first', $step->fresh()->remarks);
        $this->assertSame([$chair->id], $this->recipients(StaffNotification::APPROVAL_NEEDED, $loan, round: 1, stepOrder: 2));
        $this->assertSame(1, AuditLog::where('action', 'loan_step_approved')->where('auditable_id', $loan->id)->count());
    }

    public function test_a_sign_off_on_a_loan_rejected_since_it_was_read_answers_409(): void
    {
        $loan = $this->loanInReview();
        $step = $this->pendingStep($loan);
        $manager = $this->staff('manager');

        $stepRequestsLoan = Loan::findOrFail($loan->id);
        $stepRequestsStep = LoanApprovalStep::findOrFail($step->id);
        app(LoanService::class)->reject(Loan::findOrFail($loan->id), $this->admin, 'no');
        $afterReject = $this->state();

        $this->assertConflict(fn () => app(LoanApprovalChainService::class)->approve($stepRequestsLoan, $stepRequestsStep, $manager));

        $this->assertSame($afterReject, $this->state());
        $this->assertSame([], $this->recipients(StaffNotification::APPROVAL_NEEDED, $loan, round: 1, stepOrder: 2));
    }

    public function test_a_last_sign_off_racing_a_direct_reject_approves_once_and_notifies_once(): void
    {
        $loan = $this->loanInReview();
        $chain = app(LoanApprovalChainService::class);
        $chain->approve(Loan::findOrFail($loan->id), $this->pendingStep($loan), $this->staff('manager'));
        $lastStep = $this->pendingStep($loan);

        $rejectRequestsCopy = Loan::findOrFail($loan->id);
        $chain->approve(Loan::findOrFail($loan->id), LoanApprovalStep::findOrFail($lastStep->id), $this->staff('bod1'), 'Fine');
        $afterApprove = $this->state();

        $this->assertConflict(fn () => app(LoanService::class)->reject($rejectRequestsCopy, $this->admin, 'too late'));

        $this->assertSame($afterApprove, $this->state());
        $this->assertSame('approved', $loan->fresh()->status);
        $this->assertSame($this->ids($this->creator, $this->officer), $this->recipients(StaffNotification::LOAN_APPROVED, $loan));
        $this->assertSame([], $this->recipients(StaffNotification::LOAN_REJECTED, $loan));
    }

    public function test_a_send_back_of_a_step_signed_off_since_it_was_read_answers_409(): void
    {
        $loan = $this->loanInReview();
        $chain = app(LoanApprovalChainService::class);
        $step = $this->pendingStep($loan);
        $manager = $this->staff('manager');

        $sendBacksLoan = Loan::findOrFail($loan->id);
        $sendBacksStep = LoanApprovalStep::findOrFail($step->id);
        $chain->approve(Loan::findOrFail($loan->id), LoanApprovalStep::findOrFail($step->id), $manager);
        $afterApprove = $this->state();

        $this->assertConflict(fn () => $chain->sendBack($sendBacksLoan, $sendBacksStep, 0, $manager, 'Fix the income'));

        $this->assertSame($afterApprove, $this->state());
        $this->assertSame(1, (int) $loan->approvalSteps()->max('round'));
    }

    public function test_a_step_sign_off_locks_the_loan_then_the_step(): void
    {
        $loan = $this->loanInReview();
        $step = $this->pendingStep($loan);

        $reads = $this->lockingReads(fn () => $this->patchJson("/api/loans/{$loan->id}/approval-steps/{$step->id}/approve", ['remarks' => 'ok'])->assertOk());

        $this->assertStringContainsString('from `loans`', $reads[0]['sql']);
        $this->assertSame([$loan->id], $this->lockedIds($reads[0]));

        $this->assertStringContainsString('from `loan_approval_steps`', $reads[1]['sql']);
        $this->assertSame([$step->id], $this->lockedIds($reads[1]));
    }

    public function test_a_step_sign_off_that_deadlocks_answers_409_and_writes_nothing(): void
    {
        $loan = $this->loanInReview();
        $step = $this->pendingStep($loan);
        $before = $this->state();
        $this->failOn(fn (string $sql, array $bindings): bool => str_starts_with($sql, 'update `loan_approval_steps`') && in_array('approved', $bindings, true), self::DEADLOCK);

        $this->patchJson("/api/loans/{$loan->id}/approval-steps/{$step->id}/approve", ['remarks' => 'ok'])->assertStatus(409)->assertExactJson(['message' => self::CONFLICT]);

        $this->assertSame($before, $this->state());
    }

    public function test_a_send_back_whose_lock_wait_times_out_answers_409_and_writes_nothing(): void
    {
        $loan = $this->loanInReview();
        $step = $this->pendingStep($loan);
        $before = $this->state();
        $this->failOn(fn (string $sql, array $bindings): bool => str_starts_with($sql, 'update `loan_approval_steps`') && in_array(LoanApprovalStep::STATUS_SENT_BACK, $bindings, true), self::LOCK_WAIT_TIMEOUT);

        $this->patchJson("/api/loans/{$loan->id}/approval-steps/{$step->id}/send-back", ['target_step_order' => 0, 'remarks' => 'Fix the income'])
            ->assertStatus(409)->assertExactJson(['message' => self::CONFLICT]);

        $this->assertSame($before, $this->state());
    }

    public function test_signing_off_a_step_that_was_not_pending_when_read_is_still_a_422(): void
    {
        $loan = $this->loanInReview();
        $step = $this->pendingStep($loan);
        app(LoanApprovalChainService::class)->approve(Loan::findOrFail($loan->id), LoanApprovalStep::findOrFail($step->id), $this->staff('manager'));
        $before = $this->state();

        $this->patchJson("/api/loans/{$loan->id}/approval-steps/{$step->id}/approve", ['remarks' => 'again'])->assertStatus(422)->assertJsonValidationErrors('step');
        $this->patchJson("/api/loans/{$loan->id}/approval-steps/{$step->id}/send-back", ['target_step_order' => 0, 'remarks' => 'Fix'])->assertStatus(422)->assertJsonValidationErrors('step');

        $this->assertSame($before, $this->state());
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** An active staff member in the main branch holding `$role`. */
    private function staff(string $role): User
    {
        return tap(User::factory()->inBranches($this->branch)->create(), fn (User $user) => $user->assignRole($role));
    }

    /**
     * A loan submitted for review by its creator, with an officer, on the
     * default four-step chain (submit, manager, bod1, release): the manager's
     * step is pending.
     */
    private function loanInReview(): Loan
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
        ]);

        $loan = app(LoanService::class)->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
            'account_officer_id' => $this->officer->id,
        ], $this->creator);

        app(LoanService::class)->submitForReview($loan, $this->creator);

        return $loan->fresh();
    }

    private function pendingStep(Loan $loan): LoanApprovalStep
    {
        return $loan->approvalSteps()
            ->where('status', LoanApprovalStep::STATUS_PENDING)
            ->orderByDesc('round')
            ->firstOrFail();
    }

    private function assertConflict(Closure $act): void
    {
        try {
            $act();
            $this->fail('The second decision was not refused.');
        } catch (HttpResponseException $e) {
            $this->assertSame(409, $e->getResponse()->getStatusCode());
            $this->assertSame(['message' => self::CONFLICT], $e->getResponse()->getData(true));
        }
    }

    /**
     * The users told about `$loan` with `$type`, sorted by id, once per row:
     * a user told twice appears twice.
     *
     * @return list<int>
     */
    private function recipients(string $type, Loan $loan, ?int $round = null, ?int $stepOrder = null): array
    {
        return DatabaseNotification::query()
            ->where('type', $type)
            ->where('notifiable_type', User::class)
            ->get()
            ->filter(fn (DatabaseNotification $n) => ($n->data['data']['loan_id'] ?? null) === $loan->id)
            ->filter(fn (DatabaseNotification $n) => $round === null || ($n->data['data']['round'] ?? null) === $round)
            ->filter(fn (DatabaseNotification $n) => $stepOrder === null || ($n->data['data']['step_order'] ?? null) === $stepOrder)
            ->pluck('notifiable_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function ids(User ...$users): array
    {
        return collect($users)->pluck('id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
    }

    /**
     * Refuse the first statement `$matches` accepts with the driver error
     * MySQL raises for `$errorInfo`, built the way PDO builds it.
     *
     * @param  Closure(string, array<int, mixed>): bool  $matches
     * @param  array{0: string, 1: int, 2: string}  $errorInfo
     */
    private function failOn(Closure $matches, array $errorInfo): void
    {
        $fired = false;

        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$fired, $matches, $errorInfo): void {
            if ($fired || ! $matches($query, $bindings)) {
                return;
            }

            $fired = true;

            [$sqlState, $driverCode, $driverMessage] = $errorInfo;
            $pdo = new PDOException("SQLSTATE[{$sqlState}]: General error: {$driverCode} {$driverMessage}");
            $pdo->errorInfo = $errorInfo;
            (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, $sqlState);

            throw new QueryException(DB::connection()->getName(), $query, $bindings, $pdo);
        });
    }

    /**
     * Every locking read issued inside the transaction `$act` opens, in order.
     *
     * @return list<array{sql: string, bindings: array<int, mixed>}>
     */
    private function lockingReads(callable $act): array
    {
        $recording = false;
        $reads = [];

        Event::listen(TransactionBeginning::class, function () use (&$recording): void {
            $recording = true;
        });

        DB::listen(function (QueryExecuted $query) use (&$recording, &$reads): void {
            $sql = strtolower($query->sql);

            if ($recording && str_contains($sql, 'for update')) {
                $reads[] = ['sql' => $sql, 'bindings' => $query->bindings];
            }
        });

        $act();

        return $reads;
    }

    /**
     * The ids a `whereKey()` lock names, whether bound or inlined.
     *
     * @param  array{sql: string, bindings: array<int, mixed>}  $read
     * @return list<int>
     */
    private function lockedIds(array $read): array
    {
        $bindings = $read['bindings'];
        $sql = preg_replace_callback('/\?/', function () use (&$bindings): string {
            return (string) array_shift($bindings);
        }, $read['sql']);

        preg_match('/`id` (?:= (\d+)|in \(([\d, ]+)\))/', $sql, $match);

        return array_map('intval', explode(',', $match[1] !== '' ? $match[1] : $match[2]));
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $rows = fn (string $table): array => DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();

        return [
            'loans' => $rows('loans'),
            'steps' => $rows('loan_approval_steps'),
            'notifications' => DB::table('notifications')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'audit' => AuditLog::count(),
        ];
    }
}
