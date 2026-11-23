<?php

namespace App\Services\LoanOrigination\Intake;

use App\Models\Branch;
use App\Models\LoanProduct;
use App\Models\OriginationApplication;
use App\Models\OriginationConsent;
use App\Models\OriginationDocument;
use App\Models\OriginationLink;
use App\Models\OriginationNotice;
use App\Models\OriginationSession;
use App\Services\AuditLogService;
use App\Services\GeneratedNumberWrite;
use App\Services\LoanOrigination\ApplicationReference;
use App\Services\LoanOrigination\ApplicationTransitions;
use App\Services\LoanOrigination\DraftRules;
use App\Services\LoanOrigination\Files\OriginationFileStore;
use App\Services\LoanOrigination\OriginationNotices;
use App\Services\LoanWriteTransaction;
use App\Support\PhilippineMobile;
use Brick\Math\RoundingMode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The applicant's own writes on a public link (origination decisions 3, 5, 9,
 * 10, 16 and 18), all through LO-B1's rules: DraftRules for the draft,
 * OriginationFileStore for files, ApplicationTransitions for every status
 * change and ApplicationReference for the reference number.
 *
 * - open(): after a verified code. `apply` returns the mobile's open draft or
 *   starts one (one open draft per mobile, by the unique `open_mobile_hash`);
 *   `resume` finds the open draft on this link; `track` finds a submitted
 *   application by its reference and the mobile together.
 * - saveDraft(): the whitelist, the link's products and branches, completion
 *   worked out on the server; an incomplete draft comes back to draft.
 * - upload() / removeDocument(): while the draft is open; while tracking,
 *   only a document staff asked for again.
 * - submit(): under a row lock, idempotent: a repeat answers the first
 *   reference. Every required notice must be accepted at its current
 *   version; each consent is recorded with its version, text hash, time, IP
 *   and user agent; optional consents are their own rows.
 *
 * Audit rows name no one (the `applicant` actor) and hold ids only.
 */
final class ApplicantIntake
{
    public const SUBMITTED = 'This application has already been submitted, so it can\'t be changed here.';

    public const NOT_SUBMITTABLE = 'This application can\'t be submitted any more. Please contact the office.';

    public const OTHER_LINK = 'You already have an application in progress from another link. Open that link to continue it.';

    public const NO_DRAFT = 'We couldn\'t find an application in progress for this number.';

    public const NO_APPLICATION = 'We couldn\'t find an application with that reference for this number.';

    public const NOT_REPLACEABLE = 'This document can\'t be changed now.';

    public function __construct(
        private readonly DraftRules $rules,
        private readonly PublicPortal $portal,
        private readonly ApplicationTransitions $transitions,
        private readonly OriginationFileStore $files,
        private readonly OriginationNotices $notices,
        private readonly ApplicationReference $references,
        private readonly PublicApplicationPresenter $presenter,
    ) {}

    /**
     * The application a verified code opens.
     *
     * @throws NotFoundHttpException when there is nothing to resume or track
     */
    public function open(OriginationLink $link, string $purpose, string $mobile, ?string $reference, Request $request): OriginationApplication
    {
        $hash = OriginationApplication::mobileHash($mobile);

        if ($purpose === 'track') {
            return OriginationApplication::query()
                ->where('reference_number', $reference)
                ->where('mobile_hash', $hash)
                ->whereNotIn('status', OriginationApplication::UNSUBMITTED_STATUSES)
                ->first() ?? throw new NotFoundHttpException(self::NO_APPLICATION);
        }

        if ($purpose === 'resume') {
            return OriginationApplication::query()->where('open_mobile_hash', $hash)->where('link_id', $link->id)->first()
                ?? throw new NotFoundHttpException(self::NO_DRAFT);
        }

        try {
            return $this->startOrFind($link, $mobile, $hash, $request);
        } catch (UniqueConstraintViolationException) {
            // Another verify for the same mobile started it a moment ago.
            return $this->startOrFind($link, $mobile, $hash, $request);
        }
    }

