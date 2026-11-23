<?php

namespace App\Http\Requests\LoanOrigination\Staff;

use App\Models\OriginationDocument;
use App\Services\LoanOrigination\Staff\DocumentReview;
use App\Services\LoanOrigination\Staff\StaffAccess;

/**
 * A staff decision on a document: verify takes optional `notes`; reject and
 * request-replacement need a `reason`, which the applicant's message carries
 * for a replacement.
 *
 * The document is found before the body is validated, so another branch's
 * document answers the same 404 as a missing one, never a 422 about the body.
 */
class DocumentDecisionRequest extends StaffWriteRequest
{
    public const NOT_FOUND = 'No such document.';

    private ?OriginationDocument $document = null;

    public function authorize(): bool
    {
        if (! StaffAccess::canUpdate($this->user())) {
            return false;
        }

        $this->document = DocumentReview::visibleTo($this->user())
            ->with(['currentVersion', 'versions'])
            ->find((int) $this->route('document'));

        abort_if($this->document === null, 404, self::NOT_FOUND);

        return true;
    }

    public function document(): OriginationDocument
    {
        return $this->document ?? abort(404, self::NOT_FOUND);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return str_ends_with($this->path(), '/verify')
            ? ['notes' => ['nullable', 'string', 'max:1000']]
            : ['reason' => ['required', 'string', 'max:1000']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.required' => 'Say why, so the applicant knows what to send.'];
    }

    public function text(): ?string
    {
        $text = $this->validated('notes') ?? $this->validated('reason');

        return blank($text) ? null : (string) $text;
    }
}
