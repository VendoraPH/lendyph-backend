<?php

namespace App\Services\CsvImport;

use App\Models\CsvImportFile;
use App\Models\CsvImportRun;
use App\Models\LoanProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Answers "which LoanProduct does each legacy product string become?" for a
 * staged import run.
 *
 * This is the one gate in the import that a human MUST close. `loans` has a NOT
 * NULL foreign key to `loan_products`, `loan_products.name` is not unique, and
 * nothing anywhere in this codebase looks a product up by name — so a product
 * string that does not resolve is a row that cannot be written at all. The
 * mapping is therefore blocking by construction, not by policy.
 *
 * The two things this class deliberately does NOT do:
 *
 *  1. FUZZY MATCHING. A suggestion is an exact match after case folding and
 *     whitespace collapsing, and nothing else. "Regular" does not suggest
 *     "Regular Loan", and a string that matches two products suggests neither.
 *     Silently landing a cohort on the wrong product re-prices every loan in it
 *     — different rate, different method, different penalty — and the resulting
 *     loans look completely ordinary. A blocked import is recoverable in
 *     minutes; a mis-priced one is discovered by members.
 *  2. CREATING A PRODUCT. A bare name carries no rate, no interest method, no
 *     term, no fees, no penalty rate and no grace period. Inventing a product
 *     from one means inventing all six, and every loan mapped to it inherits
 *     the invention.
 */
class ProductMappingResolver
{
    /**
     * Rows read per chunk during the compatibility pass. The pass projects five
     * scalars per row server-side rather than hauling the `raw`/`normalized`
     * JSON blobs across the wire, so the chunk is small regardless of how wide
     * the staged rows are.
     */
    private const SCAN_CHUNK = 1000;

    /**
     * Most distinct product strings reported for one run.
     *
     * A healthy file has one to a handful — the target cooperative has exactly
     * one product. A file whose columns are shifted has one distinct "product"
     * PER ROW, because the cell being read is somebody's phone number. That is
     * the worst outcome this package exists to catch, and it is also the one
     * that would otherwise turn this endpoint into a per-row JSON response, a
     * per-row accumulator array and a per-row O(entries x products) suggestion
     * loop. Past this cap the file is not mappable and saying so is the correct
     * operator answer, so the response is truncated and the PUT refuses.
     */
    public const MAX_CSV_PRODUCT_STRINGS = 500;

    /**
     * The whole mapping payload for a run: what the file says, what this system
     * offers, what has been confirmed, and what will go wrong if it is.
     *
     * @return array<string, mixed>
     */
    public function payload(CsvImportRun $run): array
    {
        $loansFile = $run->loansFile;
        $products = $this->products();
        $confirmed = self::normalizeStoredMapping($run->product_mapping);

        $entries = $loansFile ? $this->distinctProductStrings($loansFile) : [];

        foreach ($entries as $key => $entry) {
            $suggestion = $this->suggest($entry['csv_value'], $products);

            $entries[$key] = $entry + [
                'suggested_loan_product_id' => $suggestion['loan_product_id'],
                'suggestion_reason' => $suggestion['reason'],
                'suggestion_candidate_ids' => $suggestion['candidate_ids'],
                'mapped_loan_product_id' => $confirmed[$entry['csv_value']] ?? null,
            ];
        }

        /**
         * Compatibility is measured against the product a row would ACTUALLY
         * land on: the confirmed mapping when there is one, otherwise the
         * suggestion. With neither there is nothing to compare against, and the
         * entry reports `compatibility: null` rather than a reassuring zero.
         */
        $effective = [];

        foreach ($entries as $entry) {
            $productId = $entry['mapped_loan_product_id'] ?? $entry['suggested_loan_product_id'];

            if ($productId !== null && $products->has($productId)) {
                $effective[$entry['csv_value']] = $productId;
            }
        }

        /**
         * No compatibility pass on a truncated set. It is another full walk of
         * the loans file, and against a file with 500+ distinct "products" the
         * answer is not a warning per cohort — it is that the Loan Product
         * column is not the Loan Product column.
         */
        $truncated = self::isTruncated($entries);

        $compatibility = $loansFile && $effective !== [] && ! $truncated
            ? $this->compatibility($loansFile, $effective, $products)
            : [];

        foreach ($entries as $key => $entry) {
            $entries[$key]['compatibility'] = $compatibility[$entry['csv_value']] ?? null;
        }

        $entries = array_values($entries);
        $unmapped = $this->unmapped($entries, $confirmed);

        return [
            'run_id' => $run->id,
            'phase' => $run->phase,
            'loans_file_staged' => $loansFile !== null && $entries !== [],
            'mapping_complete' => $entries !== [] && $unmapped === [] && ! $truncated,
            'csv_products_truncated' => $truncated,
            'csv_product_string_cap' => self::MAX_CSV_PRODUCT_STRINGS,
            'unmapped' => $unmapped,
            'confirmed_mapping' => $confirmed === [] ? null : $confirmed,
            'csv_products' => $entries,
            'loan_products' => $products->map(fn (LoanProduct $p): array => $this->productPayload($p))->values()->all(),
            'totals' => $this->totals($entries),
        ];
    }

