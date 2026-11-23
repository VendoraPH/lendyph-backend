<?php

namespace App\Services\Linda;

use App\Models\Branch;
use App\Models\LindaQuestion;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\Diagnostics\ErrorDigest;
use App\Services\Linda\Model\LindaModelFactory;
use App\Services\Linda\Model\LindaModelRequest;
use App\Services\Linda\Model\LindaModelUnavailable;
use App\Services\Linda\Model\LindaPrices;
use App\Services\Linda\Model\LindaUnderstanding;
use App\Services\Linda\Model\LindaUsage;
use App\Services\Linda\Model\LindaWriteRequest;
use App\Services\Linda\Model\LindaWriting;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/**
 * Answers one Linda question (Linda v1 plan, "How a question is answered").
 *
 * 1. The matcher runs first; what it understands never leaves the server.
 *    A question it misses that names a record or a person, or asks to look
 *    one up, is a lookup (LindaLookup::parse()): the server answers it from
 *    the record in the user's branches (LindaAnswerer::lookup()), logged as
 *    source `lookup`, and the model never sees it. A "name" that is one of
 *    the user's own branches or a loan product is no lookup ("collections
 *    from Main"); the rest of the question is read again without it.
 *    A "How do I…" or "Where is…" question whose every word names a page in
 *    the menu is answered from the menu (LindaMenu), also on the server.
 * 2. Right after a lookup turn ("Which member?"), a short reply (a record
 *    number, or a name) is that lookup's target (LindaLookup::reply()).
 * 3. With no match and the none driver, the reply lists what Linda can
 *    answer. Otherwise the full-name check runs (LindaNameCheck, D2): a
 *    question naming a member of the deployment is a member lookup, one
 *    naming a staff user gets the officer performance answer (with officer
 *    or ranking words) or a local "not yet", and one naming a co-maker or a
 *    walk-in a local "not yet"; all are logged as `lookup`. A failure reading
 *    the people answers data_unavailable, never the model.
 * 4. Only then, below the monthly cap, the model reads the question and the
 *    topics (intents) of up to 5 earlier turns, never their text or an
 *    answer (D1), and returns only the intent, the period words, a branch
 *    name and whether it follows on. A lookup intent it returns gets the
 *    "which one?" prompt, answered locally.
 * 5. The answerer writes the reply from the reports' own figures.
 * 6. One `linda_questions` row is written, with no text.
 *
 * A follow-up keeps the period and branch of the question it follows
 * (LindaFollowUp, the rule `linda:evaluate` scores).
 *
 * A member lookup, by code, by the lookup parser or by the full-name check,
 * that mentions reminders ("Did Juan Dela Cruz receive his reminder?") is
 * answered with that member's reminders, on the server like every lookup.
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

    /** How many times a question is read again after a branch or product name is taken out. */
    private const REREAD_MAX = 3;

    /** Words that make a member lookup about the member's payment reminders. */
    private const REMINDER_WORDS = '/(?<![\pL\pN])(?:reminders?|paalala|pahinumdom|pahimangno|sms)(?![\pL\pN])/iu';

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

        // "How do I…", "Where is…": a page in the menu, found only when every
        // word is understood, so a name or a record number never lands here.
        if (LindaMenu::find($question) !== null) {
            $log->source = LindaQuestion::SOURCE_MATCHER;

            return $this->answered($log, $started, $user, LindaIntent::HowTo, null, null, $question);
        }

        $lookup = $this->lookupIn($user, $question, $history);

        if ($lookup !== null) {
            $log->source = LindaQuestion::SOURCE_LOOKUP;

            return $this->lookedUp($log, $started, $user, self::aboutReminders($lookup, $question));
        }

        if (! in_array(config('linda.driver'), ['fake', 'anthropic'], true)) {
            return $this->finish($log, $started, $this->answerer->unmatched());
        }

        $named = $this->nameCheck($log, $started, $question);

        if ($named !== null) {
            $log->source = LindaQuestion::SOURCE_LOOKUP;

            return $this->namedPerson($log, $started, $user, $question, $named);
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
     * The lookup a question asks for: by the lookup parser, else, right after
     * a lookup turn, as a reply naming its target. A "name" that is one of the
     * user's own branches or a loan product is no lookup: its words are taken
     * out and the rest of the question is read again ("collections from Main
     * and balance of Juan"). A re-read that gives up (after 3 tries, or when
     * the name can't be found again) stays local, asking which member.
     */
    private function lookupIn(User $user, #[\SensitiveParameter] string $question, LindaHistory $history): ?LindaLookupRequest
    {
        $lookup = LindaLookup::parse($question);

        if ($lookup === null && $history->pendingLookup !== null) {
            $lookup = LindaLookup::reply($question, $history->pendingLookup);
        }

        for ($tries = 0; $lookup !== null && $this->namesAPlaceOrProduct($user, $lookup); $tries++) {
            $rest = $tries < self::REREAD_MAX ? self::without((string) $lookup->name, $question) : null;

            // Giving up stays local: a member lookup with no target asks which one.
            if ($rest === null) {
                return new LindaLookupRequest(LindaLookupRequest::MEMBER, LindaIntent::BorrowerLookup);
            }

            $question = $rest;
            $lookup = LindaLookup::parse($question);

            // A lookup with no target now only lacks the words taken out.
            if ($lookup !== null && $lookup->code === null && $lookup->name === null) {
                return null;
            }
        }

        return $lookup;
    }

    /**
     * A member lookup that names its member and mentions reminders asks for
     * that member's reminders; any other lookup is kept as it is.
     */
    private static function aboutReminders(LindaLookupRequest $lookup, #[\SensitiveParameter] string $question): LindaLookupRequest
    {
        if ($lookup->kind !== LindaLookupRequest::MEMBER || ($lookup->code === null && $lookup->name === null) || preg_match(self::REMINDER_WORDS, $question) !== 1) {
            return $lookup;
        }

        return new LindaLookupRequest($lookup->kind, $lookup->intent, $lookup->code, $lookup->name, true);
    }

    /**
     * Whether a name lookup's name is one of the user's own branch names or a
     * loan product's name (case, spaces and a trailing "Branch" or "Loan"
     * ignored).
     */
    private function namesAPlaceOrProduct(User $user, LindaLookupRequest $lookup): bool
    {
        if ($lookup->code !== null || $lookup->name === null) {
            return false;
        }

        $wanted = self::nameKey($lookup->name);

        if ($wanted === '') {
            return false;
        }

        $branchIds = $this->branchAccess->for($user);
        $names = $branchIds === [] ? collect() : Branch::query()
            ->when($branchIds !== null, fn ($query) => $query->whereIn('id', $branchIds))
            ->pluck('name');

        return $names->merge(LoanProduct::query()->pluck('name'))
            ->contains(fn ($name) => self::nameKey((string) $name) === $wanted);
    }

    /** "  Main   Branch " → "main", "Salary Loan" → "salary" */
    private static function nameKey(#[\SensitiveParameter] string $name): string
    {
        $words = LindaText::words(LindaText::clean($name));

        if ($words !== [] && in_array(end($words), ['branch', 'loan', 'loans'], true)) {
            array_pop($words);
        }

        return implode(' ', $words);
    }

    /** The question with a name's words taken out once, or null when they aren't found as typed. */
    private static function without(#[\SensitiveParameter] string $name, #[\SensitiveParameter] string $question): ?string
    {
        $words = preg_split('/\s+/u', trim($name)) ?: [];
        $pattern = '/(?<![\pL\pN])'.implode('\s+', array_map(fn (string $word) => preg_quote($word, '/'), $words)).'(?![\pL\pN])/iu';
        $rest = $words === [] ? null : preg_replace($pattern, ' ', $question, 1, $count);

        return $rest === null || $count !== 1 ? null : $rest;
    }

    /**
     * The full-name check (D2). A failure reading the people (the database)
     * never lets the question through to the model: it logs a digest only,
     * writes the question row with data_unavailable and answers 503.
     *
     * @throws LindaHttpError data_unavailable
     */
    private function nameCheck(LindaQuestion $log, int $started, #[\SensitiveParameter] string $question): ?LindaNameCheck
    {
        try {
            return LindaNameCheck::find($question);
        } catch (Throwable $e) {
            Log::warning('Linda name check failed.', ErrorDigest::context($e));
            $log->fill(['source' => LindaQuestion::SOURCE_LOOKUP, 'outcome' => self::OUTCOME_ERROR, 'error_code' => LindaHttpError::DATA_UNAVAILABLE]);
            $this->write($log, $started);

            throw LindaHttpError::dataUnavailable();
        }
    }

    /**
     * A question naming someone by full name (D2), answered locally: a member
     * lookup (with no target when the read was capped); for a staff user, the
     * officer performance answer when it has officer or ranking words, for the
     * period it names (this month by default), else a fixed "not yet"; for a
     * co-maker or a walk-in, a fixed "not yet". Any other capped read gets the
     * neutral "not yet": it doesn't mean someone was named.
     *
     * @throws LindaHttpError data_unavailable
     */
    private function namedPerson(LindaQuestion $log, int $started, User $user, #[\SensitiveParameter] string $question, LindaNameCheck $named): LindaReply
    {
        if ($named->kind === LindaNameCheck::MEMBER) {
            return $this->lookedUp($log, $started, $user, self::aboutReminders(new LindaLookupRequest(LindaLookupRequest::MEMBER, LindaIntent::BorrowerLookup, null, $named->name), $question));
        }

        // A capped read named no one in particular: a neutral reply, kept local.
        if ($named->name === null) {
            return $this->finish($log, $started, new LindaReply(LindaIntent::UnsupportedQuestion->value, LindaWording::notYet(), 'not_yet', answerFunction: class_basename(LindaNameCheck::class)));
        }

        if ($named->kind !== LindaNameCheck::STAFF) {
            return $this->finish($log, $started, new LindaReply(LindaIntent::UnsupportedQuestion->value, LindaWording::otherPersonNotYet(), 'not_yet', answerFunction: class_basename(LindaNameCheck::class)));
        }

        if (! $named->performance) {
            return $this->finish($log, $started, new LindaReply(LindaIntent::UnsupportedQuestion->value, LindaWording::staffNotYet(), 'not_yet', answerFunction: class_basename(LindaNameCheck::class)));
        }

        $intent = LindaIntent::OfficerPerformance;
        $found = LindaPeriod::extract(LindaText::clean($question));

        if ($found['refused']) {
            return $this->finish($log, $started, $this->answerer->periodRefused((string) ($found['reason'] ?? LindaPeriod::REFUSED_INVALID), $intent));
        }

        [$period] = LindaFollowUp::carry($intent, $found['period'], null, false, []);

        return $this->answered($log, $started, $user, $intent, $period, null, $question);
    }

    /**
     * Ask the model, below the cap, with its prices checked first so a broken
     * price table, rate or cap sends nothing (`no_prices`). A failed call is
     * logged with its reason code and the cost it may have run up: the usage
     * of an answer that couldn't be used, or, for a timeout or an internal
     * failure once the call may have been sent (either may still be billed),
     * the worst-case estimate on the requested model.
     *
     * Earlier questions go to the model as their topics (intents) only, never
     * their text (D1); a lookup's topic is left out too.
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
            $request = new LindaModelRequest($question, array_column($history->earlier, 'intent'), $model);
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

        $lookupKind = $intent->lookupKind();

        // The question already passed the parser, so the model's lookup intent
        // asks which record is meant.
        if ($lookupKind !== null) {
            return $this->lookedUp($log, $started, $user, new LindaLookupRequest($lookupKind, $intent));
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

        if ($intent === LindaIntent::OpenQuestion) {
            return LindaIntent::openQuestionsOn()
                ? $this->openAnswer($log, $started, $user, $question, $period, $this->asTyped($branchName, $question, $history), $history, $understanding->topics)
                : $this->finish($log, $started, $this->answerer->notYet($intent, $user));
        }

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

    /**
     * An open question (round 3, LINDA_OPEN_QUESTIONS): the model writes the
     * answer from the facts pack (LindaFacts), which the topic answers build
     * under the caller's permissions and branches. It has already passed
     * every guard (lookups, the full-name check), and only reaches here once
     * the model classified it.
     *
     * The written answer is shown only when every number in it is in the
     * pack and it holds no identifier or person's name (a name check that
     * can't run counts as a name). Otherwise, or when the pack is empty, the
     * cap is reached or the call fails, the fixed "not yet" reply is shown
     * instead. The log records `used` or `fell_back`, never text, and books
     * both calls' usage on the one row.
     *
     * @throws LindaHttpError data_unavailable
     */
    /**
     * @param  list<LindaIntent>  $topics  the topics the classifier picked for the pack
     */
    private function openAnswer(LindaQuestion $log, int $started, User $user, #[\SensitiveParameter] string $question, ?LindaPeriod $period, ?string $branchName, LindaHistory $history, array $topics): LindaReply
    {
        $period ??= LindaPeriod::named('this_month');
        $log->fill(['intent' => LindaIntent::OpenQuestion->value, 'period_from' => $period->from->toDateString(), 'period_to' => $period->to->toDateString()]);

        try {
            $pack = app(LindaFacts::class)->build($user, $period, $branchName, $topics);
        } catch (Throwable $e) {
            Log::warning('Linda answer failed.', ['intent' => LindaIntent::OpenQuestion->value] + ErrorDigest::context($e));
            $log->fill(['outcome' => self::OUTCOME_ERROR, 'error_code' => LindaHttpError::DATA_UNAVAILABLE]);
            $this->write($log, $started);

            throw LindaHttpError::dataUnavailable();
        }

        $facts = $pack['facts'];

        // A pack that can't be encoded is never sent: the row is written with
        // the classifier's cost already on it, and the reply falls back.
        try {
            $encoded = json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Log::warning('Linda facts pack could not be encoded.', ErrorDigest::context($e));

            return $this->fellBack($log, $started, $user);
        }

        // The cap check counts this question's classifier call, not yet saved.
        if ($facts['topics'] === [] || LindaFacts::hasIdentifier($encoded) || $this->budget->reachedWith((string) ($log->cost ?? '0'))) {
            return $this->fellBack($log, $started, $user);
        }

        $writing = $this->writeOpen($log, new LindaWriteRequest($question, array_column($history->earlier, 'intent'), $facts, (string) ($log->model ?? $this->settings->model())));

        if ($writing === null || ! LindaFacts::fits($writing->text, $facts) || $this->namesSomeone($writing->text)) {
            return $this->fellBack($log, $started, $user);
        }

        $links = [];
        foreach (LindaFacts::topicsUsed($writing->text, $facts) as $topic) {
            foreach ($pack['links'][$topic] ?? [] as $link) {
                $links[$link['url']] = $link;
            }
        }

        $log->open_answer = LindaQuestion::OPEN_USED;

        return $this->finish($log, $started, new LindaReply(
            LindaIntent::OpenQuestion->value,
            $writing->text."\n\n".LindaWording::writtenFromFigures(),
            'answered',
            links: array_slice(array_values($links), 0, 4),
            answerFunction: class_basename(LindaFacts::class),
        ));
    }

    /**
     * The model's written answer, its usage added to the row; null when the
     * call failed (its cost booked as understand() books a failed call).
     */
    private function writeOpen(LindaQuestion $log, LindaWriteRequest $request): ?LindaWriting
    {
        $sent = false;

        try {
            LindaPrices::worstCaseWriteEstimate($request->model);
            $writer = LindaModelFactory::writer((string) config('linda.driver'));

            if ($writer === null) {
                return null;
            }

            $sent = true;
            $writing = $writer->write($request);
            $this->addUsage($log, $writing->usage);

            return $writing;
        } catch (LindaModelUnavailable $e) {
            if ($e->usage !== null) {
                $this->addUsage($log, $e->usage);
            } elseif ($sent && in_array($e->reason, ['timeout', 'bad_output'], true)) {
                $this->addWorstCase($log, $request->model);
            }
        } catch (Throwable $e) {
            Log::warning('Linda model call failed.', ['reason' => self::REASON_INTERNAL] + ErrorDigest::context($e));

            if ($sent) {
                $this->addWorstCase($log, $request->model);
            }
        }

        return null;
    }

    /** Whether a written answer names one of the deployment's people; a check that can't run says yes. */
    private function namesSomeone(#[\SensitiveParameter] string $text): bool
    {
        try {
            return LindaNameCheck::find($text) !== null;
        } catch (Throwable) {
            return true;
        }
    }

    private function fellBack(LindaQuestion $log, int $started, User $user): LindaReply
    {
        $log->open_answer = LindaQuestion::OPEN_FELL_BACK;

        return $this->finish($log, $started, $this->answerer->notYet(LindaIntent::OpenQuestion, $user));
    }

    /** A second call's usage, added to the row's tokens and cost. */
    private function addUsage(LindaQuestion $log, LindaUsage $usage): void
    {
        $log->fill([
            'input_tokens' => (int) $log->input_tokens + $usage->inputTokens,
            'output_tokens' => (int) $log->output_tokens + $usage->outputTokens,
            'cache_read_tokens' => (int) $log->cache_read_tokens + $usage->cacheReadTokens,
            'cache_write_tokens' => (int) $log->cache_write_tokens + $usage->cacheWriteTokens,
            'cost' => (string) BigDecimal::of((string) ($log->cost ?? '0'))->plus(LindaPrices::cost($usage))->toScale(6, RoundingMode::HalfUp),
        ]);
    }

    /** A failed write call's charge: the write call's own worst case, added to the row. */
    private function addWorstCase(LindaQuestion $log, string $model): void
    {
        try {
            $log->cost = (string) BigDecimal::of((string) ($log->cost ?? '0'))->plus(LindaPrices::worstCaseWriteEstimate($model))->toScale(6, RoundingMode::HalfUp);
        } catch (LindaModelUnavailable) {
            $log->error_code = 'no_prices';
        }
    }

    /**
     * A lookup, answered on the server from the record it names.
     *
     * @throws LindaHttpError data_unavailable
     */
    private function lookedUp(LindaQuestion $log, int $started, User $user, LindaLookupRequest $lookup): LindaReply
    {
        $log->intent = $lookup->intent->value;

        try {
            $reply = $this->answerer->lookup($user, $lookup);
        } catch (Throwable $e) {
            Log::warning('Linda answer failed.', ['intent' => $lookup->intent->value] + ErrorDigest::context($e));
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
