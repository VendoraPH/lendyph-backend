<?php

namespace App\Services\Cic;

use App\Models\CicCapture;
use App\Models\CicCaptureRecord;
use App\Models\CicSetting;
use App\Services\Cic\Contracts\ValidatesCicCapture;
use App\Services\Cic\Records\CicFormat;
use App\Services\Cic\Records\CicRecord;
use App\Services\Cic\Records\CicRecordSet;
use Illuminate\Support\Facades\Cache;

/**
 * The server's authoritative CSDF 2016 checks, ported from frontend #699's
 * validate.ts and validate-rules.ts, and the records slice's own rules:
 *
 * - **Field:** type (X, N, D), maximum length, code list, fixed value and
 *   Mandatory. A missing NM field is never an issue; a missing D field is an
 *   error only while its condition applies (prompt §1.1, §10).
 * - **Dependent:** the workbook's conditions as validate-rules.ts encodes
 *   them (addresses, type/number pairs, contacts, contract phases,
 *   guarantees and linked subjects). Rule texts the generated layouts cut at
 *   400 characters (summary Q23) are implemented only as far as legible.
 * - **Cross-record:** every contract's member, guarantor and member co-maker,
 *   and every event's member, has an ID record in the file; subject numbers,
 *   contract numbers and record keys are unique.
 * - **Financial:** outstanding at least 0; Contract Start before the
 *   reference date, the planned end after the start, the actual end on or
 *   after it; overdue count, amount and days agree; an open loan with nothing
 *   owed is a warning.
 * - Plan decision 4: a subject with no Identification is a warning, not an
 *   error, until CIC answers Q4.
 *
 * Issues read `{record_type, record_key, field_id, severity, code, message}`,
 * errors first, with messages in words that hold no member values. The
 * provider code is checked once, on the header (HD2), from today's settings:
 * the file writes position 2 from them.
 *
 * @phpstan-type Issue array{record_type: string, record_key: string, field_id: string|null, severity: 'error'|'warning', code: string, message: string}
 * @phpstan-type Rec array{record_type: string, record_key: string, values: array<string, string>, build_issues: list<array{field_id: string|null, severity: string, code: string, message: string}>}
 */
final class CicValidator implements ValidatesCicCapture
{
    public const RECORD_CHUNK = 500;

    private const SUBJECT_TYPES = ['ID'];

    private const CONTRACT_TYPES = ['CI', 'CN'];

    /** Address block offsets: type, full, street, postal, subdivision, barangay, city, province, country. */
    private const ADDR = ['type' => 0, 'full' => 1, 'street' => 2, 'postal' => 3, 'subdivision' => 4, 'barangay' => 5, 'city' => 6, 'province' => 7, 'country' => 8];

    /** @var array<string, list<array{label: string, base: int, required: string, expected: string|null}>> required: always|non_mfi|never */
    private const ADDRESS_BLOCKS = [
        'ID' => [
            ['label' => 'Address 1', 'base' => 32, 'required' => 'always', 'expected' => 'MI'],
            ['label' => 'Address 2', 'base' => 43, 'required' => 'non_mfi', 'expected' => 'AI'],
            ['label' => 'Sole Trader address 1', 'base' => 94, 'required' => 'never', 'expected' => null],
            ['label' => 'Sole Trader address 2', 'base' => 105, 'required' => 'never', 'expected' => null],
        ],
    ];

    /** @var array<string, list<array{0: int, 1: int}>> fields filled together or not at all */
    private const PAIRS = [
        'ID' => [[54, 55], [56, 57], [58, 59], [60, 61], [66, 67], [72, 73], [78, 79], [80, 81], [116, 117], [118, 119], [120, 121], [122, 123]],
        'CI' => [[31, 32], [33, 34]],
    ];

