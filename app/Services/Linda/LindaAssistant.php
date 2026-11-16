<?php

namespace App\Services\Linda;

use App\Models\LindaQuestion;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\Diagnostics\ErrorDigest;
use App\Services\Linda\Model\LindaModelFactory;
use App\Services\Linda\Model\LindaModelRequest;
use App\Services\Linda\Model\LindaModelUnavailable;
use App\Services\Linda\Model\LindaPrices;
use App\Services\Linda\Model\LindaUnderstanding;
use App\Services\Linda\Model\LindaUsage;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Answers one Linda question (Linda v1 plan, "How a question is answered").
 *
 * 1. The matcher runs first; what it understands never leaves the server.
 *    A question it misses that names a record or asks to look one up ends at
 *    the local guard (LindaGuard) with the "not yet" reply.
 * 2. With no match and the none driver, the reply lists what Linda can
 *    answer. Otherwise, below the monthly cap, the model reads the question
 *    (and up to 5 earlier questions with their intents, never an answer) and
 *    returns only the intent, the period words, a branch name and whether it
 *    follows on.
 * 3. The answerer writes the reply from the reports' own figures.
 * 4. One `linda_questions` row is written, with no text.
 *
 * A follow-up keeps the period and branch of the question it follows
 * (LindaFollowUp, the rule `linda:evaluate` scores).
 *
 * Errors answer through LindaHttpError: `monthly_limit` at the cap,
 * `ai_unavailable` when the model can't be asked and `data_unavailable` when
 * the figures can't be read. No question, answer or exception text is ever
 * logged; a failed answer logs its exception class only (ErrorDigest).
 */
final class LindaAssistant
{
    public const OUTCOME_LIMITED = 'limited';

    public const OUTCOME_REFUSED = 'refused';

    public const OUTCOME_ERROR = 'error';

    /** Any other failure of the model call (security review S4). */
    public const REASON_INTERNAL = 'internal';

    public function __construct(
        private readonly LindaAnswerer $answerer,
        private readonly LindaSettings $settings,
        private readonly LindaBudget $budget,
        private readonly BranchAccess $branchAccess,
    ) {}