    /** @return array<string, mixed> */
    public function saveDraft(OriginationApplication $application, OriginationLink $link, mixed $input): array
    {
        $draft = $this->rules->normalize($input);
        $productId = $draft['loan']['loan_product_id'];

        if ($productId !== null && ! $this->portal->offers($link, $productId)) {
            throw ValidationException::withMessages(['draft.loan.loan_product_id' => 'Choose one of the loan products on this page.']);
        }

        if ($link->branch_id !== null) {
            $draft['personal']['branch_id'] = $link->branch_id;
        } elseif ($draft['personal']['branch_id'] !== null && ! $this->portal->branches($link)->contains('id', $draft['personal']['branch_id'])) {
            throw ValidationException::withMessages(['draft.personal.branch_id' => 'Choose one of the branches on this page.']);
        }

        $saved = DB::transaction(function () use ($application, $link, $draft, $productId): OriginationApplication {
            $locked = $this->lockOpen($application);

            if ($locked->status === 'incomplete') {
                $locked = $this->transitions->move($locked, 'draft', ApplicationTransitions::ACTOR_APPLICANT);
            }

            $name = trim($draft['personal']['first_name'].' '.$draft['personal']['last_name']);
            $term = trim((string) $draft['loan']['term']);

            $locked->fill([
                'draft' => $draft,
                'applicant_name' => $name === '' ? null : mb_substr($name, 0, 200),
                'loan_product_id' => $productId,
                'requested_amount' => $this->rules->decimal($draft['loan']['amount'])?->toScale(2, RoundingMode::Unnecessary)->__toString(),
                'requested_term' => preg_match('/^\d{1,4}$/', $term) === 1 ? (int) $term : null,
                'branch_id' => $this->portal->branchFor($link, $draft['personal']['branch_id']),
                'last_saved_at' => now(),
            ]);
            $this->fillCompletion($locked);
            $locked->save();

            return $locked;
        });

        return $this->presenter->state($saved);
    }

    /**
     * @return array<string, mixed> the stored document, as the applicant sees it
     */
    public function upload(OriginationApplication $application, OriginationSession $session, string $type, UploadedFile $file): array
    {
        $types = collect($this->portal->documentTypes($application->loan_product_id))->keyBy('type');

        if (! $types->has($type)) {
            throw ValidationException::withMessages(['type' => 'Choose one of the documents listed.']);
        }

        $application->refresh();

        if ($session->isTracking()) {
            $existing = $application->documents()->where('type', $type)->first();

            if (! in_array($application->status, PublicApplicationPresenter::REPLACEABLE_STATUSES, true)
                || ! in_array($existing?->status, PublicApplicationPresenter::REPLACEABLE_DOCUMENTS, true)) {
                throw LoanWriteTransaction::conflict(self::NOT_REPLACEABLE);
            }
        } elseif (! $application->isUnsubmitted()) {
            throw LoanWriteTransaction::conflict(self::SUBMITTED);
        }

        $version = $this->files->store($application, $type, $file);

        AuditLogService::log(
            'document_uploaded',
            $application,
            null,
            ['document_id' => $version->document_id, 'version_id' => $version->id, 'type' => $type],
            'An applicant uploaded a document to an online application.',
            asApplicant: true,
        );

        if ($application->fresh()->isUnsubmitted()) {
            $this->refreshCompletion($application);
        }

        return $this->presenter->document($types->get($type), OriginationDocument::query()->with('currentVersion')->findOrFail($version->document_id));
    }

