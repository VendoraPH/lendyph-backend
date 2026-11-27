<?php

namespace App\Services\LoanOrigination;

use App\Models\LoanProduct;
use App\Models\OriginationRuleRevision;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The rules for an application draft, on the server (origination decisions
 * 5 and 18, audit §3.3 and §3.5). The wizard (#615, `src/lib/loan-origination.ts`)
 * runs the same checks to guide the applicant; the server is the authority.
 *
 * - normalize(): the draft is checked against a whitelist and stored in one
 *   shape. Unknown keys are dropped, every known field is filled, and a field
 *   of the wrong type or length is refused (422 on `draft.<section>.<field>`).
 * - completedSteps() / completionPercent() / lastCompletedStep(): the
 *   wizard's seven steps before Review, as the wizard counts them.
 * - missingFields(): what blocks a submit, in words. Gender and civil status
 *   are required, as a member record requires them (round fz), plus the
 *   fields the product's settings add.
 * - declaredTotals(): the declared income, expenses and debts added up on
 *   the server, so the browser never sums money. Each line is converted to a
 *   month (amount × periods a year ÷ 12, from the current rule revision) and
 *   rounded half up to the centavo; the totals are the sums of those lines,
 *   so a screen listing them adds up.
 */
final class DraftRules
{
    public const INCOME_SOURCES = ['employed', 'self_employed', 'business_owner', 'farmer', 'pensioner', 'other'];

    public const FREQUENCIES = ['daily', 'weekly', 'bi_weekly', 'semi_monthly', 'monthly', 'quarterly', 'semi_annual', 'annual'];

    /** The wizard's steps, in order; Review isn't counted. */
    public const STEPS = ['product', 'contact', 'personal', 'employment', 'expenses', 'loan', 'documents', 'review'];

    public const MAX_EXISTING_LOANS = 10;

    /** The largest amount a money field takes, as `decimal(15,2)` does. */
    private const MAX_AMOUNT = '9999999999999.99';

    private const DEFAULT_PERIODS_PER_YEAR = [
        'daily' => 365, 'weekly' => 52, 'bi_weekly' => 26, 'semi_monthly' => 24,
        'monthly' => 12, 'quarterly' => 4, 'semi_annual' => 2, 'annual' => 1,
    ];

    /**
     * Each section's fields: ['text', max length] | ['int'] | ['bool'] |
     * ['enum', values] | ['loans'].
     *
     * @var array<string, array<string, array{0: string, 1?: int|list<string>}>>
     */
    private const SCHEMA = [
        'personal' => [
            'first_name' => ['text', 100], 'middle_name' => ['text', 100], 'last_name' => ['text', 100], 'suffix' => ['text', 20],
            'date_of_birth' => ['text', 10], 'gender' => ['text', 20], 'civil_status' => ['text', 30], 'nationality' => ['text', 50],
            'region' => ['text', 100], 'province' => ['text', 100], 'city' => ['text', 100], 'barangay' => ['text', 100],
            'street_address' => ['text', 255], 'postal_code' => ['text', 10], 'years_at_residence' => ['text', 10],
            'residence_ownership' => ['text', 50], 'dependents' => ['text', 10], 'membership_number' => ['text', 50],
            'branch_id' => ['int'],
        ],
        'employment' => [
            'income_source' => ['enum', self::INCOME_SOURCES],
            'employer_name' => ['text', 150], 'employment_type' => ['text', 50], 'job_title' => ['text', 100],
            'years_employed' => ['text', 10], 'employer_address' => ['text', 255],
            'gross_monthly_income' => ['text', 20], 'net_monthly_income' => ['text', 20], 'other_regular_income' => ['text', 20],
            'salary_frequency' => ['enum', self::FREQUENCIES],
            'business_name' => ['text', 150], 'business_type' => ['text', 100], 'business_address' => ['text', 255],
            'years_operating' => ['text', 10], 'monthly_revenue' => ['text', 20], 'monthly_business_expenses' => ['text', 20],
            'monthly_net_business_income' => ['text', 20],
            'other_source_description' => ['text', 255], 'other_income_amount' => ['text', 20],
            'other_income_frequency' => ['enum', self::FREQUENCIES], 'other_income_details' => ['text', 500],
        ],
        'expenses' => [
            'housing' => ['text', 20], 'utilities' => ['text', 20], 'food' => ['text', 20], 'transportation' => ['text', 20],
            'education' => ['text', 20], 'family_support' => ['text', 20], 'other_essential' => ['text', 20],
            'credit_card_payments' => ['text', 20], 'other_debt_payments' => ['text', 20], 'other_commitments' => ['text', 20],
            'has_existing_loans' => ['bool'],
            'existing_loans' => ['loans'],
        ],
        'loan' => [
            'loan_product_id' => ['int'], 'amount' => ['text', 20], 'purpose' => ['text', 255], 'term' => ['text', 10],
            'frequency' => ['text', 30], 'release_method' => ['text', 50],
        ],
    ];

    /** @var array<string, array{0: string, 1?: int|list<string>}> */
    private const EXISTING_LOAN = [
        'institution' => ['text', 100], 'outstanding_balance' => ['text', 20], 'payment_amount' => ['text', 20],
        'payment_frequency' => ['enum', self::FREQUENCIES], 'remaining_term' => ['text', 20],
    ];

    private const EXPENSE_LINES = [
        'housing' => 'Rent or housing',
        'utilities' => 'Electricity, water and internet',
        'food' => 'Food',
        'transportation' => 'Transportation',
        'education' => 'School fees',
        'family_support' => 'Support to family',
        'other_essential' => 'Other necessary expenses',
    ];

    private const DEBT_LINES = [
        'credit_card_payments' => 'Credit card payments',
        'other_debt_payments' => 'Other debt payments',
        'other_commitments' => 'Other regular commitments',
    ];

    /**
     * The application form's own fields (`section.field`), which a product's
     * settings may make required.
     *
     * @return list<string>
     */
    public static function fieldKeys(): array
    {
        $keys = [];

        foreach (self::SCHEMA as $section => $fields) {
            foreach ($fields as $field => $spec) {
                if ($spec[0] === 'text' || $spec[0] === 'enum') {
                    $keys[] = "{$section}.{$field}";
                }
            }
        }

        return $keys;
    }

    /**
     * An empty draft, every field blank.
     *
     * @return array<string, array<string, mixed>>
     */
    public function empty(): array
    {
        return $this->normalize([]);
    }

    /**
     * The draft in its one stored shape.
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws ValidationException
     */
    public function normalize(mixed $input): array
    {
        $errors = [];

        if (! is_array($input)) {
            throw ValidationException::withMessages(['draft' => 'The application could not be read. Refresh and try again.']);
        }

        $draft = [];

        foreach (self::SCHEMA as $section => $fields) {
            $given = $input[$section] ?? [];

            if (! is_array($given)) {
                $errors["draft.{$section}"] = 'This part of the application could not be read. Refresh and try again.';
                $given = [];
            }

            $draft[$section] = $this->normalizeFields($fields, $given, "draft.{$section}", $errors);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $draft;
    }

    /**
     * The steps before Review whose data the draft holds.
     *
     * @param  array<string, array<string, mixed>>  $draft
     * @return list<string>
     */
    public function completedSteps(array $draft, ?LoanProduct $product, bool $contactVerified, bool $documentsComplete, ?string $today = null): array
    {
        $today ??= Carbon::now('Asia/Manila')->toDateString();
        $done = [];

        if ($draft['loan']['loan_product_id'] !== null) {
            $done[] = 'product';
        }

        if ($contactVerified) {
            $done[] = 'contact';
        }

        if ($this->personalErrors($draft['personal'], $today, submitting: false) === []) {
            $done[] = 'personal';
        }

        if ($this->employmentErrors($draft['employment']) === []) {
            $done[] = 'employment';
        }

        if ($this->expenseErrors($draft['expenses']) === []) {
            $done[] = 'expenses';
        }

        if ($this->loanErrors($draft['loan'], $product) === []) {
            $done[] = 'loan';
        }

        if ($documentsComplete) {
            $done[] = 'documents';
        }

        return $done;
    }

    /** @param  list<string>  $done */
    public function completionPercent(array $done): int
    {
        $total = count(self::STEPS) - 1;
        $counted = count(array_filter($done, fn (string $step) => $step !== 'review'));

        return (int) round(min($counted, $total) / $total * 100);
    }

    /** @param  list<string>  $done */
    public function lastCompletedStep(array $done): ?string
    {
        $last = null;

        foreach (self::STEPS as $step) {
            if (in_array($step, $done, true)) {
                $last = $step;
            }
        }

        return $last;
    }

    /**
     * What blocks a submit: `section.field` => what to do, in words.
     *
     * @param  array<string, array<string, mixed>>  $draft
     * @param  list<string>  $requiredFields  the product's own required fields
     * @return array<string, string>
     */
    public function missingFields(array $draft, ?LoanProduct $product, ?string $today = null, array $requiredFields = []): array
    {
        $today ??= Carbon::now('Asia/Manila')->toDateString();

        $errors = [
            ...$this->prefixed('personal', $this->personalErrors($draft['personal'], $today, submitting: true)),
            ...$this->prefixed('employment', $this->employmentErrors($draft['employment'])),
            ...$this->prefixed('expenses', $this->expenseErrors($draft['expenses'])),
            ...$this->prefixed('loan', $this->loanErrors($draft['loan'], $product)),
        ];

        foreach ($requiredFields as $key) {
            [$section, $field] = array_pad(explode('.', $key, 2), 2, '');

            if (isset($errors[$key]) || ! isset(self::SCHEMA[$section][$field])) {
                continue;
            }

            if (trim((string) ($draft[$section][$field] ?? '')) === '') {
                $errors[$key] = 'Enter your '.str_replace('_', ' ', $field).'.';
            }
        }

        return $errors;
    }

    /**
     * The declared amounts, each line a month, and their totals, as exact
     * two-decimal strings.
     *
     * @param  array<string, array<string, mixed>>  $draft
     * @param  array<string, int>|null  $periodsPerYear  the rule revision's, by default
     * @return array{
     *     monthly_income: ?string, monthly_expenses: string, monthly_debt_payments: string, monthly_remaining: ?string,
     *     income: list<array{key: string, label: string, monthly: string}>,
     *     expenses: list<array{key: string, label: string, monthly: string}>,
     *     debts: list<array{key: string, label: string, monthly: string}>
     * }
     */
    public function declaredTotals(array $draft, ?array $periodsPerYear = null): array
    {
        $periods = $periodsPerYear ?? $this->periodsPerYear();
        $employment = $draft['employment'];
        $expenses = $draft['expenses'];

        $income = [];
        $line = function (string $key, string $label, ?BigDecimal $monthly) use (&$income): void {
            if ($monthly !== null) {
                $income[] = ['key' => $key, 'label' => $label, 'monthly' => (string) $monthly->toScale(2, RoundingMode::HalfUp)];
            }
        };

        switch ($employment['income_source']) {
            case 'employed':
                $line('gross_monthly_income', 'Gross monthly pay', $this->decimal($employment['gross_monthly_income']));
                break;
            case 'self_employed':
            case 'business_owner':
                $net = $this->decimal($employment['monthly_net_business_income']);
                $revenue = $this->decimal($employment['monthly_revenue']);

                if ($net !== null) {
                    $line('monthly_net_business_income', 'Net income a month', $net);
                } elseif ($revenue !== null) {
                    $costs = $this->decimal($employment['monthly_business_expenses']) ?? BigDecimal::zero();
                    $line('monthly_revenue', 'Average sales less business costs', BigDecimal::max($revenue->minus($costs), BigDecimal::zero()));
                }
                break;
            case 'farmer':
            case 'pensioner':
            case 'other':
                $line('other_income_amount', 'Amount received', $this->monthly($employment['other_income_amount'], $employment['other_income_frequency'], $periods));
                break;
        }

        $line('other_regular_income', 'Other regular income a month', $this->decimal($employment['other_regular_income']));

        $expenseLines = [];
        foreach (self::EXPENSE_LINES as $key => $label) {
            $amount = $this->decimal($expenses[$key]);

            if ($amount !== null) {
                $expenseLines[] = ['key' => $key, 'label' => $label, 'monthly' => (string) $amount->toScale(2, RoundingMode::HalfUp)];
            }
        }

        $debts = [];
        foreach (self::DEBT_LINES as $key => $label) {
            $amount = $this->decimal($expenses[$key]);

            if ($amount !== null) {
                $debts[] = ['key' => $key, 'label' => $label, 'monthly' => (string) $amount->toScale(2, RoundingMode::HalfUp)];
            }
        }

        if ($expenses['has_existing_loans'] === true) {
            foreach ($expenses['existing_loans'] as $i => $loan) {
                $monthly = $this->monthly($loan['payment_amount'], $loan['payment_frequency'], $periods);

                if ($monthly !== null) {
                    $name = trim((string) $loan['institution']);
                    $debts[] = ['key' => "existing_loans.{$i}", 'label' => $name !== '' ? $name : 'Unnamed lender', 'monthly' => (string) $monthly];
                }
            }
        }

        $sum = fn (array $lines): BigDecimal => array_reduce($lines, fn (BigDecimal $carry, array $l) => $carry->plus($l['monthly']), BigDecimal::zero());
        $incomeTotal = $income === [] ? null : $sum($income);
        $expenseTotal = $sum($expenseLines);
        $debtTotal = $sum($debts);

        return [
            'monthly_income' => $incomeTotal === null ? null : (string) $incomeTotal->toScale(2),
            'monthly_expenses' => (string) $expenseTotal->toScale(2),
            'monthly_debt_payments' => (string) $debtTotal->toScale(2),
            'monthly_remaining' => $incomeTotal === null ? null : (string) $incomeTotal->minus($expenseTotal)->minus($debtTotal)->toScale(2),
            'income' => $income,
            'expenses' => $expenseLines,
            'debts' => $debts,
        ];
    }

    /**
     * A money field as an exact amount, or null when blank or not an amount:
     * commas, spaces and ₱ are ignored, and at most two decimals are taken.
     */
    public function decimal(mixed $value): ?BigDecimal
    {
        $cleaned = preg_replace('/[,\s₱]/u', '', (string) $value);

        if ($cleaned === '' || ! preg_match('/^\d{1,13}(\.\d{1,2})?$/', (string) $cleaned)) {
            return null;
        }

        $amount = BigDecimal::of($cleaned);

        return $amount->isGreaterThan(self::MAX_AMOUNT) ? null : $amount;
    }

    /** @return array<string, int> */
    private function periodsPerYear(): array
    {
        $stored = OriginationRuleRevision::query()->orderByDesc('version')->value('rules');
        $rules = is_string($stored) ? json_decode($stored, true) : $stored;

        return array_map('intval', $rules['periods_per_year'] ?? self::DEFAULT_PERIODS_PER_YEAR);
    }

    /** @param  array<string, int>  $periods */
    private function monthly(mixed $amount, mixed $frequency, array $periods): ?BigDecimal
    {
        $value = $this->decimal($amount);

        if ($value === null || ! is_string($frequency) || ! isset($periods[$frequency])) {
            return null;
        }

        return $value->multipliedBy($periods[$frequency])->dividedBy(12, 2, RoundingMode::HalfUp);
    }

    /**
     * @param  array<string, array{0: string, 1?: int|list<string>}>  $fields
     * @param  array<mixed>  $given
     * @param  array<string, string>  $errors
     * @return array<string, mixed>
     */
    private function normalizeFields(array $fields, array $given, string $path, array &$errors): array
    {
        $out = [];

        foreach ($fields as $field => $spec) {
            $value = $given[$field] ?? null;
            $key = "{$path}.{$field}";

            $out[$field] = match ($spec[0]) {
                'text' => $this->text($value, (int) $spec[1], $key, $errors),
                'enum' => $this->enum($value, (array) $spec[1], $key, $errors),
                'int' => $this->integer($value, $key, $errors),
                'bool' => $this->boolean($value, $key, $errors),
                'loans' => $this->loans($value, $key, $errors),
            };
        }

        return $out;
    }

    /** @param  array<string, string>  $errors */
    private function text(mixed $value, int $max, string $key, array &$errors): string
    {
        if ($value === null) {
            return '';
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            $errors[$key] = 'Enter text here.';

            return '';
        }

        $text = (string) $value;

        if (mb_strlen($text) > $max) {
            $errors[$key] = "Keep this to {$max} characters or fewer.";

            return '';
        }

        return $text;
    }

    /**
     * @param  list<string>  $values
     * @param  array<string, string>  $errors
     */
    private function enum(mixed $value, array $values, string $key, array &$errors): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (! is_string($value) || ! in_array($value, $values, true)) {
            $errors[$key] = 'Choose one of the options.';

            return '';
        }

        return $value;
    }

    /** @param  array<string, string>  $errors */
    private function integer(mixed $value, string $key, array &$errors): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9]\d{0,9}$/', $value)) {
            return (int) $value;
        }

        $errors[$key] = 'Choose one of the options.';

        return null;
    }

    /** @param  array<string, string>  $errors */
    private function boolean(mixed $value, string $key, array &$errors): ?bool
    {
        if ($value === null || is_bool($value)) {
            return $value;
        }

        $errors[$key] = 'Answer yes or no.';

        return null;
    }

    /**
     * @param  array<string, string>  $errors
     * @return list<array<string, mixed>>
     */
    private function loans(mixed $value, string $key, array &$errors): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            $errors[$key] = 'The existing loans could not be read. Refresh and try again.';

            return [];
        }

        if (count($value) > self::MAX_EXISTING_LOANS) {
            $errors[$key] = 'Add at most '.self::MAX_EXISTING_LOANS.' existing loans.';

            return [];
        }

        $loans = [];

        foreach ($value as $i => $loan) {
            if (! is_array($loan)) {
                $errors["{$key}.{$i}"] = 'This loan could not be read. Remove it and add it again.';

                continue;
            }

            $loans[] = $this->normalizeFields(self::EXISTING_LOAN, $loan, "{$key}.{$i}", $errors);
        }

        return $loans;
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, string>
     */
    private function personalErrors(array $p, string $today, bool $submitting): array
    {
        $errors = [];

        if (trim($p['first_name']) === '') {
            $errors['first_name'] = 'Enter your first name.';
        }

        if (trim($p['last_name']) === '') {
            $errors['last_name'] = 'Enter your last name.';
        }

        $age = $this->ageOn($p['date_of_birth'], $today);

        if ($age === null) {
            $errors['date_of_birth'] = 'Enter your date of birth.';
        } elseif ($age < 18) {
            $errors['date_of_birth'] = 'You must be at least 18 years old to apply.';
        } elseif ($age > 120) {
            $errors['date_of_birth'] = 'Check the year of your date of birth.';
        }

        if ($submitting && trim($p['gender']) === '') {
            $errors['gender'] = 'Choose your gender.';
        }

        if ($submitting && trim($p['civil_status']) === '') {
            $errors['civil_status'] = 'Choose your civil status.';
        }

        foreach (['province' => 'Enter your province.', 'city' => 'Enter your city or municipality.', 'barangay' => 'Enter your barangay.', 'street_address' => 'Enter your house number and street.'] as $field => $message) {
            if (trim($p[$field]) === '') {
                $errors[$field] = $message;
            }
        }

        if (trim($p['postal_code']) !== '' && ! preg_match('/^\d{4}$/', trim($p['postal_code']))) {
            $errors['postal_code'] = 'A postal code has 4 digits.';
        }

        if (trim($p['dependents']) !== '' && ! preg_match('/^\d{1,2}$/', trim($p['dependents']))) {
            $errors['dependents'] = 'Enter the number of dependents, like 2.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $e
     * @return array<string, string>
     */
    private function employmentErrors(array $e): array
    {
        $errors = [];
        $this->optionalAmount($errors, 'other_regular_income', $e['other_regular_income']);

        switch ($e['income_source']) {
            case '':
                $errors['income_source'] = 'Choose where most of your income comes from.';
                break;
            case 'employed':
                if (trim($e['employer_name']) === '') {
                    $errors['employer_name'] = 'Enter your employer\'s name.';
                }
                if (trim($e['job_title']) === '') {
                    $errors['job_title'] = 'Enter your job title.';
                }
                $this->requireAmount($errors, 'gross_monthly_income', $e['gross_monthly_income'], 'gross monthly income');
                $this->optionalAmount($errors, 'net_monthly_income', $e['net_monthly_income']);
                if ($e['salary_frequency'] === '') {
                    $errors['salary_frequency'] = 'Choose how often you are paid.';
                }
                break;
            case 'self_employed':
            case 'business_owner':
                if (trim($e['business_type']) === '') {
                    $errors['business_type'] = 'Enter the type of business.';
                }
                $this->requireAmount($errors, 'monthly_revenue', $e['monthly_revenue'], 'average monthly revenue');
                $this->optionalAmount($errors, 'monthly_business_expenses', $e['monthly_business_expenses']);
                $this->optionalAmount($errors, 'monthly_net_business_income', $e['monthly_net_business_income']);
                break;
            default:
                if (trim($e['other_source_description']) === '') {
                    $errors['other_source_description'] = 'Describe your source of income.';
                }
                $this->requireAmount($errors, 'other_income_amount', $e['other_income_amount'], 'income amount');
                if ($e['other_income_frequency'] === '') {
                    $errors['other_income_frequency'] = 'Choose how often you receive it.';
                }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $x
     * @return array<string, string>
     */
    private function expenseErrors(array $x): array
    {
        $errors = [];

        foreach ([...array_keys(self::EXPENSE_LINES), ...array_keys(self::DEBT_LINES)] as $key) {
            $this->optionalAmount($errors, $key, $x[$key]);
        }

        if ($x['has_existing_loans'] === null) {
            $errors['has_existing_loans'] = 'Tell us whether you have existing loans.';
        }

        if ($x['has_existing_loans'] === true) {
            if ($x['existing_loans'] === []) {
                $errors['existing_loans'] = 'Add at least one existing loan.';
            }

            foreach ($x['existing_loans'] as $i => $loan) {
                if (trim($loan['institution']) === '') {
                    $errors["existing_loans.{$i}.institution"] = 'Enter the lender\'s name.';
                }
                if ($this->decimal($loan['payment_amount']) === null) {
                    $errors["existing_loans.{$i}.payment_amount"] = 'Enter the regular payment amount.';
                }
                if ($loan['payment_frequency'] === '') {
                    $errors["existing_loans.{$i}.payment_frequency"] = 'Choose how often you pay.';
                }
                $this->optionalAmount($errors, "existing_loans.{$i}.outstanding_balance", $loan['outstanding_balance']);
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $l
     * @return array<string, string>
     */
    private function loanErrors(array $l, ?LoanProduct $product): array
    {
        if ($product === null) {
            return ['loan_product_id' => 'Choose a loan product.'];
        }

        $errors = [];
        $amount = $this->decimal($l['amount']);
        $min = $product->min_amount === null ? null : BigDecimal::of((string) $product->min_amount);
        $max = $product->max_amount === null ? null : BigDecimal::of((string) $product->max_amount);

        if ($amount === null || $amount->isZero()) {
            $errors['amount'] = 'Enter the amount you want to borrow.';
        } elseif (($min !== null && $amount->isLessThan($min)) || ($max !== null && $amount->isGreaterThan($max))) {
            $errors['amount'] = $min !== null && $max !== null
                ? 'Enter an amount from '.$this->pesos($min).' to '.$this->pesos($max).'.'
                : ($min !== null ? 'Enter at least '.$this->pesos($min).'.' : 'Enter at most '.$this->pesos($max).'.');
        }

        $unit = $product->term_unit?->value ?? 'months';
        $term = trim($l['term']);

        if (! preg_match('/^\d{1,4}$/', $term) || (int) $term === 0) {
            $errors['term'] = 'Choose a repayment term.';
        } elseif (($product->min_term !== null && (int) $term < $product->min_term) || ($product->max_term !== null && (int) $term > $product->max_term)) {
            $errors['term'] = "Choose a term from {$product->min_term} to {$product->max_term} {$unit}.";
        }

        if (trim($l['purpose']) === '') {
            $errors['purpose'] = 'Tell us what the loan is for.';
        }

        $frequencies = $this->frequenciesOf($product);

        if ($l['frequency'] === '' && count($frequencies) > 1) {
            $errors['frequency'] = 'Choose how often you will pay.';
        } elseif ($l['frequency'] !== '' && ! in_array($l['frequency'], $frequencies, true)) {
            $errors['frequency'] = 'Choose one of this product\'s payment schedules.';
        }

        return $errors;
    }

    /** @return list<string> */
    private function frequenciesOf(LoanProduct $product): array
    {
        $listed = array_values(array_filter((array) ($product->frequencies ?? []), 'is_string'));

        if ($listed !== []) {
            return $listed;
        }

        $single = $product->frequency instanceof \BackedEnum ? $product->frequency->value : $product->frequency;

        return is_string($single) && $single !== '' ? [$single] : [];
    }

    private function ageOn(string $dateOfBirth, string $today): ?int
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateOfBirth, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        $born = Carbon::createFromFormat('Y-m-d', $dateOfBirth, 'Asia/Manila')->startOfDay();
        $on = Carbon::createFromFormat('Y-m-d', $today, 'Asia/Manila')->startOfDay();

        return $born->isAfter($on) ? null : (int) $born->diffInYears($on);
    }

    /** @param  array<string, string>  $errors */
    private function requireAmount(array &$errors, string $key, mixed $value, string $label): void
    {
        if ($this->decimal($value) === null) {
            $errors[$key] = "Enter your {$label}.";
        }
    }

    /** @param  array<string, string>  $errors */
    private function optionalAmount(array &$errors, string $key, mixed $value): void
    {
        if (trim((string) $value) !== '' && $this->decimal($value) === null) {
            $errors[$key] = 'Enter an amount in pesos, like 5000.';
        }
    }

    /**
     * @param  array<string, string>  $errors
     * @return array<string, string>
     */
    private function prefixed(string $section, array $errors): array
    {
        $out = [];

        foreach ($errors as $key => $message) {
            $out["{$section}.{$key}"] = $message;
        }

        return $out;
    }

    private function pesos(BigDecimal $amount): string
    {
        return '₱'.number_format((float) (string) $amount->toScale(2, RoundingMode::HalfUp), 2);
    }
}
