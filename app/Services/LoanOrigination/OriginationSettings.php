<?php

namespace App\Services\LoanOrigination;

use App\Http\Resources\Savings\SavingsUserRef;
use App\Models\OriginationProductSetting;
use App\Models\OriginationRuleRevision;
use App\Models\OriginationSetting;
use App\Models\User;
use App\Services\LoanOrigination\Files\FileScanner;
use App\Services\LoanOrigination\Files\MisconfiguredScanner;
use App\Services\LoanOrigination\Files\NoScanner;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Online applications' settings, read and saved as the frontend's one
 * OriginationSettings object (general, products, assessment, documents,
 * notifications), plus what the server decides (`server`, `rules_version`).
 *
 * - The settings row and the product rows are audited as they change.
 * - The assessment rules are immutable revisions (decision 15): a save whose
 *   rules differ from the current revision adds the next one; a save that
 *   changes nothing there adds nothing. Rates are kept as the exact decimals
 *   entered (at most four places) and money at two, never rounded.
 * - Rates and money go out as JSON numbers, as the frontend's types expect;
 *   the stored strings stay exact.
 */
final class OriginationSettings
{
    public const SCANNER_NONE = 'Not scanned (no virus scanner is set up)';

    public const SCANNER_MISCONFIGURED = 'Not set up correctly: uploads are refused until the server\'s virus scanner setting is fixed';

    private const THRESHOLDS = ['max_dti', 'min_coverage', 'min_remaining_income'];

    public function __construct(
        private readonly OriginationSwitch $switch,
        private readonly FileScanner $scanner,
    ) {}

    /** @return array<string, mixed> */
    public function present(): array
    {
        $settings = OriginationSetting::current()->load('updater');
        $revision = OriginationRuleRevision::current();
        $rules = $revision->rules;

        return [
            'general' => [
                'online_applications_enabled' => $settings->online_applications_enabled,
                'default_branch_id' => $settings->default_branch_id,
                'reference_format' => $settings->referenceFormat(),
                'draft_expiration_days' => $settings->draft_expiration_days,
                'estimated_minutes' => $settings->estimated_minutes,
            ],
            'products' => OriginationProductSetting::query()->orderBy('loan_product_id')->get()
                ->map(fn (OriginationProductSetting $p) => [
                    'loan_product_id' => $p->loan_product_id,
                    'online' => $p->online,
                    'required_fields' => $p->required_fields ?? [],
                    'eligibility' => $p->eligibility,
                    'required_documents' => $p->required_documents ?? [],
                    'instructions' => $p->instructions,
                ])->values()->all(),
            'assessment' => [
                'max_dti' => self::number($rules['max_dti']),
                'min_coverage' => self::number($rules['min_coverage']),
                'min_remaining_income' => self::number($rules['min_remaining_income']),
                'income_recognition' => array_map(self::number(...), $rules['income_recognition']),
                'require_verified_income' => (bool) $rules['require_verified_income'],
                'min_employment_years' => $rules['min_employment_years'],
                'missing_information_rule' => $rules['missing_information_rule'],
                'product_overrides' => array_map(
                    fn (array $o) => array_map(fn ($v) => is_string($v) ? self::number($v) : $v, $o),
                    $rules['product_overrides'] ?? [],
                ),
            ],
            'documents' => [
                'accepted_file_types' => $settings->accepted_file_types,
                'max_upload_mb' => $settings->max_upload_mb,
                'retention_days' => $settings->retention_days,
                'types' => $settings->document_types,
            ],
            'notifications' => $settings->notifications,
            'rules_version' => $revision->version,
            'server' => [
                'enabled' => $this->switch->enabled(),
                'public_url' => $this->switch->publicUrl(),
                'public_url_problem' => $this->switch->publicUrlProblem(),
                'scanner' => match (true) {
                    $this->scanner instanceof NoScanner => self::SCANNER_NONE,
                    $this->scanner instanceof MisconfiguredScanner => self::SCANNER_MISCONFIGURED,
                    default => 'ClamAV',
                },
            ],
            'updated_by' => SavingsUserRef::from($settings->updater),
            'updated_at' => $settings->updated_at,
        ];
    }