    public function removeDocument(OriginationApplication $application, string $publicId): void
    {
        if (! $application->fresh()->isUnsubmitted()) {
            throw LoanWriteTransaction::conflict(self::SUBMITTED);
        }

        /** @var ?OriginationDocument $document */
        $document = $application->documents()->where('public_id', $publicId)->first();

        if ($document === null) {
            throw new NotFoundHttpException('This document isn\'t on your application.');
        }

        foreach ($document->versions()->get() as $version) {
            $this->files->delete($version);
        }

        $document->delete();

        AuditLogService::log(
            'document_removed',
            $application,
            ['document_id' => $document->id, 'type' => $document->type],
            null,
            'An applicant removed a document from an online application.',
            asApplicant: true,
        );

        $this->refreshCompletion($application);
    }

    /**
     * @param  array{privacy_notice_version: string, terms_version: string, declaration_version: string, credit_authorization_version: ?string, marketing_opt_in: bool}  $consents
     * @return array{reference_number: string, submitted_at: mixed}
     */
    public function submit(OriginationApplication $application, OriginationLink $link, array $consents, Request $request): array
    {
        return GeneratedNumberWrite::run(function () use ($application, $link, $consents, $request): array {
            /** @var OriginationApplication $locked */
            $locked = OriginationApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

            if ($locked->reference_number !== null && ! $locked->isUnsubmitted()) {
                return ['reference_number' => $locked->reference_number, 'submitted_at' => $locked->submitted_at];
            }

            if (! $locked->isUnsubmitted()) {
                throw LoanWriteTransaction::conflict(self::NOT_SUBMITTABLE);
            }

            if (($closed = $this->portal->closedReason($link)) !== null) {
                throw LoanWriteTransaction::conflict($closed);
            }

            $current = $this->notices->currentAll();
            $this->assertSubmittable($locked, $link, $consents, $current);

            foreach (OriginationNotice::TYPES as $type) {
                if ($current[$type] !== null) {
                    $this->consent($locked, $type, $current[$type], true, 'submit', $request);
                }
            }

            $this->consent($locked, 'marketing', null, (bool) $consents['marketing_opt_in'], 'submit', $request);

            $this->references->assign($locked);
            $submitted = $this->transitions->move($locked, 'submitted', ApplicationTransitions::ACTOR_APPLICANT);

            OriginationLink::query()->whereKey($link->id)->increment('submitted');

            AuditLogService::log(
                'submitted',
                $submitted,
                null,
                ['reference_number' => $submitted->reference_number, 'link_id' => $link->id, 'branch_id' => $submitted->branch_id],
                'An applicant submitted an online application.',
                asApplicant: true,
            );

            return ['reference_number' => (string) $submitted->reference_number, 'submitted_at' => $submitted->submitted_at];
        }, ApplicationReference::CONFLICT);
    }

    private function startOrFind(OriginationLink $link, string $mobile, string $hash, Request $request): OriginationApplication
    {
        return DB::transaction(function () use ($link, $mobile, $hash, $request): OriginationApplication {
            /** @var ?OriginationApplication $open */
            $open = OriginationApplication::query()->where('open_mobile_hash', $hash)->lockForUpdate()->first();

            if ($open !== null && $open->link_id !== $link->id) {
                throw LoanWriteTransaction::conflict(self::OTHER_LINK);
            }

            $application = $open ?? $this->start($link, $mobile, $hash);
            $this->consent($application, 'privacy_notice', $this->notices->current('privacy_notice'), true, 'verify', $request);

            return $application;
        });
    }

    private function start(OriginationLink $link, string $mobile, string $hash): OriginationApplication
    {
        $application = new OriginationApplication;
        $application->fill([
            'link_id' => $link->id,
            'branch_id' => $this->portal->branchFor($link, null),
            'mobile' => PhilippineMobile::normalise($mobile),
            'mobile_hash' => $hash,
            'draft' => $this->rules->empty(),
            'is_test' => $link->is_test,
            'started_at' => now(),
            'last_saved_at' => now(),
        ]);
        $this->fillCompletion($application, documentsComplete: false);
        $application->save();

        OriginationLink::query()->whereKey($link->id)->increment('started');

        AuditLogService::log(
            'created',
            $application,
            null,
            ['link_id' => $link->id, 'branch_id' => $application->branch_id],
            'An applicant started an online application.',
            asApplicant: true,
        );

        return $application;
    }

