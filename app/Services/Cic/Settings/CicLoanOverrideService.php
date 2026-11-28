<?php

namespace App\Services\Cic\Settings;

use App\Models\CicLoanOverride;
use App\Models\Loan;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A loan's own CIC classification over its product's mapping (prompt §3.3),
 * with the reason staff gave. Each create, change and removal writes an audit
 * row naming the loan by its display number, with the reason. The request
 * checks the loan is reportable and the codes are CIC's.
 */
final class CicLoanOverrideService
{
    /** Loans CIC reports: every loan that was ever released. */
    public const REPORTABLE_STATUSES = Loan::EVER_RELEASED_STATUSES;

    private const FIELDS = ['record_type', 'contract_type', 'transaction_type'];

    public const BUSY = 'Someone else changed this loan\'s CIC override at the same moment. Reload the page and try again.';

    /** @param  array{loan_id: int, record_type?: string|null, contract_type?: string|null, transaction_type?: string|null, reason: string}  $values */
    public function create(array $values, User $user): CicLoanOverride
    {
        try {
            return DB::transaction(function () use ($values, $user) {
                $loan = Loan::query()->whereKey($values['loan_id'])->lockForUpdate()->firstOrFail();

                if (CicLoanOverride::query()->where('loan_id', $loan->id)->lockForUpdate()->exists()) {
                    abort(409, 'This loan already has a CIC override. Change or remove that one instead.');
                }

                $override = new CicLoanOverride(['loan_id' => $loan->id, ...$this->codes($values), 'reason' => $values['reason']]);
                $override->forceFill(['created_by' => $user->id, 'updated_by' => $user->id])->save();
                $after = $this->words($override);

                AuditLogService::log('created', $override, null, ['loan' => $loan->displayNumber(), ...$after, 'reason' => $override->reason],
                    "CIC override set on loan {$loan->displayNumber()}: ".$this->list($after).". Reason: {$override->reason}", $user->id);

                return $override;
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, self::BUSY);
        }
    }

    /** @param  array{record_type?: string|null, contract_type?: string|null, transaction_type?: string|null, reason: string}  $values */
    public function update(CicLoanOverride $override, array $values, User $user): CicLoanOverride
    {
        return DB::transaction(function () use ($override, $values, $user) {
            /** @var CicLoanOverride $locked */
            $locked = CicLoanOverride::query()->whereKey($override->id)->lockForUpdate()->firstOrFail();
            $before = $this->words($locked);
            $locked->fill([...$this->codes($values), 'reason' => $values['reason']]);

            if (! $locked->isDirty()) {
                return $locked;
            }

            $locked->forceFill(['updated_by' => $user->id])->save();
            $after = $this->words($locked);
            $number = $locked->loan?->displayNumber();

            AuditLogService::log('updated', $locked,
                ['loan' => $number, ...$before, 'reason' => $locked->getOriginal('reason')],
                ['loan' => $number, ...$after, 'reason' => $locked->reason],
                "CIC override on loan {$number} changed: ".$this->list($after).". Reason: {$locked->reason}", $user->id);

            return $locked;
        });
    }

    public function delete(CicLoanOverride $override, string $reason, User $user): void
    {
        DB::transaction(function () use ($override, $reason, $user) {
            /** @var CicLoanOverride $locked */
            $locked = CicLoanOverride::query()->whereKey($override->id)->lockForUpdate()->firstOrFail();
            $number = $locked->loan?->displayNumber();
            $before = $this->words($locked);

            AuditLogService::log('deleted', $locked, ['loan' => $number, ...$before, 'reason' => $locked->reason], ['reason' => $reason],
                "CIC override removed from loan {$number}; its product's mapping applies again. Reason: {$reason}", $user->id);

            $locked->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string|null>
     */
    private function codes(array $values): array
    {
        $codes = [];

        foreach (self::FIELDS as $field) {
            $codes[$field] = ($values[$field] ?? null) ?: null;
        }

        return $codes;
    }

    /** @return array<string, string|null> each field with its label; null keeps the product's value */
    private function words(CicLoanOverride $override): array
    {
        $override->loadMissing('loan');
        $productType = $override->loan === null ? null
            : DB::table('cic_product_mappings')->where('loan_product_id', $override->loan->loan_product_id)->value('record_type');
        $recordType = $override->record_type ?? $productType;

        return [
            'record_type' => $override->record_type,
            'contract_type' => CicClassificationCodes::words($recordType, 'contract_type', $override->contract_type),
            'transaction_type' => CicClassificationCodes::words($recordType, 'transaction_type', $override->transaction_type),
        ];
    }

    /** @param  array<string, string|null>  $words */
    private function list(array $words): string
    {
        return implode('; ', array_map(
            fn (string $field) => CicProductMappingService::fieldWords($field).' '.($words[$field] ?? 'the product\'s'),
            array_keys($words),
        ));
    }
}
