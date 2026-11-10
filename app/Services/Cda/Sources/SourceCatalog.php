<?php

namespace App\Services\Cda\Sources;

use InvalidArgumentException;

/**
 * Every source key a template may name (CDA design §2), with its family, the
 * unit of its value, a label and its params.
 *
 * - **Family** picks the resolver, by convention `Cda\Sources\{Family}Source`
 *   (SourceRegistry::resolverClass()). C1 builds `coop` and `profile`; C2
 *   builds members, loans, share_capital, savings, accounting, fs and
 *   report; C3 officers, social and mediation; C4 classification and pesos.
 * - **Unit**: count (int), money (2-decimal string), percent (2-decimal
 *   string), number, text, date (YYYY-MM-DD), boolean, table (a list of
 *   rows). `unit_by` names a param whose value changes the unit.
 * - **Params**: `type` date (a token from SourceContext::DATE_TOKENS or
 *   YYYY-MM-DD), enum (`values`), int (`min`, `max`, or `values`), string,
 *   boolean; `required`, and a `default` applied by normalize().
 *
 * A resolver receives params already checked and defaulted. Adding a key is
 * a catalogue change here plus its resolver; the validator accepts only
 * keys listed here.
 */
final class SourceCatalog
{
    /** @var list<string> */
    public const FAMILIES = [
        'coop', 'profile', 'members', 'loans', 'classification', 'share_capital', 'savings', 'accounting',
        'fs', 'officers', 'social', 'mediation', 'report', 'pesos',
    ];

    /** @var list<string> */
    public const UNITS = ['count', 'money', 'percent', 'number', 'text', 'date', 'boolean', 'table', 'any'];

    /**
     * The keys whose figures are never overridden on a report (design §2,
     * Task 14): loans, accounting, fs, share capital and savings, and (C1
     * review, M2) another report's field and the PESOS result, which are
     * built from them.
     *
     * @var list<string>
     */
    public const NOT_OVERRIDABLE_PREFIXES = ['loans.', 'accounting.', 'fs.', 'share_capital.', 'savings.', 'report.', 'pesos.'];

    private const AS_OF = ['type' => 'date', 'default' => 'period_end'];

    private const FROM = ['type' => 'date', 'default' => 'period_start'];

    private const TO = ['type' => 'date', 'default' => 'period_end'];

    private const GENDER = ['type' => 'enum', 'values' => ['male', 'female', 'unspecified']];

    private const MEMBER_TYPE = ['type' => 'enum', 'values' => ['regular', 'associate']];

    private const SAVINGS_KIND = ['type' => 'enum', 'values' => ['regular', 'time_deposit']];

    private const HALF = ['type' => 'enum', 'values' => ['h1', 'h2']];