    /**
     * The distinct product strings in a run's staged loan rows, with counts.
     *
     * ONE grouped query. The product cell is pulled out of the JSON server-side
     * — `normalized` first because that value is already trimmed and is exactly
     * what the import pass will look the mapping up by, `raw` only as a fallback
     * for a row that never normalised.
     *
     * MySQL does not preserve JSON object key order, which is why
     * NormalizedRow::toPayload() stores `values` as a positional list; the
     * index below therefore comes from CsvImportSchema and is never written
     * down as a literal.
     *
     * @return array<string, array{csv_value: string, is_blank: bool, loan_count: int, valid_loan_count: int, invalid_loan_count: int, blocking: bool}>
     */
    public function distinctProductStrings(CsvImportFile $loansFile): array
    {
        $index = CsvImportSchema::indexOf(CsvImportSchema::LOANS, 'loan_product');

        $rows = DB::table('csv_import_rows')
            ->selectRaw(
                'COALESCE('
                ."NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`normalized`, ?)), 'null'), "
                ."NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`raw`, ?)), 'null')"
                .') as csv_product, `status`, COUNT(*) as row_count',
                ['$.values['.$index.']', '$['.$index.']'],
            )
            ->where('csv_import_file_id', $loansFile->id)
            ->groupBy('csv_product', 'status')
            /**
             * Most-used first, so a truncated set keeps the strings that are
             * actually products and drops the garbage. The limit is twice the
             * cap plus slack because a string produces up to two rows here —
             * one per `status` — and the merge below collapses them.
             */
            ->orderByRaw('COUNT(*) DESC')
            ->limit(self::MAX_CSV_PRODUCT_STRINGS * 2 + 2)
            ->get();

        $entries = [];

        foreach ($rows as $row) {
            /**
             * Re-keyed in PHP rather than trusted from the GROUP BY. The
             * `normalized` value is already trimmed but the `raw` fallback is
             * not, so " Regular Loan" and "Regular Loan" arrive as two SQL
             * groups and are one product string. Collapsing them here — with
             * exactly ValueNormalizer::text()'s rule — makes the key the import
             * pass will look up.
             */
            $value = self::stagedValue($row->csv_product);
            $count = (int) $row->row_count;

            $entries[$value] ??= [
                'csv_value' => $value,
                'is_blank' => $value === '',
                'loan_count' => 0,
                'valid_loan_count' => 0,
                'invalid_loan_count' => 0,
                'blocking' => false,
            ];

            $entries[$value]['loan_count'] += $count;

            if ($row->status === 'valid') {
                $entries[$value]['valid_loan_count'] += $count;
                /**
                 * Only a string carried by at least one importable row blocks
                 * the run. A string that appears solely on rows staging already
                 * rejected is still listed — the admin should see it — but
                 * demanding a product for cohorts that can never be written
                 * would make a file with one malformed line unmappable.
                 */
                $entries[$value]['blocking'] = true;
            } else {
                $entries[$value]['invalid_loan_count'] += $count;
            }
        }

        uasort($entries, fn (array $a, array $b): int => $b['loan_count'] <=> $a['loan_count']
            ?: strcmp($a['csv_value'], $b['csv_value']));

        return array_slice($entries, 0, self::MAX_CSV_PRODUCT_STRINGS, preserve_keys: true);
    }

