<?php

namespace App\Http\Requests\LoanOrigination\Staff;

use App\Models\OriginationDocument;
use App\Services\LoanOrigination\HandOff\MemberDocuments;
use App\Services\LoanOrigination\Staff\DocumentReview;
use App\Services\LoanOrigination\Staff\StaffAccess;

/**
 * A staff decision on a document: verify takes optional `notes`; reject and
 * request-replacement need a `reason`, which the applicant's message carries
 * for a replacement. Verifying an ID (a document the hand-off files as the
 * member's valid ID) also takes its `id_type`, `custom_type_name` for
 * "others", and `id_number`, read off the ID (LO-B4, MemberDocuments).
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
        if (! $this->verifies()) {
            return ['reason' => ['required', 'string', 'max:1000']];
        }

        return [
            'notes' => ['nullable', 'string', 'max:1000'],
            ...($this->verifiesAnId() ? MemberDocuments::rules() : []),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.required' => 'Say why, so the applicant knows what to send.', ...MemberDocuments::messages()];
    }

    /**
     * The ID type and number a verified ID records, or null for any other
     * decision or document.
     *
     * @return array{type: string, custom_type_name: ?string, id_number: string}|null
     */
    public function idDetails(): ?array
    {
        return $this->verifiesAnId() ? MemberDocuments::fromInput($this->validated()) : null;
    }

    private function verifies(): bool
    {
        return str_ends_with($this->path(), '/verify');
    }

    private function verifiesAnId(): bool
    {
        return $this->verifies() && MemberDocuments::isId($this->document()->type);
    }

    public function text(): ?string
    {
        $text = $this->validated('notes') ?? $this->validated('reason');

        return blank($text) ? null : (string) $text;
    }
}
