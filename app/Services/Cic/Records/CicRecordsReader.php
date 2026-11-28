<?php

namespace App\Services\Cic\Records;

use App\Models\Borrower;
use App\Models\CicCapture;
use App\Models\CicCaptureRecord;
use App\Models\CicSetting;
use App\Services\Cic\CicIssueWords;
use App\Services\Cic\CicLayouts;
use App\Services\Cic\CicValidator;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What the CIC record screens read, from a capture or from a live build:
 * records with their issue counts, one record's fields and issues, and the
 * issue list. Member names and codes, and field values, are added only for a
 * caller who may see personal data (the controllers decide; this never
 * returns them unasked).
 *
 * A live build, and a capture's validation, is made once per request (the
 * reader is a request-scoped instance), never once per row. A live build is
 * also cached for 60 seconds per reference date (security review S-M2),
 * encrypted, since it holds members' values; the key carries the CIC
 * settings, mappings and overrides, so changing one reads afresh. One
 * record's live view builds only that record's loans (liveRecord()).
 *
 * @phpstan-import-type Issue from CicValidator
 */
final class CicRecordsReader
{
    /** @var array<string, array{set: CicRecordSet, issues: list<array<string, mixed>>}> */
    private array $live = [];

    public function __construct(
        private readonly CicRecordBuilder $builder,
        private readonly CicValidator $validator,
    ) {}

    public const LIVE_SECONDS = 60;

    /** @return array{set: CicRecordSet, issues: list<Issue>} */
    public function live(Carbon $reference): array
    {
        $key = $reference->toDateString();

        if (isset($this->live[$key])) {
            return $this->live[$key];
        }

        $cacheKey = self::liveCacheKey($reference);
        $cached = Cache::get($cacheKey);

        if (is_string($cached)) {
            try {
                return $this->live[$key] = Crypt::decrypt($cached);
            } catch (DecryptException) {
                Cache::forget($cacheKey);
            }
        }

        $set = $this->builder->build($reference);
        $this->live[$key] = ['set' => $set, 'issues' => $this->validator->issuesForSet($set)];

        try {
            Cache::put($cacheKey, Crypt::encrypt($this->live[$key]), self::LIVE_SECONDS);
        } catch (Throwable) {
            // Too large for the cache store, or the store is down: the build
            // stands for this request, uncached.
        }

        return $this->live[$key];
    }

    /**
     * One record of a live build and its issues, or null when the build has
     * no such record. Builds only the loans that record can come from (the
     * contract's loan, or the member's loans as borrower or member co-maker),
     * with their subjects so cross-record checks hold; reads the cached full
     * build instead when there is one, and the full build when the key names
     * no loan or member directly.
     *
     * @return array{record: CicRecord, issues: list<Issue>}|null
     */
    public function liveRecord(Carbon $reference, string $type, string $key): ?array
    {
        $full = fn (): ?array => $this->recordIn($this->live($reference), $type, $key);

        if (isset($this->live[$reference->toDateString()]) || Cache::has(self::liveCacheKey($reference))) {
            return $full();
        }

        $loanIds = in_array($type, ['CI', 'CN'], true) ? self::contractLoanIds($key) : self::memberLoanIds($key);

        if ($loanIds === []) {
            return $full();
        }

        $set = $this->builder->build($reference, loanIds: $loanIds);

        return $this->recordIn(['set' => $set, 'issues' => $this->validator->issuesForSet($set)], $type, $key) ?? $full();
    }

    /**
     * @param  array{set: CicRecordSet, issues: list<Issue>}  $build
     * @return array{record: CicRecord, issues: list<Issue>}|null
     */
    private function recordIn(array $build, string $type, string $key): ?array
    {
        $record = collect($build['set']->ofType($type))->first(fn (CicRecord $r) => $r->key === $key);

        return $record === null ? null : [
            'record' => $record,
            'issues' => array_values(array_filter($build['issues'], fn (array $i) => $i['record_type'] === $type && $i['record_key'] === $key)),
        ];
    }