    /**
     * Whether the distinct-string set hit the cap.
     *
     * A heuristic by construction — exactly MAX_CSV_PRODUCT_STRINGS distinct
     * products is indistinguishable from more — and that is fine, because a
     * file at this cap is not mappable either way. It reads as "too many to
     * be real", not as an exact count.
     *
     * @param  array<array-key, array<string, mixed>>  $entries
     */
    public static function isTruncated(array $entries): bool
    {
        return count($entries) >= self::MAX_CSV_PRODUCT_STRINGS;
    }

    /**
     * Exact, case-insensitive, whitespace-collapsed name match — or nothing.
     *
     * Two products sharing a name is not a tie to be broken, it is a question
     * only the admin can answer, so it yields no suggestion and both candidate
     * ids so the UI can show what the choice is between.
     *
     * @param  Collection<int, LoanProduct>  $products
     * @return array{loan_product_id: int|null, reason: string, candidate_ids: list<int>}
     */
    public function suggest(string $csvValue, Collection $products): array
    {
        if (self::canonical($csvValue) === '') {
            return ['loan_product_id' => null, 'reason' => 'blank_csv_value', 'candidate_ids' => []];
        }

        $needle = self::canonical($csvValue);

        $matches = $products
            ->filter(fn (LoanProduct $p): bool => self::canonical((string) $p->name) === $needle)
            ->keys()
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        return match (count($matches)) {
            0 => ['loan_product_id' => null, 'reason' => 'no_match', 'candidate_ids' => []],
            1 => ['loan_product_id' => $matches[0], 'reason' => 'exact_name_match', 'candidate_ids' => $matches],
            default => ['loan_product_id' => null, 'reason' => 'ambiguous_name', 'candidate_ids' => $matches],
        };
    }

