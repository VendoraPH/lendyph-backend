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
 *
 * The loan page also gets when each document was last printed, any print
 * ({@see forLoanPage()}): the Release dialog reminds the cashier to print it
 * again after release when an insurance premium or a co-maker is added there,
 * which never holds the release (finish run round 4).
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
     * What GET /loans/{id} shows of the prints, both lists from one read of
     * the loan's prints (printsOf()), in the order of LoanDocumentPrint::LABELS:
     *
     * - `printed`: the latest print of each document, whatever its terms
     *   version and whether it carries terms, since it only says when the paper
     *   was printed (finish run round 4: the Release dialog reminds the cashier
     *   to print it again after a change made there);
     * - `outdated`: as outdated().
     *
     * Both are empty, with nothing read, unless the loan is approved.
     *
     * @return array{printed: list<array{document: string, label: string, printed_at: Carbon}>, outdated: list<array{document: string, label: string, printed_at: Carbon}>}
     */
    public function forLoanPage(Loan $loan): array
    {
        if ($loan->status !== 'approved') {
            return ['printed' => [], 'outdated' => []];
        }

        $prints = $this->printsOf($loan);

        return [
            'printed' => self::listed($prints->unique('document')->keyBy('document')),
            'outdated' => $this->outdatedAmong($loan, $prints),
        ];
    }

    /**
     * The printed documents whose terms changed since they were printed, in
     * the order of LoanDocumentPrint::LABELS. Empty unless the loan is
     * approved. The terms are worked out only for an approved loan with a
     * print to compare (comparable()), and only for that print's document.
     *
     * @return list<array{document: string, label: string, printed_at: Carbon}>
     */
    public function outdated(Loan $loan): array
    {
        return $this->forLoanPage($loan)['outdated'];
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
     * The printed documents among `$prints` (printsOf()) whose terms changed
     * since their comparable print, as outdated() describes.
     *
     * @param  Collection<int, LoanDocumentPrint>  $prints
     * @return list<array{document: string, label: string, printed_at: Carbon}>
     */
    private function outdatedAmong(Loan $loan, Collection $prints): array
    {
        $changed = self::comparable($prints)->filter(function (LoanDocumentPrint $print) use ($loan): bool {
            $now = $this->terms->fingerprint($loan, $print->document);

            return $now !== null && ! hash_equals($print->terms_fingerprint, $now);
        });

        return self::listed($changed);
    }

    /**
     * Every print of the loan, newest first: one query on the (loan, document,
     * id) index, shared by both lists of forLoanPage(). A loan has a handful of
     * prints, so reading them all costs nothing.
     *
     * @return Collection<int, LoanDocumentPrint>
     */
    private function printsOf(Loan $loan): Collection
    {
        return LoanDocumentPrint::query()
            ->where('loan_id', $loan->getKey())
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The print each document is compared with, keyed by document: its latest
     * print under the current VERSION that carries a fingerprint. `$prints`
     * are newest first, so the first of each document is kept.
     *
     * @param  Collection<int, LoanDocumentPrint>  $prints
     * @return Collection<string, LoanDocumentPrint>
     */
    private static function comparable(Collection $prints): Collection
    {
        return $prints
            ->filter(fn (LoanDocumentPrint $print): bool => $print->terms_version === LoanDocumentTerms::VERSION
                && $print->terms_fingerprint !== null)
            ->unique('document')
            ->keyBy('document');
    }

    /**
     * One print per document, keyed by document, as the items GET /loans/{id}
     * sends, in the order of LoanDocumentPrint::LABELS.
     *
     * @param  Collection<string, LoanDocumentPrint>  $byDocument
     * @return list<array{document: string, label: string, printed_at: Carbon}>
     */
    private static function listed(Collection $byDocument): array
    {
        $items = [];

        foreach (LoanDocumentPrint::LABELS as $document => $label) {
            $print = $byDocument->get($document);

            if ($print !== null) {
                $items[] = ['document' => $document, 'label' => $label, 'printed_at' => $print->printed_at];
            }
        }

        return $items;
    }
}