    /** @var array<string, array{whenOpened: list<int>, whenActive: list<int>, emptyWhenClosed: list<int>, emptyWhenNotGranted: list<int>, guarantees: int, linked: int, outstanding: int, overdueAmount: int}> */
    private const CONTRACTS = [
        'CI' => ['whenOpened' => [13, 15, 26, 28, 35], 'whenActive' => [31, 32], 'emptyWhenClosed' => [31, 32], 'emptyWhenNotGranted' => [33, 34], 'guarantees' => 42, 'linked' => 126, 'outstanding' => 32, 'overdueAmount' => 34],
        'CN' => ['whenOpened' => [13, 15, 23], 'whenActive' => [], 'emptyWhenClosed' => [], 'emptyWhenNotGranted' => [], 'guarantees' => 26, 'linked' => 110, 'outstanding' => 23, 'overdueAmount' => 24],
    ];

    private const PHASE_LABEL = ['RQ' => 'Requested', 'RN' => 'Renounced', 'RF' => 'Refused', 'AC' => 'Active', 'CL' => 'Closed', 'CA' => 'Closed in advance'];

    /** How long a complete capture's issues are kept (a capture never changes; settings are in the key). */
    public const CACHE_SECONDS = 86400;

    /**
     * A capture's issues. A complete capture's are cached by its id and
     * checksum, and the settings they're checked against (security review
     * S-M2): a complete capture never changes.
     *
     * @return list<Issue>
     */
    public function issues(CicCapture $capture): array
    {
        if (! $capture->isComplete() || $capture->checksum === null) {
            return $this->captureIssues($capture);
        }

        $setting = CicSetting::current();
        $key = sprintf('cic:validation:%d:%s:%s', $capture->id, $capture->checksum, hash('sha256', json_encode([$setting->provider_code, (bool) $setting->is_mfi], JSON_THROW_ON_ERROR)));

        return Cache::remember($key, self::CACHE_SECONDS, fn () => $this->captureIssues($capture));
    }

    /** @return list<Issue> */
    private function captureIssues(CicCapture $capture): array
    {
        $setting = CicSetting::current();
        $context = ['mfi' => (bool) $setting->is_mfi, 'provider_code' => CicFormat::text($setting->provider_code), 'reference_date' => CicFormat::date($capture->reference_date)];

        $subjectKeys = CicCaptureRecord::query()->where('capture_id', $capture->id)->whereIn('record_type', self::SUBJECT_TYPES)
            ->pluck('record_key')->all();

        $records = (function () use ($capture) {
            foreach (CicCapture::RECORD_TYPES as $type) {
                $query = CicCaptureRecord::query()->where('capture_id', $capture->id)->where('record_type', $type)
                    ->select(['id', 'record_type', 'record_key', 'values', 'build_issues']);

                foreach ($query->lazyById(self::RECORD_CHUNK) as $row) {
                    yield [
                        'record_type' => $row->record_type,
                        'record_key' => $row->record_key,
                        'values' => $row->values,
                        'build_issues' => $row->build_issues ?? [],
                    ];
                }
            }
        })();

        return $this->validate($records, $subjectKeys, $context, self::leftOut($capture->left_out ?? []));
    }

    /**
     * The issues of a live build (readiness and the live record screens).
     *
     * @return list<Issue>
     */
    public function issuesForSet(CicRecordSet $set): array
    {
        $context = ['mfi' => $set->context->mfi, 'provider_code' => $set->context->providerCode, 'reference_date' => $set->context->referenceDate];
        $subjectKeys = array_map(fn (CicRecord $r) => $r->key, $set->ofType('ID'));

        $records = (function () use ($set) {
            foreach ($set->all() as $record) {
                yield ['record_type' => $record->type, 'record_key' => $record->key, 'values' => $record->values, 'build_issues' => $record->buildIssues];
            }
        })();

        return $this->validate($records, $subjectKeys, $context, self::leftOut($set->leftOut));
    }

    /**
     * The loans a build left out of the file, as issues (they hold their
     * record type, key, field, severity, code and message already).
     *
     * @param  list<array<string, mixed>>  $leftOut
     * @return list<Issue>
     */
    private static function leftOut(array $leftOut): array
    {
        return array_map(fn (array $i) => self::issue((string) $i['record_type'], (string) $i['record_key'], $i['field_id'] ?? null,
            ($i['severity'] ?? 'warning') === 'error' ? 'error' : 'warning', (string) $i['code'], (string) $i['message']), array_values($leftOut));
    }

