<?php

namespace App\Services\Penalties;

use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\PenaltyConfig;
use Illuminate\Support\Facades\DB;

/**
 * A loan's late-penalty terms: what it copies from its product at creation,
 * and what the engine reads from it afterwards.
 *
 * - A product covered by an active penalty configuration gives a new loan
 *   that configuration's id, name and brackets, and `penalty_rate` 0: the
 *   brackets are the loan's whole penalty.
 * - Any other product gives today's copy of its `penalty_rate`, with the
 *   bracket fields null.
 *
 * Copied once, at creation (LoanService::productSnapshot() and the CSV
 * import), so editing or deactivating a configuration never reprices a loan.
 */
final class PenaltyTerms
{
    public const SOURCE_CONFIGURATION = 'configuration';

    public const SOURCE_FIXED_RATE = 'fixed_rate';

    public const SOURCE_NONE = 'none';

    /**
     * The penalty attributes a new loan on `$product` stores.
     *
     * Inside the creating transaction the lookup is a locking read: the
     * active configuration and its link to the product are read together
     * under a shared lock, then its brackets under the same lock. A product
     * moved to another configuration meanwhile gets that one; one no longer
     * covered gets its own rate. An edit or delete of the configuration waits
     * until the loan is saved, and the delete then sees the loan and is
     * refused (PenaltyConfigService).
     *
     * @return array{penalty_rate: mixed, penalty_config_id: int|null, penalty_config_name: string|null, penalty_brackets: list<array{days_from: int, days_to: int|null, rate: float}>|null}
     */
    public static function forProduct(LoanProduct $product): array
    {
        $locking = DB::transactionLevel() > 0;
        $config = $product->getKey() === null ? null : PenaltyConfig::query()
            ->select('penalty_configs.*')
            ->join('penalty_config_products', 'penalty_config_products.penalty_config_id', '=', 'penalty_configs.id')
            ->where('penalty_config_products.loan_product_id', $product->getKey())
            ->where('penalty_configs.status', PenaltyConfig::STATUS_ACTIVE)
            ->orderBy('penalty_configs.id')
            ->when($locking, fn ($q) => $q->sharedLock())
            ->first();

        if ($config === null) {
            return [
                'penalty_rate' => $product->penalty_rate,
                'penalty_config_id' => null,
                'penalty_config_name' => null,
                'penalty_brackets' => null,
            ];
        }

        $conditions = $config->conditions()->when($locking, fn ($q) => $q->sharedLock())->get();
        $config->setRelation('conditions', $conditions);

        return [
            'penalty_rate' => 0,
            'penalty_config_id' => $config->id,
            'penalty_config_name' => $config->name,
            'penalty_brackets' => $config->brackets(),
        ];
    }

    /**
     * The active configuration covering `$product`, or null, for display.
     * Loaded onto the product once (LoanProductResource::collection() loads a
     * whole list in one query; loans sharing a product share its instance).
     * A new loan's copy reads afresh instead (forProduct()).
     */
    public static function activeConfigFor(LoanProduct $product): ?PenaltyConfig
    {
        if ($product->getKey() === null) {
            return null;
        }

        return $product->loadMissing('activePenaltyConfigs')->activePenaltyConfigs->first();
    }

    /**
     * Where a new loan on `$product` takes its penalty from, for the product
     * form: an active configuration, the product's own rate, or nothing.
     *
     * @return array{type: string, config_id: int|null, config_name: string|null, rate: float|null}
     */
    public static function sourceFor(LoanProduct $product): array
    {
        $config = self::activeConfigFor($product);

        if ($config !== null) {
            return ['type' => self::SOURCE_CONFIGURATION, 'config_id' => $config->id, 'config_name' => $config->name, 'rate' => null];
        }

        if ((float) $product->penalty_rate > 0) {
            return ['type' => self::SOURCE_FIXED_RATE, 'config_id' => null, 'config_name' => null, 'rate' => (float) $product->penalty_rate];
        }

        return ['type' => self::SOURCE_NONE, 'config_id' => null, 'config_name' => null, 'rate' => null];
    }

    /**
     * The brackets the loan was created with, or null for a loan charged by
     * its `penalty_rate`.
     *
     * @return list<array{days_from: int, days_to: int|null, rate: float}>|null
     */
    public static function bracketsOf(Loan $loan): ?array
    {
        $brackets = $loan->penalty_brackets;

        return is_array($brackets) && $brackets !== [] ? BracketRate::normalise($brackets) : null;
    }

    /**
     * Whether the loan charges any late penalty at all: a `penalty_rate`
     * above 0, or a bracket with a rate above 0.
     */
    public static function chargesPenalty(Loan $loan): bool
    {
        $brackets = self::bracketsOf($loan);

        return $brackets === null ? (float) $loan->penalty_rate > 0 : BracketRate::chargesAnything($brackets);
    }
}
