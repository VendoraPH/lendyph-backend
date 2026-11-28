<?php

namespace App\Services\Cic\Settings;

use App\Models\CicLoanOverride;
use App\Models\CicProductMapping;
use App\Models\CicPublicKey;
use App\Models\CicSetting;
use App\Models\User;
use App\Services\Cic\CicSwitch;

/**
 * The JSON the CIC settings screens read. Names staff by id and name only;
 * a public key's armored text is never sent.
 */
final class CicSettingsPresenter
{
    /** @return array{id: int, name: string}|null */
    public static function user(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => $user->full_name];
    }

    /** @return array<string, mixed> */
    public static function settings(CicSetting $setting): array
    {
        $setting->loadMissing('updater');

        return [
            'provider_code' => $setting->provider_code,
            'format_version' => CicSwitch::formatVersion(),
            'is_mfi' => (bool) $setting->is_mfi,
            'institution_name' => $setting->institution_name,
            'contact_name' => $setting->contact_name,
            'contact_email' => $setting->contact_email,
            'contact_phone' => $setting->contact_phone,
            'updated_by' => self::user($setting->updater),
            'updated_at' => $setting->updated_at?->toJSON(),
        ];
    }

    /**
     * A key's history row. `can_verify` tells the screen whether the caller
     * may verify it (pending, and not their own upload).
     *
     * @return array<string, mixed>
     */
    public static function publicKey(CicPublicKey $key, ?User $caller = null): array
    {
        $key->loadMissing(['uploader', 'verifier', 'retirer']);

        return [
            'id' => $key->id,
            'fingerprint' => $key->fingerprint,
            'fingerprint_display' => trim(chunk_split($key->fingerprint, 4, ' ')),
            'key_id' => $key->key_id,
            'algorithm' => $key->algorithm,
            'user_ids' => array_values($key->user_ids ?? []),
            'key_created_at' => $key->key_created_at?->toJSON(),
            'expires_at' => $key->expires_at?->toJSON(),
            'expired' => $key->isExpired(),
            'status' => $key->status,
            'uploaded_by' => self::user($key->uploader),
            'uploaded_at' => $key->created_at?->toJSON(),
            'verified_by' => self::user($key->verifier),
            'verified_at' => $key->verified_at?->toJSON(),
            'retired_by' => self::user($key->retirer),
            'retired_at' => $key->retired_at?->toJSON(),
            'retire_reason' => $key->retire_reason,
            'can_verify' => $caller !== null
                && $key->status === CicPublicKey::STATUS_PENDING
                && $key->uploaded_by !== $caller->id,
        ];
    }

    /** @return array<string, mixed>|null */
    public static function productMapping(?CicProductMapping $mapping): ?array
    {
        if ($mapping === null) {
            return null;
        }

        $mapping->loadMissing('updater');

        return [
            ...self::codes($mapping->record_type, $mapping->contract_type, $mapping->transaction_type),
            'purpose_code' => $mapping->purpose_code,
            'purpose_label' => CicClassificationCodes::label($mapping->record_type, 'purpose_code', $mapping->purpose_code),
            'updated_by' => self::user($mapping->updater),
            'updated_at' => $mapping->updated_at?->toJSON(),
        ];
    }

    /**
     * @param  array<int, CicProductMapping|null>|null  $mappings  product mappings by loan product id, when the caller read them for a page
     * @return array<string, mixed>
     */
    public static function loanOverride(CicLoanOverride $override, ?array $mappings = null): array
    {
        $override->loadMissing(['loan.loanProduct', 'creator', 'updater']);
        $loan = $override->loan;
        $mapping = match (true) {
            $loan === null => null,
            $mappings !== null => $mappings[$loan->loan_product_id] ?? null,
            default => CicProductMapping::query()->where('loan_product_id', $loan->loan_product_id)->first(),
        };
        $recordType = $override->record_type ?? $mapping?->record_type;

        return [
            'id' => $override->id,
            'loan' => $loan === null ? null : [
                'id' => $loan->id,
                'display_number' => $loan->displayNumber(),
                'status' => $loan->status,
                'loan_product' => $loan->loanProduct === null ? null : ['id' => $loan->loanProduct->id, 'name' => $loan->loanProduct->name],
            ],
            'record_type' => $override->record_type,
            'contract_type' => $override->contract_type,
            'contract_type_label' => CicClassificationCodes::label($recordType, 'contract_type', $override->contract_type),
            'transaction_type' => $override->transaction_type,
            'transaction_type_label' => CicClassificationCodes::label($recordType, 'transaction_type', $override->transaction_type),
            'product_mapping' => self::productMapping($mapping),
            'reason' => $override->reason,
            'created_by' => self::user($override->creator),
            'created_at' => $override->created_at?->toJSON(),
            'updated_by' => self::user($override->updater),
            'updated_at' => $override->updated_at?->toJSON(),
        ];
    }

    /** @return array<string, string|null> */
    private static function codes(?string $recordType, ?string $contractType, ?string $transactionType): array
    {
        return [
            'record_type' => $recordType,
            'contract_type' => $contractType,
            'contract_type_label' => CicClassificationCodes::label($recordType, 'contract_type', $contractType),
            'transaction_type' => $transactionType,
            'transaction_type_label' => CicClassificationCodes::label($recordType, 'transaction_type', $transactionType),
        ];
    }
}