    /**
     * @param  iterable<Rec>  $records
     * @param  list<string>  $subjectKeys  every ID record's key (its subject number)
     * @param  array{mfi: bool, provider_code: string, reference_date: string}  $context
     * @param  list<Issue>  $leftOut  issues about loans the file leaves out
     * @return list<Issue>
     */
    public function validate(iterable $records, array $subjectKeys, array $context, array $leftOut = []): array
    {
        $issues = $leftOut;

        if (preg_match('/^[A-Za-z0-9]{8}$/', $context['provider_code']) !== 1) {
            $issues[] = self::issue('HD', '', 'HD2', 'error', 'PROVIDER_CODE', 'The CIC provider code must be exactly 8 letters or digits. Set it in CIC Settings.');
        }

        $subjects = array_fill_keys($subjectKeys, true);
        $seenKeys = [];
        $seenSubjects = [];
        $seenContracts = [];
        $count = 0;

        foreach ($records as $record) {
            $count++;
            $type = $record['record_type'];
            $key = $record['record_key'];
            $values = $record['values'];
            $push = function (?string $fieldId, string $severity, string $code, string $message) use (&$issues, $type, $key): void {
                $issues[] = self::issue($type, $key, $fieldId, $severity, $code, $message);
            };

            if (isset($seenKeys["{$type}|{$key}"])) {
                $push(null, 'error', 'DUPLICATE_RECORD', "This {$type} record is in the file more than once.");
            }
            $seenKeys["{$type}|{$key}"] = true;

            if (! CicLayouts::hasRecordType($type)) {
                $push(null, 'error', 'UNKNOWN_RECORD_TYPE', 'This record type is not part of the CIC format.');

                continue;
            }

            $this->recordIssues($type, $values, $record['build_issues'], $context, $push);

            $subjectNo = $values["{$type}5"] ?? '';

            if (in_array($type, self::SUBJECT_TYPES, true) && $subjectNo !== '') {
                if (isset($seenSubjects[$subjectNo])) {
                    $push("{$type}5", 'error', 'DUPLICATE_SUBJECT', 'This subject number is reported more than once.');
                }
                $seenSubjects[$subjectNo] = true;
            }

            if (in_array($type, self::CONTRACT_TYPES, true)) {
                $contractNo = $values["{$type}7"] ?? '';

                $flagged = in_array('DUPLICATE_CONTRACT', array_column($record['build_issues'], 'code'), true);

                if ($contractNo !== '' && ! $flagged) {
                    if (isset($seenContracts[$contractNo])) {
                        $push("{$type}7", 'error', 'DUPLICATE_CONTRACT', 'This contract number is reported more than once.');
                    }
                    $seenContracts[$contractNo] = true;
                }

                $this->crossRecordContract($type, $values, $subjects, $push);
            }

            if ($type === 'NE' && $subjectNo !== '' && ! isset($subjects[$subjectNo])) {
                $push('NE5', 'error', 'SUBJECT_NOT_IN_FILE', 'The member of this event has no ID record in the file.');
            }
        }

        if ($count === 0) {
            $issues[] = self::issue('FT', '', null, 'warning', 'EMPTY_FILE', 'The file has no records.');
        }

        usort($issues, fn (array $a, array $b) => ($a['severity'] === 'error' ? 0 : 1) <=> ($b['severity'] === 'error' ? 0 : 1));

        return $issues;
    }