    /**
     * @var array<string, array{family: string, unit: string, label: string, params: array<string, array<string, mixed>>, unit_by?: array{param: string, map: array<string, string>}}>
     */
    public const KEYS = [
        // Cooperative and profile (C1).
        'coop.name' => ['family' => 'coop', 'unit' => 'text', 'label' => 'Cooperative name', 'params' => []],
        'coop.address' => ['family' => 'coop', 'unit' => 'text', 'label' => 'Cooperative address', 'params' => []],
        'coop.contact' => ['family' => 'coop', 'unit' => 'text', 'label' => 'Cooperative contact', 'params' => []],
        'profile.field' => ['family' => 'profile', 'unit' => 'any', 'label' => 'Regulatory profile', 'params' => [
            'name' => ['type' => 'enum', 'required' => true, 'values' => ProfileSource::FIELDS],
        ]],
        'branches.count' => ['family' => 'coop', 'unit' => 'count', 'label' => 'Branches', 'params' => []],
        'branches.list' => ['family' => 'coop', 'unit' => 'table', 'label' => 'Branches', 'params' => []],
        'users.count' => ['family' => 'coop', 'unit' => 'count', 'label' => 'System users', 'params' => [
            'active' => ['type' => 'boolean', 'default' => true],
        ]],

        // Members (C2).
        'members.count' => ['family' => 'members', 'unit' => 'count', 'label' => 'Members', 'params' => [
            'as_of' => self::AS_OF, 'gender' => self::GENDER, 'member_type' => self::MEMBER_TYPE,
            'age_from' => ['type' => 'int', 'min' => 0, 'max' => 150], 'age_to' => ['type' => 'int', 'min' => 0, 'max' => 150],
            'activity' => ['type' => 'enum', 'values' => ['active', 'inactive']],
        ]],
        'members.new' => ['family' => 'members', 'unit' => 'count', 'label' => 'New members', 'params' => [
            'from' => self::FROM, 'to' => self::TO, 'gender' => self::GENDER, 'member_type' => self::MEMBER_TYPE,
        ]],
        'members.ended' => ['family' => 'members', 'unit' => 'count', 'label' => 'Members who left', 'params' => [
            'from' => self::FROM, 'to' => self::TO, 'reason' => ['type' => 'enum', 'values' => ['withdrawn', 'deceased']],
        ]],
        'members.missing' => ['family' => 'members', 'unit' => 'count', 'label' => 'Members missing data', 'params' => [
            'field' => ['type' => 'enum', 'required' => true, 'values' => ['membership_date', 'membership_number', 'gender', 'birthdate', 'member_type']],
            'as_of' => self::AS_OF,
        ]],

        // Loans (C2).
        'loans.released' => ['family' => 'loans', 'unit' => 'money', 'label' => 'Loans released', 'params' => [
            'measure' => ['type' => 'enum', 'values' => ['count', 'amount'], 'default' => 'amount'],
            'product' => ['type' => 'int', 'min' => 1], 'from' => self::FROM, 'to' => self::TO,
        ], 'unit_by' => ['param' => 'measure', 'map' => ['count' => 'count', 'amount' => 'money']]],
        'loans.outstanding' => ['family' => 'loans', 'unit' => 'money', 'label' => 'Loans outstanding', 'params' => [
            'measure' => ['type' => 'enum', 'values' => ['count', 'principal', 'total'], 'default' => 'principal'],
            'as_of' => self::AS_OF,
        ], 'unit_by' => ['param' => 'measure', 'map' => ['count' => 'count', 'principal' => 'money', 'total' => 'money']]],
        'loans.past_due' => ['family' => 'loans', 'unit' => 'money', 'label' => 'Past due loans', 'params' => [
            'measure' => ['type' => 'enum', 'values' => ['count', 'amount'], 'default' => 'amount'],
            'as_of' => self::AS_OF,
        ], 'unit_by' => ['param' => 'measure', 'map' => ['count' => 'count', 'amount' => 'money']]],
        'loans.par' => ['family' => 'loans', 'unit' => 'percent', 'label' => 'Portfolio at risk', 'params' => [
            'days' => ['type' => 'int', 'values' => [1, 30, 90], 'default' => 30], 'as_of' => self::AS_OF,
        ]],
        'loans.by_product' => ['family' => 'loans', 'unit' => 'table', 'label' => 'Loans by product', 'params' => [
            'from' => self::FROM, 'to' => self::TO,
        ]],
        'loans.collection_efficiency' => ['family' => 'loans', 'unit' => 'percent', 'label' => 'Collection efficiency', 'params' => [
            'from' => self::FROM, 'to' => self::TO,
        ]],

        // Loan classification and provisioning, from the year-end capture (C4).
        'loans.classification' => ['family' => 'classification', 'unit' => 'table', 'label' => 'Loan classification', 'params' => []],
        'loans.provision_required' => ['family' => 'classification', 'unit' => 'money', 'label' => 'Required allowance', 'params' => []],
        'loans.allowance_booked' => ['family' => 'classification', 'unit' => 'money', 'label' => 'Allowance booked (1200)', 'params' => []],
        'loans.provision_gap' => ['family' => 'classification', 'unit' => 'money', 'label' => 'Provision gap', 'params' => []],

        // Share capital (C2).
        'share_capital.paid_up' => ['family' => 'share_capital', 'unit' => 'money', 'label' => 'Paid-up share capital', 'params' => ['as_of' => self::AS_OF]],
        'share_capital.holders' => ['family' => 'share_capital', 'unit' => 'count', 'label' => 'Share capital holders', 'params' => ['as_of' => self::AS_OF]],
        'share_capital.movements' => ['family' => 'share_capital', 'unit' => 'money', 'label' => 'Share capital movements', 'params' => [
            'side' => ['type' => 'enum', 'values' => ['credit', 'debit', 'net'], 'default' => 'net'], 'from' => self::FROM, 'to' => self::TO,
        ]],

        // Savings (C2).
        'savings.balance' => ['family' => 'savings', 'unit' => 'money', 'label' => 'Savings deposits', 'params' => ['kind' => self::SAVINGS_KIND, 'as_of' => self::AS_OF]],
        'savings.accounts' => ['family' => 'savings', 'unit' => 'count', 'label' => 'Savings accounts', 'params' => ['kind' => self::SAVINGS_KIND, 'as_of' => self::AS_OF]],
        'savings.depositors' => ['family' => 'savings', 'unit' => 'count', 'label' => 'Depositors', 'params' => ['kind' => self::SAVINGS_KIND, 'as_of' => self::AS_OF]],

        // Accounting, closed years only (C2).
        'accounting.year_closed' => ['family' => 'accounting', 'unit' => 'boolean', 'label' => 'Accounting year closed', 'params' => []],
        'accounting.balance' => ['family' => 'accounting', 'unit' => 'money', 'label' => 'Account balance', 'params' => [
            'code' => ['type' => 'string', 'required' => true, 'pattern' => 'account_code'], 'as_of' => self::AS_OF,
        ]],
        'accounting.flow' => ['family' => 'accounting', 'unit' => 'money', 'label' => 'Account movements', 'params' => [
            'code' => ['type' => 'string', 'required' => true, 'pattern' => 'account_code'], 'from' => self::FROM, 'to' => self::TO,
            'side' => ['type' => 'enum', 'values' => ['debit', 'credit', 'net'], 'default' => 'net'],
        ]],

        // Financial statements, through the existing builders (C2).
        'fs.balance_sheet' => ['family' => 'fs', 'unit' => 'table', 'label' => 'Balance sheet', 'params' => ['as_of' => self::AS_OF]],
        'fs.total_assets' => ['family' => 'fs', 'unit' => 'money', 'label' => 'Total assets', 'params' => ['as_of' => self::AS_OF]],
        'fs.total_liabilities' => ['family' => 'fs', 'unit' => 'money', 'label' => 'Total liabilities', 'params' => ['as_of' => self::AS_OF]],
        'fs.total_equity' => ['family' => 'fs', 'unit' => 'money', 'label' => 'Total equity', 'params' => ['as_of' => self::AS_OF]],
        'fs.is_balanced' => ['family' => 'fs', 'unit' => 'boolean', 'label' => 'Balance sheet balances', 'params' => ['as_of' => self::AS_OF]],
        'fs.income_statement' => ['family' => 'fs', 'unit' => 'table', 'label' => 'Income statement', 'params' => ['from' => self::FROM, 'to' => self::TO]],
        'fs.total_income' => ['family' => 'fs', 'unit' => 'money', 'label' => 'Total income', 'params' => ['from' => self::FROM, 'to' => self::TO]],
        'fs.total_expenses' => ['family' => 'fs', 'unit' => 'money', 'label' => 'Total expenses', 'params' => ['from' => self::FROM, 'to' => self::TO]],
        'fs.net_surplus' => ['family' => 'fs', 'unit' => 'money', 'label' => 'Net surplus', 'params' => ['from' => self::FROM, 'to' => self::TO]],
        'fs.cash_flow' => ['family' => 'fs', 'unit' => 'table', 'label' => 'Cash flow', 'params' => ['from' => self::FROM, 'to' => self::TO]],
        'fs.equity_changes' => ['family' => 'fs', 'unit' => 'table', 'label' => 'Changes in equity', 'params' => ['from' => self::FROM, 'to' => self::TO]],

        // Officers and trainings (C3).
        'officers.current' => ['family' => 'officers', 'unit' => 'text', 'label' => 'Officer', 'params' => [
            'position' => ['type' => 'enum', 'required' => true, 'values' => ['chairperson', 'vice_chairperson', 'secretary', 'treasurer', 'general_manager']],
            'as_of' => self::AS_OF,
        ]],
        'officers.list' => ['family' => 'officers', 'unit' => 'table', 'label' => 'Officers', 'params' => ['from' => self::FROM, 'to' => self::TO]],
        'officers.count' => ['family' => 'officers', 'unit' => 'count', 'label' => 'Officers', 'params' => [
            'sex' => ['type' => 'enum', 'values' => ['male', 'female']],
            'group' => ['type' => 'enum', 'values' => ['board', 'committee', 'management', 'all'], 'default' => 'all'],
            'from' => self::FROM, 'to' => self::TO,
        ]],
        'trainings.summary' => ['family' => 'officers', 'unit' => 'table', 'label' => 'Officer trainings', 'params' => ['from' => self::FROM, 'to' => self::TO]],

        // Social programs and mediation (C3).
        'social_programs.list' => ['family' => 'social', 'unit' => 'table', 'label' => 'Social programs', 'params' => []],
        'social_programs.summary' => ['family' => 'social', 'unit' => 'number', 'label' => 'Social programs', 'params' => [
            'measure' => ['type' => 'enum', 'required' => true, 'values' => ['count', 'beneficiaries', 'budget', 'actual_expense', 'cdf_amount']],
            'category' => ['type' => 'string'],
        ], 'unit_by' => ['param' => 'measure', 'map' => ['count' => 'count', 'beneficiaries' => 'count', 'budget' => 'money', 'actual_expense' => 'money', 'cdf_amount' => 'money']]],
        'mediation.cases' => ['family' => 'mediation', 'unit' => 'table', 'label' => 'Mediation cases', 'params' => ['half' => self::HALF]],
        'mediation.summary' => ['family' => 'mediation', 'unit' => 'count', 'label' => 'Mediation cases', 'params' => [
            'half' => self::HALF,
            'status' => ['type' => 'enum', 'values' => ['pending', 'in_mediation', 'resolved', 'escalated', 'withdrawn']],
        ]],

        // Cross-report (C2) and PESOS (C4).
        'report.field' => ['family' => 'report', 'unit' => 'any', 'label' => 'Another report', 'params' => [
            'type' => ['type' => 'enum', 'required' => true, 'values' => ['capr', 'financial_statements', 'performance_audit', 'social_audit', 'pesos', 'officers_trainings', 'mediation', 'sworn_statement', 'atir', 'abr']],
            'part' => ['type' => 'enum', 'values' => ['annual', 'h1', 'h2'], 'default' => 'annual'],
            'field' => ['type' => 'string', 'required' => true],
        ]],
        'pesos.result' => ['family' => 'pesos', 'unit' => 'any', 'label' => 'Enhanced PESOS', 'params' => [
            'field' => ['type' => 'string', 'required' => true],
        ]],
    ];

