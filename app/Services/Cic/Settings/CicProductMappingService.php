<?php

namespace App\Services\Cic\Settings;

use App\Models\CicProductMapping;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Cic\CicDomains;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Each loan product's default CIC classification (prompt §3.3): record type
 * (CI or CN), contract type, transaction type and, for CI, purpose of credit.
 * Codes are checked in the request (CicClassificationCodes). Each product
 * whose mapping really changes writes one audit row naming the product and the
 * old and new codes with their labels.
 */
final class CicProductMappingService
{
    public const BUSY = 'Someone else saved CIC product mappings at the same moment. Reload the page and try again.';

    private const CODES = ['contract_type', 'transaction_type', 'purpose_code'];

    /** @return array<string, mixed> */
    public function listing(): array
    {
        $mappings = CicProductMapping::query()->with('updater')->get()->keyBy('loan_product_id');

        return [
            'products' => LoanProduct::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'status'])
                ->map(fn (LoanProduct $product) => [
                    'loan_product' => ['id' => $product->id, 'name' => $product->name, 'status' => $product->status],
                    'mapping' => CicSettingsPresenter::productMapping($mappings->get($product->id)),
                ])->values()->all(),
            'code_lists' => [
                'CI:ContractTypeDomain' => CicDomains::codes((string) CicClassificationCodes::domain('CI', 'contract_type')),
                'CN:ContractTypeDomain' => CicDomains::codes((string) CicClassificationCodes::domain('CN', 'contract_type')),
                'TransactionTypeDomain' => CicDomains::codes((string) CicClassificationCodes::domain('CI', 'transaction_type')),
                'CreditPurposeDomain' => CicDomains::codes((string) CicClassificationCodes::domain('CI', 'purpose_code')),
            ],
        ];
    }

    /** @param  list<array{loan_product_id: int, record_type: string, contract_type: string, transaction_type: string, purpose_code?: string|null}>  $entries  validated */
    public function save(array $entries, User $user): void
    {
        $names = LoanProduct::query()->whereIn('id', array_column($entries, 'loan_product_id'))->pluck('name', 'id');

        try {
            DB::transaction(function () use ($entries, $user, $names) {
                foreach ($entries as $entry) {
                    $this->saveOne($entry, $user, (string) ($names[$entry['loan_product_id']] ?? 'Loan product #'.$entry['loan_product_id']));
                }
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, self::BUSY);
        }
    }

    /**
     * Clears a product's mapping, audited in words. Its loans then report the
     * unmapped-product error until it's mapped again or a loan gets its own
     * classification. A product with no mapping answers 404.
     */
    public function clear(int $loanProductId, User $user): void
    {
        DB::transaction(function () use ($loanProductId, $user) {
            /** @var CicProductMapping|null $mapping */
            $mapping = CicProductMapping::query()->where('loan_product_id', $loanProductId)->lockForUpdate()->first();
            abort_if($mapping === null, 404, 'This loan product has no CIC mapping to clear.');

            $productName = (string) (LoanProduct::query()->whereKey($loanProductId)->value('name') ?? 'Loan product #'.$loanProductId);
            $before = self::words($mapping->only(['record_type', ...self::CODES]));
            $mapping->delete();

            AuditLogService::log(
                'deleted',
                $mapping,
                ['loan_product' => $productName, ...$before],
                null,
                "CIC mapping for loan product \"{$productName}\" cleared (was ".implode('; ', array_map(
                    fn (string $field) => self::fieldWords($field).' '.($before[$field] ?? 'none'),
                    array_keys($before),
                )).'). Its loans have no contract or transaction type until it is mapped again or a loan gets its own classification.',
                $user->id,
            );
        });
    }

    /** @param  array{loan_product_id: int, record_type: string, contract_type: string, transaction_type: string, purpose_code?: string|null}  $entry */
    private function saveOne(array $entry, User $user, string $productName): void
    {
        $values = [
            'record_type' => $entry['record_type'],
            'contract_type' => $entry['contract_type'],
            'transaction_type' => $entry['transaction_type'],
            'purpose_code' => ($entry['purpose_code'] ?? null) ?: null,
        ];

        /** @var CicProductMapping|null $mapping */
        $mapping = CicProductMapping::query()->where('loan_product_id', $entry['loan_product_id'])->lockForUpdate()->first();
        $before = $mapping === null ? null : self::words($mapping->only(array_keys($values)));

        if ($mapping !== null && $mapping->only(array_keys($values)) == $values) {
            return;
        }

        $mapping ??= new CicProductMapping(['loan_product_id' => $entry['loan_product_id']]);
        $mapping->fill($values);
        $mapping->forceFill(['updated_by' => $user->id])->save();
        $after = self::words($values);

        $changed = $before === null
            ? 'set'
            : implode('; ', array_filter(array_map(
                fn (string $field) => $before[$field] === $after[$field] ? null : self::fieldWords($field).' '.($before[$field] ?? 'none').' → '.($after[$field] ?? 'none'),
                array_keys($after),
            )));

        AuditLogService::log(
            $before === null ? 'created' : 'updated',
            $mapping,
            $before === null ? null : ['loan_product' => $productName, ...$before],
            ['loan_product' => $productName, ...$after],
            "CIC mapping for loan product \"{$productName}\" ".($before === null
                ? 'set: '.implode('; ', array_map(fn (string $field) => self::fieldWords($field).' '.($after[$field] ?? 'none'), array_keys($after)))
                : "changed: {$changed}").'.',
            $user->id,
        );
    }

    /**
     * @param  array<string, string|null>  $values
     * @return array<string, string|null> each code with its label
     */
    public static function words(array $values): array
    {
        $recordType = $values['record_type'] ?? null;
        $words = ['record_type' => $recordType];

        foreach (self::CODES as $code) {
            $words[$code] = CicClassificationCodes::words($recordType, $code, $values[$code] ?? null);
        }

        return $words;
    }

    public static function fieldWords(string $field): string
    {
        return $field === 'record_type' ? 'record type' : CicClassificationCodes::fieldWords($field);
    }
}