    /**
     * One record against its layout, the dependent rules and the financial
     * checks.
     *
     * @param  array<string, string>  $values
     * @param  list<array{field_id: string|null, severity: string, code: string, message: string}>  $buildIssues
     * @param  array{mfi: bool, provider_code: string, reference_date: string}  $context
     */
    private function recordIssues(string $type, array $values, array $buildIssues, array $context, callable $push): void
    {
        $rules = $this->dependentRules($type, $values, $context['mfi']);
        $covered = [];

        foreach ($buildIssues as $issue) {
            $push($issue['field_id'], $issue['severity'] === 'error' ? 'error' : 'warning', $issue['code'], $issue['message']);

            if ($issue['severity'] === 'error' && $issue['field_id'] !== null) {
                $covered[$issue['field_id']] = true;
            }
        }

        $fileReference = CicFormat::dateKey($context['reference_date']);

        foreach (CicLayouts::fields($type) as $position => $field) {
            $id = $field['id'];

            // Position 2 is the provider code, checked once on the header.
            if ($position === 1 || isset($covered[$id])) {
                continue;
            }

            $value = $values[$id] ?? '';
            $name = $field['name'];

            if ($value === '') {
                if ($field['fixed'] !== null || $field['classification'] === 'M') {
                    $push($id, 'error', 'MISSING_MANDATORY', "{$name} is mandatory.");
                } elseif (isset($rules['warn'][$id])) {
                    $push($id, 'warning', $rules['warn'][$id][0], $rules['warn'][$id][1]);
                } elseif (isset($rules['required'][$id])) {
                    $push($id, 'error', 'MISSING_DEPENDENT', "{$name} is required because {$rules['required'][$id]}.");
                }

                continue;
            }

            // A control or invisible character (or bytes that aren't UTF-8)
            // could break the file's lines or fields (security review S-L4).
            if (preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $value) !== 0) {
                $push($id, 'error', 'CONTROL_CHARACTER', "{$name} holds a control or invisible character, or text that isn't valid, which the file can't carry. Correct it in Lendy.");

                continue;
            }

            if (isset($rules['empty'][$id])) {
                $push($id, 'error', 'MUST_BE_EMPTY', "{$name} must be left empty because {$rules['empty'][$id]}.");
            }

            if ($field['fixed'] !== null && $value !== $field['fixed']) {
                $push($id, 'error', 'FIXED_VALUE', "{$name} must be \"{$field['fixed']}\".");

                continue;
            }

            $bad = $this->checkValue($field, $value);

            if ($bad !== null) {
                $push($id, 'error', $bad[0], $bad[1]);

                continue;
            }

            if ($position === 3 && $field['type'] === 'D' && $fileReference !== null && (CicFormat::dateKey($value) ?? 0) > $fileReference) {
                $push($id, 'error', 'AFTER_FILE_REFERENCE_DATE', "{$name} is later than the File Reference Date.");
            }
        }