    /** @return list<int> the loans a contract key can name */
    private static function contractLoanIds(string $key): array
    {
        if (preg_match('/^loan-(\d+)$/', $key, $match) === 1) {
            return [(int) $match[1]];
        }

        return DB::table('loans')
            ->where(fn ($query) => $query->where('cic_contract_no', $key)->orWhere('loan_account_number', $key)
                ->orWhere('external_loan_no', $key)->orWhere('application_number', $key))
            ->pluck('id')->map(fn (mixed $id) => (int) $id)->all();
    }

    /** @return list<int> the loans of the member a subject key names, as borrower or member co-maker */
    private static function memberLoanIds(string $key): array
    {
        $memberIds = DB::table('borrowers')->where('borrower_code', $key)->pluck('id')
            ->merge(DB::table('cic_subjects')->where('subject_no', $key)->whereNotNull('borrower_id')->pluck('borrower_id'))
            ->map(fn (mixed $id) => (int) $id)->unique()->values()->all();

        if ($memberIds === []) {
            return [];
        }

        return DB::table('loans')
            ->where(fn ($query) => $query->whereIn('borrower_id', $memberIds)
                ->orWhereIn('id', DB::table('co_maker_loan')->join('co_makers', 'co_makers.id', '=', 'co_maker_loan.co_maker_id')
                    ->whereIn('co_makers.member_borrower_id', $memberIds)->select('co_maker_loan.loan_id')))
            ->pluck('id')->map(fn (mixed $id) => (int) $id)->all();
    }

    /**
     * The live cache key: the reference date and what a build reads besides
     * loans and members (settings, mappings, overrides, the latest month-end
     * capture), so a settings change is never served stale.
     */
    public static function liveCacheKey(Carbon $reference): string
    {
        $setting = CicSetting::current();
        // These tables are small (one row per product, mapped value or
        // overridden loan), so their rows themselves go into the key.
        $tables = collect(['cic_product_mappings', 'cic_loan_overrides', 'cic_code_mappings'])
            ->map(fn (string $table) => hash('sha256', DB::table($table)->orderBy('id')->get()->toJson()));
        $monthEnd = DB::table('cic_captures')->where('kind', CicCapture::KIND_MONTH_END)->where('status', CicCapture::STATUS_COMPLETE)->max('id');

        return 'cic:live:'.$reference->toDateString().':'.hash('sha256', json_encode([
            $setting->provider_code, (bool) $setting->is_mfi, (string) $setting->updated_at, $tables->all(), $monthEnd,
        ], JSON_THROW_ON_ERROR));
    }

    /** @var array<int, list<array<string, mixed>>> */
    private array $captureIssues = [];

    /** @return list<Issue> */
    public function captureIssues(CicCapture $capture): array
    {
        return $this->captureIssues[$capture->id] ??= $this->validator->issues($capture);
    }

    /**
     * Error and warning counts per record, keyed "type|key".
     *
     * @param  list<Issue>  $issues
     * @return array<string, array{errors: int, warnings: int}>
     */
    public static function countsByRecord(array $issues): array
    {
        $counts = [];

        foreach ($issues as $issue) {
            $key = "{$issue['record_type']}|{$issue['record_key']}";
            $counts[$key] ??= ['errors' => 0, 'warnings' => 0];
            $counts[$key][$issue['severity'] === 'error' ? 'errors' : 'warnings']++;
        }

        return $counts;
    }

    /**
     * Rows of a capture's records, in the file's order, one page of 25.
     *
     * @return array{rows: list<array{record_type: string, record_key: string, borrower_id: int|null}>, total: int}
     */
    public function captureRows(CicCapture $capture, ?string $type, int $page, int $perPage): array
    {
        $query = CicCaptureRecord::query()->where('capture_id', $capture->id)
            ->when($type !== null, fn ($q) => $q->where('record_type', $type));

        $total = (clone $query)->count();
        $records = $query
            ->orderByRaw("FIELD(record_type, 'ID', 'CI', 'CN', 'NE')")
            ->orderBy('record_key')
            ->forPage($page, $perPage)
            ->with(['subject:id,borrower_id', 'loan:id,borrower_id'])
            ->get(['id', 'record_type', 'record_key', 'cic_subject_id', 'loan_id']);

        return [
            'rows' => $records->map(fn (CicCaptureRecord $r) => [
                'record_type' => $r->record_type,
                'record_key' => $r->record_key,
                'borrower_id' => $r->subject?->borrower_id ?? $r->loan?->borrower_id,
            ])->all(),
            'total' => $total,
        ];
    }

