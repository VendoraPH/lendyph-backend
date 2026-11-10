<?php

namespace App\Services\Cda\Registers;

use App\Models\CdaDocument;
use App\Models\CdaNoticeSent;
use App\Models\CdaOfficer;
use App\Models\User;
use App\Notifications\StaffNotification;
use App\Services\BranchAccess;
use App\Services\Cda\CdaAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The nightly register notices (CDA design §10), in-app only:
 *
 * - a document expiring within 30 days (Manila): once per document and
 *   expiry date, so a renewed date can be noticed again;
 * - an officer serving today who hasn't completed a training their position
 *   requires: once per officer and year.
 *
 * Recipients are active users holding `branches:view_all` and `cda:prepare`
 * or `cda:approve`, plus the document's view permission when it is
 * restricted. A notice nobody can receive is neither sent nor recorded, so it
 * goes out once someone gains the permission. Each notice is recorded in
 * `cda_notices_sent` before it is
 * sent, in one transaction, so a second run sends nothing; a failure to send
 * is logged and rolls back only that notice.
 */
final class RegisterNotices
{
    public const DOCUMENT_EXPIRING = 'cda_document_expiring';

    public const TRAINING_INCOMPLETE = 'cda_training_incomplete';

    public const EXPIRY_WINDOW_DAYS = 30;

    /** @var Collection<int, User>|null */
    private ?Collection $staff = null;

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly TrainingCompliance $compliance,
    ) {}

    /** @return array{documents: int, trainings: int} how many notices went out */
    public function run(?Carbon $today = null): array
    {
        $today = ($today ?? now('Asia/Manila'))->copy()->startOfDay();

        return [
            'documents' => $this->expiringDocuments($today),
            'trainings' => $this->incompleteTrainings($today),
        ];
    }

    private function expiringDocuments(Carbon $today): int
    {
        $sent = 0;
        $documents = CdaDocument::query()
            ->where('status', 'active')
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '>=', $today->toDateString())
            ->whereDate('expires_on', '<=', $today->copy()->addDays(self::EXPIRY_WINDOW_DAYS)->toDateString())
            ->orderBy('expires_on')->orderBy('id')
            ->get();

        foreach ($documents as $document) {
            $restrictions = $this->documents->restrictionsFor($document);
            $recipients = $this->staff()->filter(fn (User $user) => collect($restrictions)->every(fn (string $permission) => $user->can($permission)));
            $days = (int) $today->diffInDays($document->expires_on->copy()->startOfDay());

            $sent += $this->sendOnce('cda_document', $document->id, self::DOCUMENT_EXPIRING, $document->expires_on->toDateString(), $recipients, new StaffNotification(
                self::DOCUMENT_EXPIRING,
                'CDA document expiring',
                sprintf('%s expires on %s (%s).', $document->title, $document->expires_on->format('M j, Y'), $days === 0 ? 'today' : ($days === 1 ? 'in 1 day' : "in {$days} days")),
                '/cda/documents',
                ['cda_document_id' => $document->id],
            ));
        }

        return $sent;
    }

    private function incompleteTrainings(Carbon $today): int
    {
        $sent = 0;
        $date = $today->toDateString();
        $officers = CdaOfficer::query()->with(['member', 'trainings'])->servingOn($date)->orderBy('id')->get();

        foreach ($officers as $officer) {
            $result = $this->compliance->evaluate($officer, $date, $date);

            if ($result['status'] !== TrainingCompliance::INCOMPLETE) {
                continue;
            }

            $missing = implode(', ', array_map(TrainingCompliance::keyInWords(...), $result['missing']));
            $noun = count($result['missing']) === 1 ? 'training' : 'trainings';

            $sent += $this->sendOnce('cda_officer', $officer->id, self::TRAINING_INCOMPLETE, (string) $today->year, $this->staff(), new StaffNotification(
                self::TRAINING_INCOMPLETE,
                'Officer training incomplete',
                sprintf('%s (%s) has not completed the required %s: %s.', $officer->displayName() ?? 'An officer', RegisterWords::position($officer->position), $noun, $missing),
                '/cda/officers',
                ['cda_officer_id' => $officer->id],
            ));
        }

        return $sent;
    }

    /** @param  Collection<int, User>  $recipients */
    private function sendOnce(string $subjectType, int $subjectId, string $kind, string $dedupeKey, Collection $recipients, StaffNotification $notice): int
    {
        if ($recipients->isEmpty()) {
            return 0;
        }

        $key = ['subject_type' => $subjectType, 'subject_id' => $subjectId, 'kind' => $kind, 'dedupe_key' => $dedupeKey];

        if (CdaNoticeSent::query()->where($key)->exists()) {
            return 0;
        }

        try {
            DB::transaction(function () use ($key, $recipients, $notice): void {
                CdaNoticeSent::query()->create([...$key, 'sent_at' => now()]);
                Notification::send($recipients, $notice);
            });
        } catch (UniqueConstraintViolationException) {
            return 0;
        } catch (Throwable $e) {
            Log::error('A CDA register notice could not be sent; it will be tried again on the next run.', [
                'subject_type' => $subjectType, 'subject_id' => $subjectId, 'kind' => $kind, 'exception' => $e,
            ]);

            return 0;
        }

        return 1;
    }

    /**
     * Active users who prepare or approve CDA reports and see every branch.
     *
     * @return Collection<int, User>
     */
    private function staff(): Collection
    {
        return $this->staff ??= User::query()->active()->orderBy('id')->get()
            ->filter(fn (User $user) => $user->can(BranchAccess::VIEW_ALL) && $user->canAny([CdaAccess::PREPARE, CdaAccess::APPROVE]))
            ->values();
    }
}
