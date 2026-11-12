<?php

namespace App\Services\Savings;

use App\Models\SavingsApproval;
use App\Models\SavingsSetting;
use App\Models\User;
use App\Services\LoanWriteTransaction;
use App\Services\Savings\Approvals\ApprovalExecutor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Maker-checker for savings (design §4, §10.1).
 *
 * - **Tiers** (SavingsSetting): up to `tier1_limit` posts at once; up to
 *   `tier2_limit` needs one checker; above it two different checkers
 *   ({@see self::DUAL_CHECKERS}; `tier3_checkers` is held at 2 in v1). Limits are inclusive: ₱10,000.00 is tier 1,
 *   ₱10,000.01 tier 2, ₱50,000.01 tier 3.
 * - **Opening** a pending approval ({@see self::open()}), written under the
 *   caller's account lock so the available balance it reserves is exact. It
 *   expires at the end of the Manila business day.
 * - **Who may decide** ({@see self::assertCheckerAllowed()}): never the maker,
 *   and never the same checker twice (403).
 * - **Executors** by convention: `Approvals\{StudlyKind}Executor`, so each
 *   slice adds its own class and never edits this one.
 */
class ApprovalGate
{
    public const TIER_DIRECT = 1;

    public const TIER_SINGLE = 2;

    public const TIER_DUAL = 3;

    /**
     * Checkers above the second tier. Fixed at two in v1: an approval records
     * a first checker and a deciding one, so a third can't be recorded, and a
     * stored `tier3_checkers` above two is never trusted.
     */
    public const DUAL_CHECKERS = 2;

    /** The unique index that keeps one live approval per thing. */
    public const LIVE_KEY_INDEX = 'savings_approvals_live_key_unique';

    /** The tier of an amount, in centavos, against two inclusive limits. */
    public static function tier(int $centavos, int $tier1Limit, int $tier2Limit): int
    {
        return match (true) {
            $centavos <= $tier1Limit => self::TIER_DIRECT,
            $centavos <= $tier2Limit => self::TIER_SINGLE,
            default => self::TIER_DUAL,
        };
    }

    public function tierFor(int $centavos, ?SavingsSetting $settings = null): int
    {
        $settings ??= SavingsSetting::current();

        return self::tier($centavos, SavingsMath::centavos($settings->tier1_limit), SavingsMath::centavos($settings->tier2_limit));
    }

    /** Checkers an amount needs: 0 at tier 1, 1 at tier 2, two different checkers above. */
    public function requiredCheckers(int $centavos, ?SavingsSetting $settings = null): int
    {
        $settings ??= SavingsSetting::current();

        return match ($this->tierFor($centavos, $settings)) {
            self::TIER_DIRECT => 0,
            self::TIER_SINGLE => 1,
            default => self::DUAL_CHECKERS,
        };
    }

    /** Whether an amount must wait for a checker. */
    public function needsApproval(int $centavos, ?SavingsSetting $settings = null): bool
    {
        return $this->requiredCheckers($centavos, $settings) > 0;
    }

    /**
     * Open a pending approval. Always at least one checker: a kind that is
     * always approved (a reversal) opens one below tier 1 too. A second live
     * approval with the same `live_key` answers 409. `fee_amount` (centavos)
     * is an excess-withdrawal fee it reserves beside `$centavos`.
     *
     * @param  array{account_id?: int|null, time_deposit_id?: int|null, target_transaction_id?: int|null, payload?: array<string, mixed>|null, live_key?: string|null, fee_amount?: int}  $attributes
     */
    public function open(string $kind, int $centavos, array $attributes, int $requestedBy): SavingsApproval
    {
        if (! in_array($kind, SavingsApproval::KINDS, true)) {
            throw new LogicException("\"{$kind}\" is not a savings approval kind.");
        }

        try {
            return $this->create($kind, $centavos, $attributes, $requestedBy);
        } catch (UniqueConstraintViolationException $e) {
            if ($e->index !== self::LIVE_KEY_INDEX) {
                throw $e;
            }

            throw LoanWriteTransaction::conflict('A request for this is already waiting for approval. Approve, reject or cancel it first.');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function create(string $kind, int $centavos, array $attributes, int $requestedBy): SavingsApproval
    {
        return SavingsApproval::query()->create([
            'kind' => $kind,
            'account_id' => $attributes['account_id'] ?? null,
            'time_deposit_id' => $attributes['time_deposit_id'] ?? null,
            'target_transaction_id' => $attributes['target_transaction_id'] ?? null,
            'amount' => SavingsMath::pesos($centavos),
            'fee_amount' => SavingsMath::pesos((int) ($attributes['fee_amount'] ?? 0)),
            'payload' => $attributes['payload'] ?? null,
            'required_checkers' => max(1, $this->requiredCheckers($centavos)),
            'status' => 'pending',
            'requested_by' => $requestedBy,
            'expires_at' => now()->endOfDay(),
            'live_key' => $attributes['live_key'] ?? null,
        ]);
    }

    /**
     * Refuses (403) a checker who made the request, or who already approved
     * it once.
     *
     * @throws HttpException
     */
    public function assertCheckerAllowed(SavingsApproval $approval, User $checker): void
    {
        if ($approval->requested_by === $checker->id) {
            throw new HttpException(403, 'You made this request, so someone else has to approve it.');
        }

        if ($approval->first_checker_id === $checker->id) {
            throw new HttpException(403, 'You have already approved this request. A different checker has to give the second approval.');
        }
    }

    /** The executor class for a kind, by convention. */
    public static function executorClass(string $kind): string
    {
        return 'App\\Services\\Savings\\Approvals\\'.Str::studly($kind).'Executor';
    }

    /** Whether a kind has its executor yet (a kind can be registered before its slice adds one). */
    public static function hasExecutor(string $kind): bool
    {
        $class = self::executorClass($kind);

        return in_array($kind, SavingsApproval::KINDS, true) && class_exists($class) && is_subclass_of($class, ApprovalExecutor::class);
    }

    /**
     * The executor for a kind, from the container. A registered kind with no
     * executor yet (registered ahead of the slice that carries it out) is
     * refused in words (422 on `approval`), never a 500, and nothing changes.
     * A kind that isn't registered at all is a programming error.
     *
     * @throws ValidationException
     * @throws LogicException
     */
    public function executor(string $kind): ApprovalExecutor
    {
        if (! in_array($kind, SavingsApproval::KINDS, true)) {
            throw new LogicException("\"{$kind}\" is not a savings approval kind.");
        }

        if (! self::hasExecutor($kind)) {
            $words = str_replace('_', ' ', $kind);

            throw ValidationException::withMessages(['approval' => [
                "A {$words} request can't be carried out yet: this version has no way to complete it. Reject or cancel it instead.",
            ]]);
        }

        return app(self::executorClass($kind));
    }
}