    /**
     * @param  array<int, array{role?: mixed, content?: mixed, intent?: mixed}>  $history  the validated history entries
     *
     * @throws LindaHttpError monthly_limit, ai_unavailable or data_unavailable
     */
    public function ask(User $user, #[\SensitiveParameter] string $question, #[\SensitiveParameter] array $history): LindaReply
    {
        $started = hrtime(true);
        $askedAt = now();
        $history = LindaHistory::from($history, (int) config('linda.limits.history_max'));
        $log = new LindaQuestion([
            'user_id' => $user->getKey(),
            'asked_at' => $askedAt,
            'source' => LindaQuestion::SOURCE_NONE,
            'branch_scope' => $this->branchAccess->for($user),
        ]);

        $match = LindaMatcher::match($question, $history->previousIntent);

        if ($match !== null) {
            $log->source = LindaQuestion::SOURCE_MATCHER;
            [$period, $branchName] = LindaFollowUp::carry($match->intent, $match->period, $match->branchName, $match->followUp, $history->earlier, $user);

            return $this->answered($log, $started, $user, $match->intent, $period, $this->asTyped($branchName, $question, $history), $question);
        }

        $lookup = LindaGuard::check($question);

        if ($lookup !== null) {
            $log->fill(['source' => LindaQuestion::SOURCE_GUARD, 'intent' => $lookup->value]);

            return $this->finish($log, $started, $this->answerer->notYet($lookup, $user));
        }

        if (! in_array(config('linda.driver'), ['fake', 'anthropic'], true)) {
            return $this->finish($log, $started, $this->answerer->unmatched());
        }

        $model = $this->settings->model();
        $understanding = $this->understand($log, $started, $question, $history, $model);

        if ($understanding === null) {
            $log->fill(['source' => LindaQuestion::SOURCE_NONE, 'model' => null]);

            return $this->finish($log, $started, $this->answerer->unmatched());
        }

        return $this->fromUnderstanding($log, $started, $user, $question, $understanding, $history);
    }

    /**
     * Ask the model, below the cap, with its prices checked first so a broken
     * price table, rate or cap sends nothing (`no_prices`). A failed call is
     * logged with its reason code and the cost it may have run up: the usage
     * of an answer that couldn't be used, or, for a timeout or an internal
     * failure once the call may have been sent (either may still be billed),
     * the worst-case estimate on the requested model.
     *
     * Earlier questions the local guard flags never go to the model: a lookup
     * it kept local stays local on every later question, its intent with it.
     *
     * @throws LindaHttpError monthly_limit or ai_unavailable
     */
    private function understand(LindaQuestion $log, int $started, #[\SensitiveParameter] string $question, LindaHistory $history, string $model): ?LindaUnderstanding
    {
        $sent = false;

        try {
            if ($this->budget->reached()) {
                $log->fill(['outcome' => self::OUTCOME_LIMITED, 'error_code' => LindaHttpError::MONTHLY_LIMIT]);
                $this->write($log, $started);

                throw LindaHttpError::monthlyLimit();
            }

            LindaPrices::worstCaseEstimate($model);
            $log->fill(['source' => LindaQuestion::SOURCE_MODEL, 'model' => $model]);
            $request = new LindaModelRequest($question, LindaGuard::withoutLookups($history->earlier), $model);
            $driver = LindaModelFactory::make((string) config('linda.driver'));
            $sent = true;
            $understanding = $driver->understand($request);

            if ($understanding !== null) {
                $this->recordUsage($log, $understanding->usage);
            }

            return $understanding;
        } catch (LindaModelUnavailable $e) {
            $this->recordFailure($log, $e, $model);
        } catch (LindaHttpError $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('Linda model call failed.', ['reason' => self::REASON_INTERNAL] + ErrorDigest::context($e));
            $log->fill(['source' => LindaQuestion::SOURCE_MODEL, 'model' => $model, 'outcome' => self::OUTCOME_ERROR, 'error_code' => self::REASON_INTERNAL]);

            if ($sent) {
                $this->bookWorstCase($log, $model);
            }
        }

        $this->write($log, $started);

        throw LindaHttpError::aiUnavailable();
    }

    private function recordFailure(LindaQuestion $log, LindaModelUnavailable $e, string $model): void
    {
        $log->fill(['outcome' => self::OUTCOME_ERROR, 'error_code' => $e->reason]);

        if ($e->reason === 'no_prices') {
            $log->fill(['source' => LindaQuestion::SOURCE_MODEL, 'model' => $model]);

            return;
        }

        try {
            if ($e->usage !== null) {
                $this->recordUsage($log, $e->usage);
            } elseif (in_array($e->reason, ['timeout', 'bad_output'], true)) {
                // A timeout, or an answer that arrived but couldn't be read, may
                // still have been billed.
                $log->cost = LindaPrices::worstCaseEstimate($model);
            }
        } catch (LindaModelUnavailable) {
            $log->error_code = 'no_prices';
        }
    }

    /** The worst-case estimate on the requested model, for a call that may have been billed. */
    private function bookWorstCase(LindaQuestion $log, string $model): void
    {
        try {
            $log->cost = LindaPrices::worstCaseEstimate($model);
        } catch (LindaModelUnavailable) {
            $log->error_code = 'no_prices';
        }
    }

    private function fromUnderstanding(LindaQuestion $log, int $started, User $user, #[\SensitiveParameter] string $question, LindaUnderstanding $understanding, LindaHistory $history): LindaReply
    {
        $intent = $understanding->intent;

        if ($understanding->refused) {
            $reply = $this->answerer->unsupported();

            return $this->finish($log, $started, $reply, self::OUTCOME_REFUSED);
        }

        if ($intent === LindaIntent::UnsupportedQuestion) {
            return $this->finish($log, $started, $this->answerer->unsupported());
        }

        // Overdue and active loans are as of today: the model's period words
        // are ignored, never refused or called unclear.
        $period = null;

        if ($intent->takesPeriod()) {
            if ($understanding->periodRefused !== null) {
                return $this->finish($log, $started, $this->answerer->periodRefused($understanding->periodRefused, $intent));
            }

            if ($understanding->periodUnclear) {
                return $this->finish($log, $started, $this->answerer->unclearPeriod($intent));
            }

            $period = $understanding->period;
        }

        [$period, $branchName] = LindaFollowUp::carry($intent, $period, $understanding->branchName, $understanding->followUp, $history->earlier, $user);

        return $this->answered($log, $started, $user, $intent, $period, $this->asTyped($branchName, $question, $history), $question);
    }

    /** A branch name as staff typed it, from this question or an earlier one, for the reply. */
    private function asTyped(?string $branchName, #[\SensitiveParameter] string $question, LindaHistory $history): ?string
    {
        return $branchName === null
            ? null
            : LindaText::asTyped($branchName, $question, ...array_reverse(array_column($history->earlier, 'question')));
    }

    /**
     * @throws LindaHttpError data_unavailable
     */
    private function answered(LindaQuestion $log, int $started, User $user, LindaIntent $intent, ?LindaPeriod $period, ?string $branchName, #[\SensitiveParameter] string $question): LindaReply
    {
        $log->fill([
            'intent' => $intent->value,
            'period_from' => $period?->from->toDateString(),
            'period_to' => $period?->to->toDateString(),
        ]);

        try {
            $reply = $this->answerer->answer($user, $intent, $period, $branchName, $question);

            if ($branchName !== null && $reply->outcome === 'answered') {
                $log->branch_id = LindaScope::resolve($user, $branchName)->branchId;
            }
        } catch (Throwable $e) {
            Log::warning('Linda answer failed.', ['intent' => $intent->value] + ErrorDigest::context($e));
            $log->fill(['outcome' => self::OUTCOME_ERROR, 'error_code' => LindaHttpError::DATA_UNAVAILABLE]);
            $this->write($log, $started);

            throw LindaHttpError::dataUnavailable();
        }

        return $this->finish($log, $started, $reply);
    }

    private function finish(LindaQuestion $log, int $started, LindaReply $reply, ?string $outcome = null): LindaReply
    {
        $log->fill([
            'intent' => $log->intent ?? $reply->intent,
            'answer_function' => $reply->answerFunction,
            'outcome' => $outcome ?? $reply->outcome,
            'member_ids' => $reply->memberIds === [] ? null : array_values(array_map('intval', $reply->memberIds)),
            'loan_ids' => $reply->loanIds === [] ? null : array_values(array_map('intval', $reply->loanIds)),
        ]);
        $this->write($log, $started);

        return $reply;
    }

    private function recordUsage(LindaQuestion $log, LindaUsage $usage): void
    {
        $log->fill([
            'model' => $usage->model,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cache_read_tokens' => $usage->cacheReadTokens,
            'cache_write_tokens' => $usage->cacheWriteTokens,
            'cost' => (string) BigDecimal::of(LindaPrices::cost($usage))->toScale(6, RoundingMode::HalfUp),
        ]);
    }

    private function write(LindaQuestion $log, int $started): void
    {
        $log->duration_ms = (int) intdiv(hrtime(true) - $started, 1_000_000);
        $log->save();
    }
}
