<?php

namespace App\Services\Cic\Records;

use App\Models\Borrower;
use App\Models\CicCapture;
use App\Models\CicCaptureRecord;
use App\Services\Cic\CicLayouts;
use App\Services\Cic\CicValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the CIC record screens read, from a capture or from a live build:
 * records with their issue counts, one record's fields and issues, and the
 * issue list. Member names and codes, and field values, are added only for a
 * caller who may see personal data (the controllers decide; this never
 * returns them unasked).
 *
 * A live build, and a capture's validation, is made once per request (the
 * reader is a request-scoped instance), never once per row.
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

    /** @return array{set: CicRecordSet, issues: list<Issue>} */
    public function live(Carbon $reference): array
    {
        $key = $reference->toDateString();

        if (! isset($this->live[$key])) {
            $set = $this->builder->build($reference);
            $this->live[$key] = ['set' => $set, 'issues' => $this->validator->issuesForSet($set)];
        }

        return $this->live[$key];
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