    /**
     * One captured record's values and member, or null when the capture has
     * no such record.
     *
     * @return array{values: array<string, string>, borrower_id: int|null}|null
     */
    public function captureRecord(CicCapture $capture, string $type, string $key): ?array
    {
        $record = CicCaptureRecord::query()->where('capture_id', $capture->id)
            ->where('record_type', $type)->where('record_key', $key)
            ->with(['subject:id,borrower_id', 'loan:id,borrower_id'])
            ->first();

        return $record === null ? null : [
            'values' => $record->values,
            'borrower_id' => $record->subject?->borrower_id ?? $record->loan?->borrower_id,
        ];
    }

    /**
     * Members' names and codes, for the rows of a caller who may see them.
     *
     * @param  list<int|null>  $borrowerIds
     * @return array<int, array{name: string, code: string}>
     */
    public static function members(array $borrowerIds): array
    {
        $ids = array_values(array_unique(array_filter($borrowerIds)));

        if ($ids === []) {
            return [];
        }

        return Borrower::query()->whereKey($ids)->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'borrower_code'])
            ->mapWithKeys(fn (Borrower $b) => [$b->id => [
                'name' => CicFormat::name($b->first_name, $b->middle_name, $b->last_name, $b->suffix),
                'code' => (string) $b->borrower_code,
            ]])
            ->all();
    }

    /**
     * A record's fields in layout order, with each one's value when asked.
     *
     * @param  array<string, string>  $values
     * @return list<array<string, mixed>>
     */
    public static function fields(string $type, array $values, bool $withValues): array
    {
        return array_map(fn (array $field) => [
            'field_id' => $field['id'],
            'name' => $field['name'],
            'type' => $field['type'],
            'classification' => $field['classification'],
            'max_length' => $field['maxLength'],
            'domain' => $field['domain'],
            ...($withValues ? ['value' => $values[$field['id']] ?? ''] : []),
        ], CicLayouts::fields($type));
    }

    /**
     * An issue as the screens show it: the field's name and classification
     * from the layouts.
     *
     * @param  Issue  $issue
     * @return array<string, mixed>
     */
    public static function present(array $issue): array
    {
        $field = $issue['field_id'] === null ? null : CicLayouts::field($issue['field_id']);

        return [
            ...$issue,
            'code_label' => CicIssueWords::code((string) $issue['code']),
            'field_name' => $field['name'] ?? null,
            'classification' => $field['classification'] ?? null,
        ];
    }

    /**
     * Loan product ids and names of contracts whose product has no CIC
     * mapping, with how many contracts each holds back.
     *
     * @param  list<CicRecord>  $contracts
     * @return list<array{loan_product_id: int, name: string, contracts: int}>
     */
    public static function unmappedProducts(array $contracts): array
    {
        $loanIds = [];

        foreach ($contracts as $record) {
            foreach ($record->buildIssues as $issue) {
                if ($issue['code'] === 'UNMAPPED_PRODUCT' && $record->loanId !== null) {
                    $loanIds[] = $record->loanId;
                }
            }
        }

        if ($loanIds === []) {
            return [];
        }

        return DB::table('loans')->join('loan_products', 'loan_products.id', '=', 'loans.loan_product_id')
            ->whereIn('loans.id', $loanIds)
            ->groupBy('loan_products.id', 'loan_products.name')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->orderBy('loan_products.name')
            ->selectRaw('loan_products.id, loan_products.name, COUNT(*) as contracts')
            ->get()
            ->map(fn (object $row) => ['loan_product_id' => (int) $row->id, 'name' => (string) $row->name, 'contracts' => (int) $row->contracts])
            ->all();
    }
}