    /**
     * What will be wrong with the loans each product string produces.
     *
     * Both findings below are WARNINGS and never errors, for the same reason:
     * the importer bypasses LoanService::createLoan(), so nothing downstream
     * will stop an out-of-bounds loan being written — and the coop this exists
     * for has exactly one product, ₱1,000–75,000 over 30 monthly periods.
     * Enforcing today's product configuration against a decade of legacy
     * balances would simply make the migration impossible. So they are counted,
     * reported loudly, and left to the admin.
     *
     * @param  array<string, int>  $effective  csv product string => loan_product_id
     * @param  Collection<int, LoanProduct>  $products
     * @return array<string, array<string, mixed>>
     */
    public function compatibility(CsvImportFile $loansFile, array $effective, Collection $products): array
    {
        $shape = CsvImportSchema::LOANS;
        $columns = [
            'csv_product' => 'loan_product',
            'csv_amount' => 'loan_amount',
            'csv_rate' => 'interest_rate',
            'csv_term' => 'term_in_months',
            'csv_interest_type' => 'interest_type',
        ];

        $selects = [];
        $bindings = [];

        foreach ($columns as $alias => $key) {
            $selects[] = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`normalized`, ?)), 'null') as {$alias}";
            $bindings[] = '$.values['.CsvImportSchema::indexOf($shape, $key).']';
        }

        $accumulators = [];

        foreach ($effective as $csvValue => $productId) {
            $accumulators[$csvValue] = $this->emptyAccumulator($products->get($productId));
        }

        DB::table('csv_import_rows')
            ->select('id', 'status')
            ->selectRaw(implode(', ', $selects), $bindings)
            ->where('csv_import_file_id', $loansFile->id)
            /**
             * chunkById, not chunk. `chunk()` pages with LIMIT/OFFSET, so the
             * last page of a million-row loans file makes MySQL walk and throw
             * away 999,000 rows to reach it — the whole pass is quadratic.
             * Keyset paging is also the only correct walk here: the import
             * processor updates these very rows, and an offset shifts under a
             * concurrent write.
             */
            ->chunkById(self::SCAN_CHUNK, function ($chunk) use (&$accumulators, $products, $effective) {
                foreach ($chunk as $row) {
                    $csvValue = self::stagedValue($row->csv_product);

                    if (! isset($accumulators[$csvValue])) {
                        continue;
                    }

                    $this->accumulate($accumulators[$csvValue], $row, $products->get($effective[$csvValue]));
                }
            });

        return array_map(fn (array $acc): array => $this->summariseAccumulator($acc), $accumulators);
    }

    /**
     * Which blocking strings the supplied mapping fails to cover.
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, int>  $mapping
     * @return list<string>
     */
    public function unmapped(array $entries, array $mapping): array
    {
        /**
         * Flipped to a lookup rather than scanned with in_array: both sides of
         * this comparison are caller-influenced — the entries come from the
         * file, the keys from the request body — so a linear scan inside a
         * filter is quadratic in two independent dimensions at once.
         * array_flip re-integerises numeric-string keys, and so does the
         * array_key_exists lookup, so the two stay consistent.
         */
        $covered = array_flip(self::mappingKeys($mapping));

        return array_values(array_map(
            fn (array $entry): string => (string) $entry['csv_value'],
            array_filter(
                $entries,
                fn (array $entry): bool => $entry['blocking'] === true
                    && ! array_key_exists((string) $entry['csv_value'], $covered),
            ),
        ));
    }

    /**
     * Every loan product on the box, keyed by id.
     *
     * Inactive products are included on purpose. A legacy cohort may genuinely
     * belong on a product that is no longer offered, and — more importantly —
     * an inactive product with a duplicate name still makes a name AMBIGUOUS.
     * Filtering it out of the candidate set would turn a question the admin has
     * to answer into a silent automatic choice.
     *
     * @return Collection<int, LoanProduct>
     */
    public function products(): Collection
    {
        return LoanProduct::query()->orderBy('name')->orderBy('id')->get()->keyBy('id');
    }

    /**
     * The staged form of a product cell: the exact string the import pass will
     * look the mapping up by.
     *
     * Mirrors ValueNormalizer::text() — strip BOM, fold NBSP to a space, trim —
     * because `normalized.values[loan_product]` is that function's output. A
     * blank cell becomes the empty-string key rather than being dropped: those
     * rows still need a product, `loans.loan_product_id` is NOT NULL, and
     * omitting them here would let the run start and fail row by row.
     */
    public static function stagedValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim(str_replace(["\u{FEFF}", "\u{00A0}"], ['', ' '], (string) $value));
    }

    /**
     * The comparison key for a suggestion: case folded, whitespace collapsed.
     *
     * Punctuation is deliberately NOT stripped, unlike CsvImportSchema::labelKey().
     * A header cell is a label whose spacing nobody can see; a product name is
     * data, and "Regular Loan" and "Regular-Loan" may well be two products.
     * Equating them is the fuzzy matching this class exists to refuse.
     */
    public static function canonical(string $value): string
    {
        $value = str_replace(["\u{FEFF}", "\u{00A0}"], ['', ' '], $value);
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return mb_strtolower(trim($value));
    }

    /**
     * A stored/submitted mapping as a string-keyed, int-valued array.
     *
     * PHP silently retypes a numeric-string array key to an integer, so a
     * product string of "2024" comes back out of json_decode() as int 2024 and
     * a strict comparison against the string "2024" from the database fails.
     * Every key is cast back to a string here so coverage checks cannot miss a
     * numerically-named product.
     *
     * @return array<string, int>
     */
    public static function normalizeStoredMapping(mixed $mapping): array
    {
        if (! is_array($mapping)) {
            return [];
        }

        $normalized = [];

        foreach ($mapping as $key => $value) {
            if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value) === 1)) {
                $normalized[(string) $key] = (int) $value;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, int>  $mapping
     * @return list<string>
     */
    public static function mappingKeys(array $mapping): array
    {
        return array_map('strval', array_keys($mapping));
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(LoanProduct $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'status' => $product->status,
            'interest_method' => $product->interest_method,
            'interest_rate' => (float) $product->interest_rate,
            'min_interest_rate' => $product->min_interest_rate === null ? null : (float) $product->min_interest_rate,
            'frequency' => $product->frequency,
            'term' => (int) $product->term,
            'min_term' => $product->min_term === null ? null : (int) $product->min_term,
            'max_term' => $product->max_term === null ? null : (int) $product->max_term,
            'min_amount' => (float) $product->min_amount,
            'max_amount' => (float) $product->max_amount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyAccumulator(?LoanProduct $product): array
    {
        return [
            'product' => $product,
            'rows' => 0,
            'evaluated' => 0,
            'unevaluated' => 0,
            'not_importable' => 0,
            'interest_method_mismatch' => 0,
            'interest_types' => [],
            'amount_below_min' => 0,
            'amount_above_max' => 0,
            'term_below_min' => 0,
            'term_above_max' => 0,
            'rate_below_min' => 0,
            'rate_above_max' => 0,
            'rows_out_of_bounds' => 0,
        ];
    }

    /**
     * Fold one staged loan row into its product string's counters.
     *
     * The bound checks are LoanService::createLoan()'s, deliberately: those are
     * the guards the importer skips, so these are the exact conditions nothing
     * else will catch.
     *
     * @param  array<string, mixed>  $acc
     */
    private function accumulate(array &$acc, object $row, ?LoanProduct $product): void
    {
        $acc['rows']++;

        if ($product === null) {
            $acc['unevaluated']++;

            return;
        }

        /**
         * A row staging already rejected will never be written, so counting it
         * towards "288 loans will disagree with their product" overstates the
         * warning by exactly the rows the admin is about to remove or fix. It is
         * counted apart instead — still visible, never folded into the number
         * the admin acts on.
         */
        if ($row->status !== 'valid') {
            $acc['not_importable']++;

            return;
        }

        /**
         * A row that never normalised has no typed values to compare, and the
         * error report already tells the admin why. Counting it as in-bounds
         * would be a lie; counting it as out-of-bounds would be a different
         * one. It is counted as unevaluated.
         */
        if ($row->csv_amount === null && $row->csv_term === null && $row->csv_rate === null && $row->csv_interest_type === null) {
            $acc['unevaluated']++;

            return;
        }

        $acc['evaluated']++;

        if ($row->csv_interest_type !== null) {
            $type = (string) $row->csv_interest_type;
            $acc['interest_types'][$type] = ($acc['interest_types'][$type] ?? 0) + 1;

            if ($type !== $product->interest_method) {
                $acc['interest_method_mismatch']++;
            }
        }

        $breaches = self::boundsBreaches($product, $row->csv_amount, $row->csv_term, $row->csv_rate);

        foreach ($breaches as $breach) {
            $acc[$breach]++;
        }

        if ($breaches !== []) {
            $acc['rows_out_of_bounds']++;
        }
    }

    /**
     * Which of LoanService::createLoan()'s bounds one loan breaks, if any.
     *
     * THE one implementation, called from two places on purpose:
     *
     *  - accumulate() above, which forecasts the damage on the mapping screen
     *    BEFORE the run — "288 loans will disagree with their product".
     *  - the importer, which stamps
     *    `ErrorReportBuilder::CATEGORY_OUT_OF_PRODUCT_BOUNDS` onto the row it
     *    just wrote, so the forecast can afterwards be expanded into the list of
     *    rows it actually turned out to be.
     *
     * If those two ever disagreed, the mapping screen would promise 288 and the
     * error report would list 291, and nobody could tell which was lying. They
     * cannot disagree while they are the same function.
     *
     * These are exactly the guards the importer skips by bypassing
     * LoanService::createLoan() — and it goes on skipping them. A migration has
     * to be able to carry a decade of loans that today's product configuration
     * would reject; this reports the breach, it does not prevent it.
     *
     * Tolerant of both the staged string form (JSON_UNQUOTE hands back strings)
     * and the typed form the importer holds, because the two callers have the
     * row in different shapes and a silent type mismatch here would simply stop
     * reporting breaches.
     *
     * @param  mixed  $amountCentavos  principal in INTEGER CENTAVOS, as staged;
     *                                 product bounds are in pesos
     * @param  mixed  $term  the CSV's stated Term in Months — NOT the period
     *                       count LoanScheduleReconstructor derives, which is
     *                       what `loans.term` becomes
     * @return list<string> breached bound names, empty when the loan is inside
     *                      every bound or too malformed to compare
     */
    public static function boundsBreaches(LoanProduct $product, mixed $amountCentavos, mixed $term, mixed $rate): array
    {
        $breaches = [];

        // Money is staged as integer centavos; product bounds are in pesos.
        if ($amountCentavos !== null && preg_match('/^-?\d+$/', (string) $amountCentavos) === 1) {
            $amount = ((int) $amountCentavos) / 100;
            $min = (float) $product->min_amount;
            $max = (float) $product->max_amount;

            if ($min > 0 && $amount < $min) {
                $breaches[] = 'amount_below_min';
            }

            if ($max > 0 && $amount > $max) {
                $breaches[] = 'amount_above_max';
            }
        }

        if ($term !== null && preg_match('/^-?\d+$/', (string) $term) === 1) {
            $months = (int) $term;
            $minTerm = (int) ($product->min_term ?? 1);
            $maxTerm = (int) ($product->max_term ?? $product->term);

            if ($months < $minTerm) {
                $breaches[] = 'term_below_min';
            }

            if ($maxTerm > 0 && $months > $maxTerm) {
                $breaches[] = 'term_above_max';
            }
        }

        if ($rate !== null && is_numeric((string) $rate)) {
            $value = (float) $rate;
            $minRate = (float) ($product->min_interest_rate ?? $product->interest_rate);
            $maxRate = (float) $product->interest_rate;

            if ($value < $minRate) {
                $breaches[] = 'rate_below_min';
            }

            if ($value > $maxRate) {
                $breaches[] = 'rate_above_max';
            }
        }

        return $breaches;
    }

    /**
     * @param  array<string, mixed>  $acc
     * @return array<string, mixed>
     */
    private function summariseAccumulator(array $acc): array
    {
        /** @var LoanProduct|null $product */
        $product = $acc['product'];

        arsort($acc['interest_types']);

        return [
            'checked_against_loan_product_id' => $product?->id,
            'checked_against_loan_product_name' => $product?->name,
            'rows' => $acc['rows'],
            'rows_evaluated' => $acc['evaluated'],
            'rows_unevaluated' => $acc['unevaluated'],
            'rows_not_importable' => $acc['not_importable'],
            'interest_method' => [
                /**
                 * The importer writes the CSV's interest method onto the loan —
                 * `interest_method` is $fillable, and the legacy contract is the
                 * truth of what the member actually signed. But
                 * LoanService::createLoan() hardcodes the method from the
                 * product, so an imported loan permanently disagrees with its
                 * own product, and DisclosureService::generateDisclosure()
                 * prints both in the same block: a statement will read "Regular
                 * Loan / straight" while the product screen says diminishing.
                 * Nothing in the system reconciles the two.
                 */
                'product_interest_method' => $product?->interest_method,
                'disagreeing_rows' => $acc['interest_method_mismatch'],
                'csv_interest_types' => $acc['interest_types'],
            ],
            'out_of_bounds' => [
                'rows' => $acc['rows_out_of_bounds'],
                'amount_below_min' => $acc['amount_below_min'],
                'amount_above_max' => $acc['amount_above_max'],
                /**
                 * The CSV's stated Term in Months, which is NOT what
                 * `loans.term` becomes — LoanScheduleReconstructor derives the
                 * period count from the release and maturity dates. This
                 * compares the number the admin sees in their own file.
                 */
                'term_below_min' => $acc['term_below_min'],
                'term_above_max' => $acc['term_above_max'],
                'rate_below_min' => $acc['rate_below_min'],
                'rate_above_max' => $acc['rate_above_max'],
            ],
        ];
    }

    /**
     * Run-wide roll-up, so the UI can headline "288 loans will disagree with
     * their product" without summing the per-string blocks itself.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, int>
     */
    private function totals(array $entries): array
    {
        $totals = [
            'csv_product_strings' => count($entries),
            'loan_rows' => 0,
            'rows_with_interest_method_disagreement' => 0,
            'rows_outside_product_bounds' => 0,
            'rows_not_compared' => 0,
        ];

        foreach ($entries as $entry) {
            $totals['loan_rows'] += (int) $entry['loan_count'];
            $compatibility = $entry['compatibility'] ?? null;

            if ($compatibility === null) {
                $totals['rows_not_compared'] += (int) $entry['loan_count'];

                continue;
            }

            $totals['rows_with_interest_method_disagreement'] += (int) $compatibility['interest_method']['disagreeing_rows'];
            $totals['rows_outside_product_bounds'] += (int) $compatibility['out_of_bounds']['rows'];
            $totals['rows_not_compared'] += (int) $compatibility['rows_unevaluated']
                + (int) $compatibility['rows_not_importable'];
        }

        return $totals;
    }
}