        foreach ($rules['findings'] as [$fieldId, $severity, $code, $message]) {
            $push($fieldId, $severity, $code, $message);
        }
    }

    /**
     * @param  array{id: string, name: string, type: string, maxLength: int, domain: string|null}  $field
     * @return array{0: string, 1: string}|null
     */
    private function checkValue(array $field, string $value): ?array
    {
        $name = $field['name'];

        if ($field['type'] === 'D' && CicFormat::dateKey($value) === null) {
            return ['INVALID_DATE', "{$name} is not a valid date (DDMMYYYY)."];
        }

        if ($field['type'] === 'N') {
            if (preg_match('/^-?\d+$/', $value) !== 1) {
                return ['INVALID_NUMBER', "{$name} is not a whole number."];
            }

            if (str_starts_with($value, '-')) {
                return ['NEGATIVE_NUMBER', "{$name} can't be negative."];
            }
        }

        if ($field['domain'] !== null && CicDomains::has($field['domain']) && ! CicDomains::contains($field['domain'], $value)) {
            return ['NOT_IN_DOMAIN', "{$name} \"{$value}\" is not a CIC code for this field."];
        }

        $length = mb_strlen($value);

        if ($length > $field['maxLength']) {
            return ['TOO_LONG', "{$name} is {$length} characters; CIC allows {$field['maxLength']}. It is never cut short: correct it in Lendy."];
        }

        return null;
    }

    /**
     * Every contract's member, guarantors and member co-makers must have an ID
     * record in the file.
     *
     * @param  array<string, string>  $values
     * @param  array<string, true>  $subjects
     */
    private function crossRecordContract(string $type, array $values, array $subjects, callable $push): void
    {
        $layout = self::CONTRACTS[$type];
        $member = $values["{$type}5"] ?? '';

        if ($member !== '' && ! isset($subjects[$member])) {
            $push("{$type}5", 'error', 'SUBJECT_NOT_IN_FILE', 'The member of this contract has no ID record in the file.');
        }

        for ($k = 0; $k < 6; $k++) {
            $guarantor = "{$type}".($layout['guarantees'] + 14 * $k + 1);

            if (($values[$guarantor] ?? '') !== '' && ! isset($subjects[$values[$guarantor]])) {
                $push($guarantor, 'error', 'GUARANTOR_NOT_IN_FILE', 'Guarantee '.($k + 1).': the guarantor has no ID record in the file.');
            }

            $linked = "{$type}".($layout['linked'] + 3 * $k);

            if (($values[$linked] ?? '') !== '' && ! isset($subjects[$values[$linked]])) {
                $push($linked, 'error', 'LINKED_SUBJECT_NOT_IN_FILE', 'Linked subject '.($k + 1).': the co-maker has no ID record in the file.');
            }
        }
    }

    /**
     * The dependent conditions (validate-rules.ts), plus the financial checks.
     *
     * @param  array<string, string>  $values
     * @return array{required: array<string, string>, empty: array<string, string>, warn: array<string, array{0: string, 1: string}>, findings: list<array{0: string|null, 1: string, 2: string, 3: string}>}
     */
    private function dependentRules(string $type, array $values, bool $mfi): array
    {
        $out = ['required' => [], 'empty' => [], 'warn' => [], 'findings' => []];
        $has = fn (string $id) => ($values[$id] ?? '') !== '';

        foreach (self::ADDRESS_BLOCKS[$type] ?? [] as $block) {
            $f = fn (string $part) => $type.($block['base'] + self::ADDR[$part]);
            $required = $block['required'] === 'always' || ($block['required'] === 'non_mfi' && ! $mfi);
            $anySplit = false;

            foreach (['street', 'postal', 'subdivision', 'barangay', 'city', 'province', 'country'] as $part) {
                $anySplit = $anySplit || $has($f($part));
            }

            if (! $required && ! $anySplit && ! $has($f('type')) && ! $has($f('full'))) {
                continue;
            }

            $why = $required ? "{$block['label']} must be reported" : "part of {$block['label']} is filled in";
            $out['required'][$f('type')] = $why;

            if (! $has($f('full'))) {
                $unlessFull = "{$why}, and no FullAddress is given";
                $out['required'][$f('city')] = $unlessFull;
                $out['required'][$f('province')] = $unlessFull;
                $out['required'][$mfi ? $f('subdivision') : $f('street')] = $unlessFull;
            } elseif ($anySplit) {
                $out['findings'][] = [$f('full'), 'warning', 'FULL_ADDRESS_IGNORED', "{$block['label']}: FullAddress and the separate address fields are both filled in; CIC ignores FullAddress."];
            }

            $addressType = strtoupper($values[$f('type')] ?? '');

            if ($block['expected'] !== null && $addressType !== '' && $addressType !== $block['expected']) {
                $out['findings'][] = [$f('type'), 'warning', 'ADDRESS_TYPE_UNEXPECTED', "{$block['label']} should be address type {$block['expected']}, not {$addressType}."];
            }
        }

        foreach (self::PAIRS[$type] ?? [] as [$a, $b]) {
            [$ia, $ib] = ["{$type}{$a}", "{$type}{$b}"];

            if ($has($ia) && ! $has($ib)) {
                $out['required'][$ib] = "{$ia} is filled in";
            }

            if ($has($ib) && ! $has($ia)) {
                $out['required'][$ia] = "{$ib} is filled in";
            }
        }

        if ($type === 'ID') {
            if (! $has('ID54') && ! $has('ID56') && ! $has('ID58')) {
                $out['warn']['ID54'] = ['NO_IDENTIFICATION', 'No identification (TIN, SSS, GSIS, PhilHealth, Senior Citizen or UMID) is on file for this member. CIC asks for at least one; add the member\'s ID with its number.'];
            }

            if (! $mfi && ! $has('ID78') && ! $has('ID80')) {
                $out['required']['ID78'] = 'at least one contact must be reported';
            }
        }

        if (isset(self::CONTRACTS[$type])) {
            $this->contractRules($type, $values, $out);
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $values
     * @param  array{required: array<string, string>, empty: array<string, string>, warn: array<string, array{0: string, 1: string}>, findings: list<array{0: string|null, 1: string, 2: string, 3: string}>}  $out
     */
    private function contractRules(string $type, array $values, array &$out): void
    {
        $c = self::CONTRACTS[$type];
        $f = fn (int $n) => "{$type}{$n}";
        $has = fn (string $id) => ($values[$id] ?? '') !== '';
        $phase = strtoupper($values[$f(9)] ?? '');
        $why = 'the contract phase is '.(self::PHASE_LABEL[$phase] ?? $phase);

        if (in_array($phase, ['AC', 'CL', 'CA'], true)) {
            foreach ($c['whenOpened'] as $n) {
                $out['required'][$f($n)] = $why;
            }
        }

        if (in_array($phase, ['CL', 'CA'], true)) {
            $out['required'][$f(16)] = $why;

            foreach ($c['emptyWhenClosed'] as $n) {
                $out['empty'][$f($n)] = $why;
            }
        }

        if (in_array($phase, ['RQ', 'RN', 'RF'], true)) {
            foreach ($c['emptyWhenNotGranted'] as $n) {
                $out['empty'][$f($n)] = $why;
            }
        }

        $reference = CicFormat::dateKey($values[$f(4)] ?? null);
        $start = CicFormat::dateKey($values[$f(13)] ?? null);
        $plannedEnd = CicFormat::dateKey($values[$f(15)] ?? null);
        $actualEnd = CicFormat::dateKey($values[$f(16)] ?? null);

        if ($phase === 'AC' && $c['whenActive'] !== [] && ($reference === null || $plannedEnd === null || $reference < $plannedEnd)) {
            foreach ($c['whenActive'] as $n) {
                $out['required'][$f($n)] = $why;
            }
        }

        if ($start !== null && $reference !== null && $start >= $reference) {
            $out['findings'][] = [$f(13), 'error', 'DATE_ORDER', 'Contract Start Date must be earlier than the Contract Reference Date.'];
        }

        if ($start !== null && $plannedEnd !== null && $plannedEnd <= $start) {
            $out['findings'][] = [$f(15), 'error', 'DATE_ORDER', 'Contract End Planned Date must be later than the Contract Start Date.'];
        }

        if ($start !== null && $actualEnd !== null && $actualEnd < $start) {
            $out['findings'][] = [$f(16), 'error', 'DATE_ORDER', 'Contract End Actual Date can\'t be earlier than the Contract Start Date.'];
        }

        $this->guaranteeRules($type, $values, $c['guarantees'], $out);
        $this->linkedSubjectRules($type, $values, $c['linked'], $out);
        $this->financialRules($type, $values, $phase, $out);
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<string, mixed>  $out
     */
    private function guaranteeRules(string $type, array $values, int $base, array &$out): void
    {
        $has = fn (string $id) => ($values[$id] ?? '') !== '';

        for ($k = 0; $k < 6; $k++) {
            $b = $base + 14 * $k;
            [$subject, $name, $amount, $currency, $guaranteeType] = array_map(fn (int $n) => "{$type}{$n}", [$b + 1, $b + 2, $b + 3, $b + 4, $b + 7]);
            $label = 'Guarantee '.($k + 1);

            if ($has($guaranteeType) && ! $has($subject) && ! $has($name)) {
                $out['required'][$subject] = "{$label} has a type: give the guarantor's subject number or name";
            }

            if ($has($amount) && ! $has($guaranteeType)) {
                $out['required'][$guaranteeType] = "{$label} has an amount";
            }

            if ($has($guaranteeType) && ! $has($amount)) {
                $out['required'][$amount] = "{$label} has a type";
            }

            if ($has($amount)) {
                $out['required'][$currency] = "{$label} has an amount";
            }

            $code = strtoupper($values[$currency] ?? '');

            if ($code !== '' && $code !== 'PHP') {
                $out['findings'][] = [$currency, 'error', 'GUARANTEE_CURRENCY', "{$label}: the currency must be PHP, not {$code}."];
            }
        }
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<string, mixed>  $out
     */
    private function linkedSubjectRules(string $type, array $values, int $base, array &$out): void
    {
        $has = fn (string $id) => ($values[$id] ?? '') !== '';

        for ($k = 0; $k < 6; $k++) {
            [$subject, $role, $name] = array_map(fn (int $n) => $type.($base + 3 * $k + $n), [0, 1, 2]);
            $label = 'Linked subject '.($k + 1);

            if (($has($subject) || $has($name)) && ! $has($role)) {
                $out['required'][$role] = "{$label} is filled in";
            }

            if ($has($role) && ! $has($subject)) {
                $out['required'][$name] = "{$label} has a role but no subject number";
            }
        }
    }

    /**
     * Overdue figures agree with each other, the overdue amount is within the
     * outstanding, and an open contract owes something.
     *
     * @param  array<string, string>  $values
     * @param  array<string, mixed>  $out
     */
    private function financialRules(string $type, array $values, string $phase, array &$out): void
    {
        $c = self::CONTRACTS[$type];
        $number = fn (string $id) => preg_match('/^\d+$/', $values[$id] ?? '') === 1 ? (int) $values[$id] : null;
        $outstanding = $number("{$type}{$c['outstanding']}");
        $overdueAmount = $number("{$type}{$c['overdueAmount']}");

        if ($type === 'CI') {
            $overdueCount = $number('CI33');
            $days = $values['CI35'] ?? '';

            // An overdue period can owe less than half a peso, which reads "0"
            // in whole pesos: a warning. An amount with no overdue period is an
            // error.
            if ($overdueCount !== null && $overdueAmount !== null && $overdueCount > 0 && $overdueAmount === 0) {
                $out['findings'][] = ['CI34', 'warning', 'OVERDUE_INCONSISTENT', 'Overdue Payments Number is above zero but Overdue Payments Amount rounds to zero pesos: the overdue periods owe less than half a peso.'];
            } elseif ($overdueCount === 0 && $overdueAmount !== null && $overdueAmount > 0) {
                $out['findings'][] = ['CI34', 'error', 'OVERDUE_INCONSISTENT', 'Overdue Payments Amount is above zero but Overdue Payments Number is zero.'];
            }

            if ($overdueCount !== null && $days !== '' && ($overdueCount > 0) === in_array($days, ['N', '0'], true)) {
                $out['findings'][] = ['CI35', 'warning', 'OVERDUE_DAYS_INCONSISTENT', 'Overdue Days doesn\'t agree with the number of overdue payments.'];
            }
        }

        if ($outstanding !== null && $overdueAmount !== null && $overdueAmount > $outstanding && $phase === 'AC') {
            $out['findings'][] = ["{$type}{$c['overdueAmount']}", 'warning', 'OVERDUE_ABOVE_OUTSTANDING', 'The overdue amount is more than the outstanding balance.'];
        }

        if ($phase === 'AC' && $outstanding === 0) {
            $out['findings'][] = ["{$type}{$c['outstanding']}", 'warning', 'OPEN_NOTHING_OWED', 'The contract is active but nothing is owed on it. Check whether the loan should be closed (a waiver can leave an open loan with nothing owed).'];
        }
    }

    /** @return Issue */
    private static function issue(string $type, string $key, ?string $fieldId, string $severity, string $code, string $message): array
    {
        return ['record_type' => $type, 'record_key' => $key, 'field_id' => $fieldId, 'severity' => $severity, 'code' => $code, 'message' => $message];
    }
}
