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
 * Each print records the terms the document stated ({@see record()}). A
 * printed document is out of date when all of these hold:
 *
 * - the loan is `approved` (documents print from Approved on, and once the
 *   loan is released the release has settled its terms);
 * - the latest print of that document carries the current
 *   {@see LoanDocumentTerms::VERSION} and a fingerprint;
 * - the fingerprint of the terms now isn't null and differs from it.
 *
 * So a document never printed, a print from before this change (no row), a
 * print under another version and one whose document couldn't be built block
 * nothing.
 */
final class LoanDocumentPrints
{
    /** The release refusal's name for each document, mid-sentence. */
    private const NAMES = [
        LoanDocumentPrint::DISCLOSURE => 'disclosure statement',
        LoanDocumentPrint::PROMISSORY_NOTE => 'promissory note',
    ];

    public function __construct(private LoanDocumentTerms $terms) {}

    /** One print of `$document`, with the terms it states now. */
    public function record(Loan $loan, string $document, User $printedBy): LoanDocumentPrint
    {
        return LoanDocumentPrint::query()->create([
            'loan_id' => $loan->getKey(),
            'document' => $document,
            'terms_fingerprint' => $this->terms->fingerprint($loan, $document),
            'terms_version' => LoanDocumentTerms::VERSION,
            'printed_by' => $printedBy->getKey(),
            'printed_at' => now(),
        ]);
    }

    /**
     * The printed documents whose terms changed since they were printed, in
     * the order of LoanDocumentPrint::LABELS. Empty unless the loan is
     * approved. The terms are worked out only for an approved loan with a
     * print to compare, and only for that print's document.
     *
     * @return list<array{document: string, label: string, printed_at: Carbon}>
     */
    public function outdated(Loan $loan): array
    {
        if ($loan->status !== 'approved') {
            return [];
        }

        $latest = $this->latestPrints($loan);
        $outdated = [];

        foreach (LoanDocumentPrint::LABELS as $document => $label) {
            $print = $latest->get($document);

            if ($print === null || $print->terms_version !== LoanDocumentTerms::VERSION || $print->terms_fingerprint === null) {
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
     * The latest print of each document for `$loan`, keyed by document: one
     * query on the (loan, document, id) index.
     *
     * @return Collection<string, LoanDocumentPrint>
     */
    private function latestPrints(Loan $loan): Collection
    {
        return LoanDocumentPrint::query()
            ->whereIn('id', LoanDocumentPrint::query()
                ->selectRaw('MAX(id)')
                ->where('loan_id', $loan->getKey())
                ->groupBy('document'))
            ->get()
            ->keyBy('document');
    }
}