    /**
     * Save the sections sent; a section left out stays as it is.
     *
     * @param  array<string, mixed>  $input  validated by UpdateOriginationSettingsRequest
     */
    public function save(array $input, User $user): void
    {
        DB::transaction(function () use ($input, $user): void {
            /** @var OriginationSetting $settings */
            $settings = OriginationSetting::query()->orderBy('id')->lockForUpdate()->firstOrFail();

            if (isset($input['general'])) {
                $general = $input['general'];
                $settings->fill(array_filter([
                    'online_applications_enabled' => $general['online_applications_enabled'] ?? null,
                    'reference_prefix' => isset($general['reference_format']) ? explode('-', $general['reference_format'])[0] : null,
                    'draft_expiration_days' => $general['draft_expiration_days'] ?? null,
                ], fn ($v) => $v !== null));

                foreach (['default_branch_id', 'estimated_minutes'] as $nullable) {
                    if (array_key_exists($nullable, $general)) {
                        $settings->{$nullable} = $general[$nullable];
                    }
                }
            }

            if (isset($input['documents'])) {
                $documents = $input['documents'];

                if (isset($documents['accepted_file_types'])) {
                    $settings->accepted_file_types = array_values(array_unique(array_map('strtolower', $documents['accepted_file_types'])));
                }

                foreach (['max_upload_mb', 'retention_days'] as $field) {
                    if (isset($documents[$field])) {
                        $settings->{$field} = $documents[$field];
                    }
                }

                if (isset($documents['types'])) {
                    $settings->document_types = array_map(fn (array $t) => [
                        'type' => $t['type'],
                        'label' => $t['label'],
                        'description' => $t['description'] ?? null,
                        'required' => (bool) $t['required'],
                    ], array_values($documents['types']));
                }
            }

            if (isset($input['notifications'])) {
                $settings->notifications = [...$settings->notifications ?? [], ...$input['notifications']];
            }

            if ($settings->isDirty()) {
                $settings->updated_by = $user->id;
                $settings->save();
            }

            if (array_key_exists('products', $input)) {
                $this->saveProducts($input['products'] ?? []);
            }

            if (isset($input['assessment'])) {
                $this->saveRules($input['assessment'], $user);
            }
        });
    }

    /** @param  list<array<string, mixed>>  $products */
    private function saveProducts(array $products): void
    {
        $keep = array_map(fn (array $p) => (int) $p['loan_product_id'], $products);

        OriginationProductSetting::query()->whereNotIn('loan_product_id', $keep)->get()
            ->each(fn (OriginationProductSetting $row) => $row->delete());

        foreach ($products as $product) {
            $row = OriginationProductSetting::query()->firstOrNew(['loan_product_id' => (int) $product['loan_product_id']]);
            $row->fill([
                'online' => (bool) $product['online'],
                'required_fields' => array_values($product['required_fields'] ?? []),
                'eligibility' => $product['eligibility'] ?? null,
                'required_documents' => array_values($product['required_documents'] ?? []),
                'instructions' => $product['instructions'] ?? null,
            ]);

            if (! $row->exists || $row->isDirty()) {
                $row->save();
            }
        }
    }

    /** @param  array<string, mixed>  $assessment */
    private function saveRules(array $assessment, User $user): void
    {
        $current = OriginationRuleRevision::query()->orderByDesc('version')->lockForUpdate()->firstOrFail();
        $rules = $current->rules;

        foreach (['max_dti', 'min_coverage'] as $rate) {
            if (array_key_exists($rate, $assessment)) {
                $rules[$rate] = self::rate($assessment[$rate]);
            }
        }

        if (array_key_exists('min_remaining_income', $assessment)) {
            $rules['min_remaining_income'] = self::money($assessment['min_remaining_income']);
        }

        foreach ($assessment['income_recognition'] ?? [] as $source => $percent) {
            $rules['income_recognition'][$source] = self::rate($percent);
        }

        foreach (['require_verified_income', 'min_employment_years', 'missing_information_rule'] as $field) {
            if (array_key_exists($field, $assessment)) {
                $rules[$field] = $field === 'require_verified_income' ? (bool) $assessment[$field] : $assessment[$field];
            }
        }

        if (array_key_exists('product_overrides', $assessment)) {
            $overrides = [];

            foreach ($assessment['product_overrides'] ?? [] as $override) {
                $row = ['loan_product_id' => (int) $override['loan_product_id']];

                foreach (self::THRESHOLDS as $field) {
                    if (isset($override[$field])) {
                        $row[$field] = $field === 'min_remaining_income' ? self::money($override[$field]) : self::rate($override[$field]);
                    }
                }

                $overrides[] = $row;
            }

            usort($overrides, fn (array $a, array $b) => $a['loan_product_id'] <=> $b['loan_product_id']);
            $rules['product_overrides'] = $overrides;
        }

        if ($rules == $current->rules) {
            return;
        }

        OriginationRuleRevision::query()->create([
            'version' => $current->version + 1,
            'rules' => $rules,
            'created_by' => $user->id,
        ]);
    }

    /** A rate as entered, exactly: "35.5", "1.3333", "40". */
    private static function rate(mixed $value): string
    {
        return (string) self::decimal($value)->strippedOfTrailingZeros();
    }

    /** Pesos with two decimals: "2500.50". */
    private static function money(mixed $value): string
    {
        return (string) self::decimal($value)->toScale(2, RoundingMode::Unnecessary);
    }

    private static function decimal(mixed $value): BigDecimal
    {
        return BigDecimal::of(is_float($value) ? rtrim(rtrim(sprintf('%.4F', $value), '0'), '.') : (string) $value);
    }

    /** A stored exact decimal as a JSON number: whole numbers as integers. */
    private static function number(string $value): int|float
    {
        $decimal = BigDecimal::of($value)->strippedOfTrailingZeros();

        return $decimal->getScale() === 0 ? (int) (string) $decimal : (float) (string) $decimal;
    }
}
