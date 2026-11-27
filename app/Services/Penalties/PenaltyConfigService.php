<?php

namespace App\Services\Penalties;

use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\PenaltyConfig;
use App\Services\AuditLogService;
use App\Services\LoanWriteTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves penalty configurations (Settings → Penalties): create, edit, the
 * status switch and delete, each in one transaction.
 *
 * - **One active configuration per product.** An active configuration's
 *   products are checked against every other active configuration under a
 *   lock: the configuration's own row first, then the product rows in id
 *   order. A clash answers 422 naming the product and the configuration; a
 *   colliding save (a deadlock, a lock timeout, the same new name twice)
 *   answers 409, never 500.
 * - **Delete only when unused.** A configuration any loan was created under
 *   is refused (409): deactivate it instead.
 * - **Audit.** One row per save, written here rather than by Auditable's
 *   automatic rows, because the brackets and products live in their own
 *   tables: the name, the status, the products (`applicable_product_ids`,
 *   read by name) and the brackets in words ("1–5 days: 20%; 11 days or
 *   more: 50%"). A save that changes nothing writes nothing.
 *
 * Loans keep the copy they were created with (PenaltyTerms), so nothing here
 * reprices a loan.
 */
final class PenaltyConfigService
{
    private const CONFLICT = 'Another change to penalty configurations was saved at the same time. Reload and try again.';

    /**
     * @param  array{name: string, status?: string, applicable_product_ids: list<int>, conditions: list<array{days_from: int, days_to: int|null, rate: int|float|string}>}  $data
     */
    public function create(array $data): PenaltyConfig
    {
        return $this->transaction(function () use ($data): PenaltyConfig {
            $status = $data['status'] ?? PenaltyConfig::STATUS_ACTIVE;
            $productIds = $this->productIds($data['applicable_product_ids']);

            if ($status === PenaltyConfig::STATUS_ACTIVE) {
                $this->assertHasProducts($productIds);
                $this->assertNoActiveClash($productIds, null);
            }

            $config = AuditLogService::withoutModelAuditing(function () use ($data, $status, $productIds): PenaltyConfig {
                $config = PenaltyConfig::create(['name' => $data['name'], 'status' => $status]);
                $this->writeBrackets($config, $data['conditions']);
                $config->products()->sync($productIds);

                return $config;
            });

            $after = $this->auditState($config);

            AuditLogService::log('created', $config, null, $after, sprintf(
                'Penalty configuration "%s" created (%s): %s. Products: %s',
                $config->name,
                $status,
                $after['conditions'],
                $this->productNames($productIds),
            ));

            return $config;
        });
    }

    /**
     * @param  array{name: string, status?: string, applicable_product_ids: list<int>, conditions: list<array{days_from: int, days_to: int|null, rate: int|float|string}>}  $data
     */
    public function update(PenaltyConfig $config, array $data): PenaltyConfig
    {
        return $this->transaction(function () use ($config, $data): PenaltyConfig {
            $config = $this->lockConfig($config);
            $before = $this->auditState($config);
            $status = $data['status'] ?? $config->status;
            $productIds = $this->productIds($data['applicable_product_ids']);

            if ($status === PenaltyConfig::STATUS_ACTIVE) {
                $this->assertHasProducts($productIds);
                $this->assertNoActiveClash($productIds, $config->id);
            }

            AuditLogService::withoutModelAuditing(function () use ($config, $data, $status, $productIds): void {
                $config->update(['name' => $data['name'], 'status' => $status]);

                if (BracketRate::normalise($data['conditions']) !== $config->brackets()) {
                    $config->conditions()->delete();
                    $this->writeBrackets($config, $data['conditions']);
                }

                $config->products()->sync($productIds);
            });

            $this->logChanges($config, $before, sprintf('Penalty configuration "%s" updated', $config->name));

            return $config;
        });
    }

    public function setStatus(PenaltyConfig $config, string $status): PenaltyConfig
    {
        return $this->transaction(function () use ($config, $status): PenaltyConfig {
            $config = $this->lockConfig($config);
            $before = $this->auditState($config);

            if ($status === PenaltyConfig::STATUS_ACTIVE) {
                $productIds = $before['applicable_product_ids'];
                $this->assertHasProducts($productIds);
                $this->assertNoActiveClash($productIds, $config->id);
            }

            AuditLogService::withoutModelAuditing(fn () => $config->update(['status' => $status]));

            $this->logChanges($config, $before, sprintf(
                'Penalty configuration "%s" %s',
                $config->name,
                $status === PenaltyConfig::STATUS_ACTIVE ? 'activated' : 'deactivated',
            ));

            return $config;
        });
    }

    public function delete(PenaltyConfig $config): void
    {
        $this->transaction(function () use ($config): void {
            $config = $this->lockConfig($config);
            $loans = Loan::query()->where('penalty_config_id', $config->id)->count();

            if ($loans > 0) {
                throw LoanWriteTransaction::conflict(self::inUseMessage($loans));
            }

            $before = $this->auditState($config);

            AuditLogService::withoutModelAuditing(fn () => $config->delete());

            AuditLogService::log('deleted', $config, $before, null, sprintf(
                'Penalty configuration "%s" deleted: %s. Products: %s',
                $before['name'],
                $before['conditions'],
                $this->productNames($before['applicable_product_ids']),
            ));
        });
    }

    public static function inUseMessage(int $loans): string
    {
        $count = $loans === 1 ? '1 loan' : "{$loans} loans";

        return "This configuration is on {$count}, so it can't be deleted. Deactivate it instead.";
    }

    /**
     * Runs `$callback` in a transaction, answering a collision 409. A delete
     * racing a loan created under the configuration hits the loans foreign
     * key (1451): that is the in-use refusal too.
     */
    private function transaction(callable $callback): mixed
    {
        try {
            return LoanWriteTransaction::run($callback(...), 'penalty_configs_name_unique', self::CONFLICT);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1451) {
                throw LoanWriteTransaction::conflict("This configuration is on a loan, so it can't be deleted. Deactivate it instead.");
            }

            throw $e;
        }
    }

    private function lockConfig(PenaltyConfig $config): PenaltyConfig
    {
        $locked = PenaltyConfig::query()->whereKey($config->getKey())->lockForUpdate()->first();

        if ($locked === null) {
            throw LoanWriteTransaction::conflict('This configuration was deleted at the same time. Reload and try again.');
        }

        return $locked;
    }

    /**
     * Locks the products in id order, then refuses any already in another
     * active configuration. The clash read is a locking read, so it sees
     * what a save that held the locks before this one committed.
     *
     * @param  list<int>  $productIds
     */
    private function assertNoActiveClash(array $productIds, ?int $exceptConfigId): void
    {
        if ($productIds === []) {
            return;
        }

        $products = LoanProduct::query()->whereKey($productIds)->orderBy('id')->lockForUpdate()->get(['id', 'name'])->keyBy('id');

        $clashes = DB::table('penalty_config_products')
            ->join('penalty_configs', 'penalty_configs.id', '=', 'penalty_config_products.penalty_config_id')
            ->where('penalty_configs.status', PenaltyConfig::STATUS_ACTIVE)
            ->whereIn('penalty_config_products.loan_product_id', $productIds)
            ->when($exceptConfigId !== null, fn ($q) => $q->where('penalty_configs.id', '!=', $exceptConfigId))
            ->orderBy('penalty_config_products.loan_product_id')
            ->sharedLock()
            ->get(['penalty_config_products.loan_product_id', 'penalty_configs.name']);

        if ($clashes->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'applicable_product_ids' => $clashes->map(fn (object $clash): string => sprintf(
                '%s is already covered by the active configuration "%s".',
                $products->get($clash->loan_product_id)?->name ?? 'A product',
                $clash->name,
            ))->all(),
        ]);
    }

    /** @param  list<int>  $productIds */
    private function assertHasProducts(array $productIds): void
    {
        if ($productIds === []) {
            throw ValidationException::withMessages([
                'applicable_product_ids' => ['An active configuration needs at least one product.'],
            ]);
        }
    }

    /**
     * @param  list<array{days_from: int, days_to: int|null, rate: int|float|string}>  $conditions
     */
    private function writeBrackets(PenaltyConfig $config, array $conditions): void
    {
        $rows = $conditions;
        usort($rows, fn (array $a, array $b): int => (int) $a['days_from'] <=> (int) $b['days_from']);

        foreach ($rows as $row) {
            $config->conditions()->create([
                'days_from' => (int) $row['days_from'],
                'days_to' => $row['days_to'] === null ? null : (int) $row['days_to'],
                // As sent: the column keeps its 4 decimals, never rounded.
                'rate' => (string) $row['rate'],
            ]);
        }

        $config->unsetRelation('conditions');
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    private function productIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }

    /**
     * What an audit row records of a configuration: its name, status,
     * products and brackets in words.
     *
     * @return array{name: string, status: string, applicable_product_ids: list<int>, conditions: string}
     */
    private function auditState(PenaltyConfig $config): array
    {
        $config->unsetRelation('conditions');

        return [
            'name' => $config->name,
            'status' => $config->status,
            'applicable_product_ids' => $this->productIds($config->products()->pluck('loan_products.id')->all()),
            'conditions' => BracketRate::inWords($config->conditions()->get(['days_from', 'days_to', 'rate'])->toArray()),
        ];
    }

    /**
     * One `updated` row with the fields that changed, or none when nothing
     * did.
     *
     * @param  array{name: string, status: string, applicable_product_ids: list<int>, conditions: string}  $before
     */
    private function logChanges(PenaltyConfig $config, array $before, string $description): void
    {
        $after = $this->auditState($config->refresh());
        $changed = array_keys(array_filter($after, fn (mixed $value, string $key): bool => $value !== $before[$key], ARRAY_FILTER_USE_BOTH));

        if ($changed === []) {
            return;
        }

        AuditLogService::log(
            'updated',
            $config,
            array_intersect_key($before, array_flip($changed)),
            array_intersect_key($after, array_flip($changed)),
            $description,
        );
    }

    /** @param  list<int>  $productIds */
    private function productNames(array $productIds): string
    {
        if ($productIds === []) {
            return 'none';
        }

        return LoanProduct::query()->whereKey($productIds)->orderBy('name')->pluck('name')->implode(', ');
    }
}