    public static function has(string $key): bool
    {
        return isset(self::KEYS[$key]);
    }

    /** @return array{family: string, unit: string, label: string, params: array<string, array<string, mixed>>} */
    public static function get(string $key): array
    {
        return self::KEYS[$key] ?? throw new InvalidArgumentException("Unknown CDA source key: {$key}.");
    }

    public static function family(string $key): string
    {
        return self::get($key)['family'];
    }

    /** The unit of the value for these params (`unit_by`), else the key's unit. */
    public static function unitFor(string $key, array $params = []): string
    {
        $entry = self::get($key);

        if (isset($entry['unit_by'])) {
            $value = $params[$entry['unit_by']['param']] ?? ($entry['params'][$entry['unit_by']['param']]['default'] ?? null);

            return $entry['unit_by']['map'][$value] ?? $entry['unit'];
        }

        return $entry['unit'];
    }

    public static function overridable(string $key): bool
    {
        foreach (self::NOT_OVERRIDABLE_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * What's wrong with these params, in words; empty when they're fine.
     *
     * @param  array<string, mixed>  $params
     * @return list<string>
     */
    public static function errors(string $key, array $params): array
    {
        if (! self::has($key)) {
            return ["Unknown source key \"{$key}\"."];
        }

        $schema = self::KEYS[$key]['params'];
        $errors = [];

        foreach (array_keys($params) as $name) {
            if (! isset($schema[$name])) {
                $errors[] = "\"{$key}\" takes no param \"{$name}\".";
            }
        }

        foreach ($schema as $name => $spec) {
            if (! array_key_exists($name, $params) || $params[$name] === null) {
                if ($spec['required'] ?? false) {
                    $errors[] = "\"{$key}\" needs the param \"{$name}\".";
                }

                continue;
            }

            $problem = self::paramProblem($params[$name], $spec);

            if ($problem !== null) {
                $errors[] = "\"{$key}\" param \"{$name}\" {$problem}.";
            }
        }

        if (isset($params['age_from'], $params['age_to']) && is_int($params['age_from']) && is_int($params['age_to']) && $params['age_from'] > $params['age_to']) {
            $errors[] = "\"{$key}\" param \"age_from\" is above \"age_to\".";
        }

        return $errors;
    }

    /**
     * The params with their defaults, after checking them.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when they aren't valid
     */
    public static function normalize(string $key, array $params): array
    {
        $errors = self::errors($key, $params);

        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }

        $normalized = [];

        foreach (self::KEYS[$key]['params'] as $name => $spec) {
            if (array_key_exists($name, $params) && $params[$name] !== null) {
                $normalized[$name] = $params[$name];
            } elseif (array_key_exists('default', $spec)) {
                $normalized[$name] = $spec['default'];
            }
        }

        return $normalized;
    }

    /** @param  array<string, mixed>  $spec */
    private static function paramProblem(mixed $value, array $spec): ?string
    {
        return match ($spec['type']) {
            'date' => is_string($value) && (in_array($value, SourceContext::DATE_TOKENS, true) || self::isDate($value))
                ? null : 'must be a date (YYYY-MM-DD) or one of '.implode(', ', SourceContext::DATE_TOKENS),
            'enum' => in_array($value, $spec['values'], true) ? null : 'must be one of '.implode(', ', $spec['values']),
            'int' => match (true) {
                ! is_int($value) => 'must be a whole number',
                isset($spec['values']) && ! in_array($value, $spec['values'], true) => 'must be one of '.implode(', ', $spec['values']),
                isset($spec['min']) && $value < $spec['min'] => "must be at least {$spec['min']}",
                isset($spec['max']) && $value > $spec['max'] => "must be at most {$spec['max']}",
                default => null,
            },
            'boolean' => is_bool($value) ? null : 'must be true or false',
            'string' => match (true) {
                ! is_string($value) || trim($value) === '' || mb_strlen($value) > 120 => 'must be text of up to 120 characters',
                ($spec['pattern'] ?? null) === 'account_code' && preg_match('/^\d{4}(-\d{1,4})?$/', $value) !== 1 => 'must be an account code such as 1200',
                default => null,
            },
            default => 'has an unknown type',
        };
    }

    private static function isDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
