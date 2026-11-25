<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanDocumentPrint;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Reprint after a change (finish run round 3, R.A. 3765): the disclosure
 * statement and the promissory note are printed for the member to sign, so
 * a loan must not be released on a signed copy whose terms no longer match.
 *
 * Each print records the terms its paper shows ({@see record()}). A printed
 * document is out of date when all of these hold:
 *
 * - the loan is `approved` (documents print from Approved on, and once the
 *   loan is released the release has settled its terms);
 * - it has a print to compare: the latest print of that document that carries
 *   the current {@see LoanDocumentTerms::VERSION} and a fingerprint;
 * - the fingerprint of the terms now isn't null and differs from that print's.
 *
 * So a document never printed, a print from before this change (no row), and
 * prints only under another version or with no terms (the document couldn't be
 * built) block nothing. Nor do they clear a hold: a newer print under another
 * version, or one with no terms, isn't compared, so the print before it still
 * is.
 */
final class LoanDocumentPrints
{
    /** The release refusal's name for each document, mid-sentence. */
    private const NAMES = [
        LoanDocumentPrint::DISCLOSURE => 'disclosure statement',
        LoanDocumentPrint::PROMISSORY_NOTE => 'promissory note',
    ];

    public function __construct(private LoanDocumentTerms $terms) {}

    /**
     * One print of `$document`, with the terms its paper shows: `$printedTerms`
     * as the document's payload sent them (GET /loans/{loan}/disclosure or
     * /promissory-note), stored as they are; or, when a print sends none (a tab
     * opened before the payload carried them), the terms it states now.
     *
     * @param  array{fingerprint: string, version: int}|null  $printedTerms
     */
    public function record(Loan $loan, string $document, User $printedBy, ?array $printedTerms = null): LoanDocumentPrint
    {
        return LoanDocumentPrint::query()->create([
            'loan_id' => $loan->getKey(),
            'document' => $document,
            'terms_fingerprint' => $printedTerms === null ? $this->terms->fingerprint($loan, $document) : $printedTerms['fingerprint'],
            'terms_version' => $printedTerms === null ? LoanDocumentTerms::VERSION : $printedTerms['version'],
            'printed_by' => $printedBy->getKey(),
            'printed_at' => now(),
        ]);
    }

    /**
     * The printed documents whose terms changed since they were printed, in
     * the order of LoanDocumentPrint::LABELS. Empty unless the loan is
     * approved. The terms are worked out only for an approved loan with a
     * print to compare (comparablePrints()), and only for that print's
     * document.
     *
     * @return list<array{document: string, label: string, printed_at: Carbon}>
     */
    public function outdated(Loan $loan): array
    {
        if ($loan->status !== 'approved') {
            return [];
        }

        $comparable = $this->comparablePrints($loan);
        $outdated = [];

        foreach (LoanDocumentPrint::LABELS as $document => $label) {
            $print = $comparable->get($document);

            if ($print === null) {
                continue;
            }

            $now = $this->terms->fingerprint($loan, $document);

            if ($now === null || hash_equals($print->terms_fingerprint, $now)) {
                continue;
            }

            $outdated[] = ['document' => $document, 'label' => $label, 'printed_at' => $print->printed_at];
        }

        return $outdated;
    }

    /**
     * The release gate: refuse while a printed document is out of date. Run
     * by LoanService::release() under the loan's lock, after its status is
     * read again, before anything is written.
     *
     * @throws ValidationException 422 on `documents`, naming each document and
     *                             the day it was printed (Manila)
     */
    public function assertNoneOutdated(Loan $loan): void
    {
        $outdated = $this->outdated($loan);

        if ($outdated !== []) {
            throw ValidationException::withMessages(['documents' => [self::refusal($outdated)]]);
        }
    }

    /**
     * "The loan's terms changed after the disclosure statement was printed on
     * November 25, 2026. Print it again and have the member sign the new copy
     * before releasing." Both documents are named in one sentence.
     *
     * @param  non-empty-list<array{document: string, label: string, printed_at: Carbon}>  $outdated
     */
    public static function refusal(array $outdated): string
    {
        $printed = array_map(
            fn (array $item): array => [self::NAMES[$item['document']], $item['printed_at']->format('F j, Y')],
            $outdated,
        );

        if (count($printed) === 1) {
            [[$name, $day]] = $printed;

            return "The loan's terms changed after the {$name} was printed on {$day}. "
                .'Print it again and have the member sign the new copy before releasing.';
        }

        [[$firstName, $firstDay], [$secondName, $secondDay]] = $printed;

        return "The loan's terms changed after the {$firstName} was printed on {$firstDay} and the {$secondName} on {$secondDay}. "
            .'Print them again and have the member sign the new copies before releasing.';
    }

    /**
     * The print each document is compared with, keyed by document: its latest
     * print under the current VERSION that carries a fingerprint. One query for
     * the loan, on the (loan, document, id) index, newest first; the first row
     * of each document is kept. A loan has a handful of prints, so reading them
     * all costs nothing.
     *
     * @return Collection<string, LoanDocumentPrint>
     */
    private function comparablePrints(Loan $loan): Collection
    {
        return LoanDocumentPrint::query()
            ->where('loan_id', $loan->getKey())
            ->where('terms_version', LoanDocumentTerms::VERSION)
            ->whereNotNull('terms_fingerprint')
            ->orderByDesc('id')
            ->get()
            ->unique('document')
            ->keyBy('document');
    }
}
