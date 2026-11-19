<?php

namespace App\Http\Controllers\Api\LoanOrigination\PublicApply;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PublicApply\RequireApplicationSession;
use App\Http\Middleware\PublicApply\ResolveApplicationLink;
use App\Models\LoanProduct;
use App\Models\OriginationApplication;
use App\Models\OriginationLink;
use App\Models\OriginationSession;
use App\Services\LoanOrigination\Intake\ApplicantIntake;
use App\Services\LoanOrigination\Intake\ApplicantOtp;
use App\Services\LoanOrigination\Intake\ApplicantSessions;
use App\Services\LoanOrigination\Intake\PublicApplicationPresenter;
use App\Services\LoanOrigination\Intake\PublicEstimate;
use App\Services\LoanOrigination\Intake\PublicPortal;
use App\Services\LoanOrigination\OriginationNotices;
use App\Services\LoanWriteTransaction;
use App\Support\PhilippineMobile;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The public application (origination audit §4.1–5 and §4.8, slice LO-B2),
 * under `/api/public-apply/{token}`: no account, a link token, and after the
 * code step an `X-Application-Session` header (PublicApplyStack). Every
 * figure and rule is the server's; answers are `{data: …}`.
 */
class PublicApplyController extends Controller
{
    public function __construct(
        private readonly PublicPortal $portal,
        private readonly ApplicantOtp $otp,
        private readonly ApplicantSessions $sessions,
        private readonly ApplicantIntake $intake,
        private readonly PublicApplicationPresenter $presenter,
        private readonly OriginationNotices $notices,
    ) {}

    /** GET /public-apply/{token}: the landing page. */
    public function portal(Request $request): JsonResponse
    {
        $link = self::link($request);
        $this->portal->countVisit($link);

        return response()->json(['data' => $this->portal->present($link)]);
    }

    /** POST /public-apply/{token}/otp: a code for a new application, a resume or tracking. */
    public function requestCode(Request $request): JsonResponse
    {
        $link = self::link($request);
        $input = $this->validateTarget($request);

        if ($input['purpose'] !== 'track' && ($closed = $this->portal->closedReason($link)) !== null) {
            throw LoanWriteTransaction::conflict($closed);
        }

        return response()->json(['data' => $this->otp->request(
            $link, $input['purpose'], $input['mobile'], $input['reference_number'], $request->ip(),
        )]);
    }

    /** POST /public-apply/{token}/otp/verify: the code, then a session. */
    public function verifyCode(Request $request): JsonResponse
    {
        $link = self::link($request);
        $input = $this->validateTarget($request, [
            'code' => ['required', 'string'],
            'privacy_notice_version' => ['nullable', 'string', 'max:20'],
        ]);

        if ($input['purpose'] !== 'track' && ($closed = $this->portal->closedReason($link)) !== null) {
            throw LoanWriteTransaction::conflict($closed);
        }

        if ($input['purpose'] === 'apply') {
            if (($request->input('privacy_notice_version')) !== $this->notices->current('privacy_notice')?->version) {
                throw ValidationException::withMessages(['privacy_notice_version' => 'Read and accept the current privacy notice to continue.']);
            }
        }

        if (preg_match('/^\d{6}$/', (string) $request->input('code')) !== 1) {
            throw ValidationException::withMessages(['code' => ApplicantOtp::WRONG_CODE]);
        }

        $this->otp->verify($link, $input['purpose'], $input['mobile'], (string) $request->input('code'), $input['reference_number']);
        $application = $this->intake->open($link, $input['purpose'], $input['mobile'], $input['reference_number'], $request);
        ['token' => $token, 'session' => $session] = $this->sessions->start($application, $link, $input['purpose'], $request->ip(), $request->userAgent());

        return response()->json(['data' => [
            'session_token' => $token,
            'expires_at' => $session->expires_at,
            'idle_minutes' => (int) config('loan_origination_intake.sessions.idle_minutes', 30),
            'application' => $session->isTracking() ? null : $this->presenter->state($application->fresh()),
        ]]);
    }