    private function lockOpen(OriginationApplication $application): OriginationApplication
    {
        /** @var OriginationApplication $locked */
        $locked = OriginationApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isUnsubmitted()) {
            throw LoanWriteTransaction::conflict(self::SUBMITTED);
        }

        return $locked;
    }

    private function refreshCompletion(OriginationApplication $application): void
    {
        DB::transaction(function () use ($application): void {
            $locked = OriginationApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

            if ($locked->isUnsubmitted()) {
                $this->fillCompletion($locked);
                $locked->save();
            }
        });
    }

    private function fillCompletion(OriginationApplication $application, ?bool $documentsComplete = null): void
    {
        $draft = $application->draft ?? $this->rules->empty();
        $product = $application->loan_product_id === null ? null : LoanProduct::query()->find($application->loan_product_id);
        $done = $this->rules->completedSteps($draft, $product, true, $documentsComplete ?? $this->missingDocuments($application) === []);

        $application->completion_percent = $this->rules->completionPercent($done);
        $application->last_completed_step = $this->rules->lastCompletedStep($done);
    }

    /**
     * The labels of required documents with no file yet.
     *
     * @return list<string>
     */
    private function missingDocuments(OriginationApplication $application): array
    {
        if (! $application->exists) {
            return [];
        }

        $uploaded = $application->documents()->whereNotNull('current_version_id')->pluck('type')->all();

        return array_values(array_map(
            fn (array $type) => $type['label'],
            array_filter($this->portal->documentTypes($application->loan_product_id), fn (array $type) => $type['required'] && ! in_array($type['type'], $uploaded, true)),
        ));
    }

    /**
     * @param  array<string, mixed>  $consents
     * @param  array<string, ?OriginationNotice>  $current
     *
     * @throws ValidationException every reason at once, in words
     */
    private function assertSubmittable(OriginationApplication $application, OriginationLink $link, array $consents, array $current): void
    {
        $errors = [];

        foreach (OriginationNotice::TYPES as $type) {
            $sent = $consents["{$type}_version"] ?? null;

            if ($current[$type] !== null && $sent !== $current[$type]->version) {
                $errors["{$type}_version"] = sprintf('Read and accept the current %s to continue.', strtolower(OriginationNotice::TYPE_LABELS[$type]));
            }
        }

        $draft = $application->draft ?? $this->rules->empty();
        $productId = $application->loan_product_id;

        if ($productId !== null && ! $this->portal->offers($link, $productId)) {
            $errors['draft.loan.loan_product_id'] = 'This loan product isn\'t offered online any more. Choose another one.';
        }

        $product = $productId === null ? null : LoanProduct::query()->find($productId);
        $errors += $this->presenter->missingFields($draft, $product, $productId);

        if ($application->branch_id === null || ! Branch::query()->whereKey($application->branch_id)->where('status', Branch::STATUS_ACTIVE)->exists()) {
            $errors['draft.personal.branch_id'] ??= 'Choose a branch.';
        }

        $missing = $this->missingDocuments($application);

        if ($missing !== []) {
            $errors['documents'] = 'Upload your '.implode(' and ', array_map('strtolower', $missing)).'.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function consent(OriginationApplication $application, string $type, ?OriginationNotice $notice, bool $accepted, string $stage, Request $request): void
    {
        OriginationConsent::query()->create([
            'application_id' => $application->id,
            'type' => $type,
            'notice_id' => $notice?->id,
            'version' => $notice?->version,
            'text_hash' => $notice?->body_hash,
            'accepted' => $accepted,
            'stage' => $stage,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent() === null ? null : mb_substr((string) AuditLogService::cleanHeader($request->userAgent()), 0, 255),
            'accepted_at' => now(),
        ]);
    }
}
