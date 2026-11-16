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
 * 2. With no match and the none driver, the reply lists what Linda can
 *    answer. Otherwise, below the monthly cap, the model reads the question
 *    (and up to 5 earlier questions with their intents, never an answer) and
 *    returns only the intent, the period words, a branch name and whether it
 *    follows on.
 * 3. The answerer writes the reply from the reports' own figures.
 * 4. One `linda_questions` row is written, with no text.
 *
 * A follow-up that names no branch keeps the earlier question's, and one that
 * keeps the earlier subject keeps its period too; a question with no period
 * whose intent defaults to this month gets this month (the rule
 * `linda:evaluate` scores).
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
    public function ask(User $user, string $question, array $history): LindaReply
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
            [$period, $branchName] = $this->carried($match->intent, $match->period, $match->branchName, $match->followUp, $history);

            return $this->answered($log, $started, $user, $match->intent, $period, $branchName);
        }

        if (! in_array(config('linda.driver'), ['fake', 'anthropic'], true)) {
            return $this->finish($log, $started, $this->answerer->unmatched());
        }

        if ($this->budget->reached()) {
            $log->fill(['outcome' => self::OUTCOME_LIMITED, 'error_code' => LindaHttpError::MONTHLY_LIMIT]);
            $this->write($log, $started);

            throw LindaHttpError::monthlyLimit();
        }

        $model = $this->settings->model();
        $log->fill(['source' => LindaQuestion::SOURCE_MODEL, 'model' => $model]);

        try {
            $understanding = LindaModelFactory::make((string) config('linda.driver'))
                ->understand(new LindaModelRequest($question, $history->earlier, $model));

            if ($understanding !== null) {
                $this->recordUsage($log, $understanding->usage);
            }
        } catch (LindaModelUnavailable $e) {
            $log->fill(['outcome' => self::OUTCOME_ERROR, 'error_code' => $e->reason]);
            $this->write($log, $started);

            throw LindaHttpError::aiUnavailable();
        }

        if ($understanding === null) {
            $log->fill(['source' => LindaQuestion::SOURCE_NONE, 'model' => null]);

            return $this->finish($log, $started, $this->answerer->unmatched());
        }

        return $this->fromUnderstanding($log, $started, $user, $understanding, $history);
    }

    private function fromUnderstanding(LindaQuestion $log, int $started, User $user, LindaUnderstanding $understanding, LindaHistory $history): LindaReply
    {
        $intent = $understanding->intent;

        if ($understanding->refused) {
            $reply = $this->answerer->unsupported();

            return $this->finish($log, $started, $reply, self::OUTCOME_REFUSED);
        }

        if ($understanding->periodRefused !== null) {
            return $this->finish($log, $started, $this->answerer->periodRefused($understanding->periodRefused, $intent));
        }

        if ($understanding->periodUnclear) {
            return $this->finish($log, $started, $this->answerer->unclearPeriod($intent));
        }

        if ($intent === LindaIntent::UnsupportedQuestion) {
            return $this->finish($log, $started, $this->answerer->unsupported());
        }

        [$period, $branchName] = $this->carried($intent, $understanding->period, $understanding->branchName, $understanding->followUp, $history);

        return $this->answered($log, $started, $user, $intent, $period, $branchName);
    }

    /**
     * The period and branch a question is answered for, after the follow-up
     * rule: the earlier questions are read newest first, the branch is
     * carried always, and the period only when the subject is unchanged. An
     * earlier question is read with its own intent, so a branch it named as
     * a follow-up ("how about sa Cabadbaran?") carries on too.
     *
     * @return array{0: ?LindaPeriod, 1: ?string}
     */
    private function carried(LindaIntent $intent, ?LindaPeriod $period, ?string $branchName, bool $followUp, LindaHistory $history): array
    {
        if ($followUp) {
            $earlier = $history->earlier;
            $sameSubject = $earlier !== [] && $earlier[count($earlier) - 1]['intent'] === $intent->value;

            foreach (array_reverse($earlier) as $entry) {
                if ($sameSubject) {
                    $period ??= LindaPeriod::extract(LindaText::clean($entry['question']))['period'];
                }

                $branchName ??= LindaMatcher::match($entry['question'], LindaIntent::tryFrom((string) $entry['intent']))?->branchName;
            }
        }

        if ($period === null && $intent->defaultsToThisMonth()) {
            $period = LindaPeriod::named('this_month');
        }

        return [$period, $branchName];
    }

    /**
     * @throws LindaHttpError data_unavailable
     */
    private function answered(LindaQuestion $log, int $started, User $user, LindaIntent $intent, ?LindaPeriod $period, ?string $branchName): LindaReply
    {
        $log->fill([
            'intent' => $intent->value,
            'period_from' => $period?->from->toDateString(),
            'period_to' => $period?->to->toDateString(),
        ]);

        try {
            $reply = $this->answerer->answer($user, $intent, $period, $branchName);

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