    /** GET /public-apply/{token}/draft */
    public function showDraft(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->presenter->state(self::application($request)->fresh())]);
    }

    /** PUT /public-apply/{token}/draft: autosave. */
    public function saveDraft(Request $request): JsonResponse
    {
        $request->validate([
            'draft' => ['present'],
            'last_completed_step' => ['nullable', 'string', 'max:20'],
        ]);

        return response()->json(['data' => $this->intake->saveDraft(self::application($request), self::link($request), $request->input('draft'))]);
    }

    /** POST /public-apply/{token}/estimate: the loan form's own preview. Saves nothing. */
    public function estimate(Request $request, PublicEstimate $estimate): JsonResponse
    {
        $input = $request->validate([
            'loan_product_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'term' => ['required', 'integer', 'min:1', 'max:10950'],
            'frequency' => ['nullable', 'string', 'max:30'],
        ], [
            'amount.regex' => 'Enter an amount in pesos with at most two decimals.',
        ]);

        $link = self::link($request);

        if (! $this->portal->offers($link, (int) $input['loan_product_id'])) {
            throw ValidationException::withMessages(['loan_product_id' => 'Choose one of the loan products on this page.']);
        }

        /** @var LoanProduct $product */
        $product = LoanProduct::query()->findOrFail((int) $input['loan_product_id']);
        if ($product->term_unit?->value !== 'days' && (int) $input['term'] > 360) {
            throw ValidationException::withMessages(['term' => 'Choose a term of at most 360 months.']);
        }

        $frequencies = array_values(array_filter((array) ($product->frequencies ?? []), 'is_string')) ?: array_filter([(string) $product->frequency]);
        $frequency = $input['frequency'] ?? (count($frequencies) === 1 ? reset($frequencies) : null);

        if (! is_string($frequency) || ! in_array($frequency, $frequencies, true)) {
            throw ValidationException::withMessages(['frequency' => 'Choose one of this product\'s payment schedules.']);
        }

        return response()->json(['data' => $estimate->estimate($product, BigDecimal::of((string) $input['amount']), (int) $input['term'], $frequency)]);
    }

    /** POST /public-apply/{token}/documents: one file for one document type. */
    public function uploadDocument(Request $request): JsonResponse
    {
        $input = $request->validate([
            'type' => ['required', 'string', 'max:50'],
            'file' => ['required', 'file'],
        ], [
            'file.required' => 'Choose a file to upload.',
            'file.file' => 'Choose a file to upload.',
        ]);

        return response()->json([
            'data' => $this->intake->upload(self::application($request), self::session($request), $input['type'], $request->file('file')),
        ], 201);
    }

    /** DELETE /public-apply/{token}/documents/{document}: by its public id. */
    public function removeDocument(Request $request): Response
    {
        $this->intake->removeDocument(self::application($request), (string) $request->route('document'));

        return response()->noContent();
    }

    /** POST /public-apply/{token}/submit: idempotent; a repeat answers the first reference. */
    public function submit(Request $request): JsonResponse
    {
        $input = $request->validate([
            'privacy_notice_version' => ['required', 'string', 'max:20'],
            'terms_version' => ['required', 'string', 'max:20'],
            'declaration_version' => ['required', 'string', 'max:20'],
            'credit_authorization_version' => ['nullable', 'string', 'max:20'],
            'marketing_opt_in' => ['required', 'boolean'],
        ]);

        $result = $this->intake->submit(self::application($request), self::link($request), [
            'credit_authorization_version' => null,
            ...$input,
        ], $request);

        // The draft is the office's now: this session ends, so it can't read
        // the draft back. Tracking takes a new code and the reference.
        $this->sessions->end(self::session($request));

        return response()->json(['data' => $result]);
    }

    /** POST /public-apply/{token}/logout: ends this session. */
    public function logout(Request $request): Response
    {
        $this->sessions->end(self::session($request));

        return response()->noContent();
    }

    /** GET /public-apply/{token}/track: the whitelist only. */
    public function track(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->presenter->track(self::application($request)->fresh(), self::link($request))]);
    }

    /**
     * The code target: SMS to a Philippine mobile, its purpose, and for
     * tracking the reference.
     *
     * @param  array<string, mixed>  $extra
     * @return array{purpose: string, mobile: string, reference_number: ?string}
     */
    private function validateTarget(Request $request, array $extra = []): array
    {
        $input = $request->validate([
            'channel' => ['required', Rule::in(['sms'])],
            'mobile' => ['required', 'string', 'max:30', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || PhilippineMobile::normalise($value) === null) {
                    $fail('Enter a Philippine mobile number, like 0917 123 4567.');
                }
            }],
            'purpose' => ['required', Rule::in(['apply', 'resume', 'track'])],
            'reference_number' => ['nullable', 'required_if:purpose,track', 'string', 'max:30', 'regex:/^[A-Za-z0-9-]+$/'],
            ...$extra,
        ], [
            'channel.in' => 'Codes can only be sent by text message for now.',
            'reference_number.required_if' => 'Enter your application\'s reference number.',
        ]);

        return [
            'purpose' => $input['purpose'],
            'mobile' => (string) PhilippineMobile::normalise($input['mobile']),
            'reference_number' => $input['purpose'] === 'track' ? strtoupper(trim((string) $input['reference_number'])) : null,
        ];
    }

    private static function link(Request $request): OriginationLink
    {
        return $request->attributes->get(ResolveApplicationLink::ATTRIBUTE);
    }

    private static function session(Request $request): OriginationSession
    {
        return $request->attributes->get(RequireApplicationSession::SESSION);
    }

    private static function application(Request $request): OriginationApplication
    {
        return $request->attributes->get(RequireApplicationSession::APPLICATION);
    }
}
