<?php

namespace App\Services\Linda;

use App\Models\LindaSetting;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Linda\Model\LindaModelUnavailable;
use App\Services\Linda\Model\LindaPrices;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Linda (Linda v1 plan): the model Linda uses, one of the price
 * table's (`linda.models`), stored in the one `linda_settings` row. With no
 * row, or a stored model the table no longer lists, it is `linda.model`.
 *
 * The key, the monthly cap and the on/off switch stay in the server's config;
 * this only reports whether they let the model run (aiStatus()).
 */
final class LindaSettings
{
    /** `LINDA_ENABLED` is false: Linda answers nothing. */
    public const REASON_LINDA_OFF = 'linda_off';

    /** The none driver: only the matcher answers, nothing leaves the server. */
    public const REASON_MODEL_OFF = 'model_off';

    /** The fake driver, for tests and local runs. */
    public const REASON_TEST_DRIVER = 'test_driver';

    /** The anthropic driver with no key set. */
    public const REASON_NO_KEY = 'no_key';

    /** A price, the exchange rate or the monthly cap isn't a plain amount: no model call is made. */
    public const REASON_NO_PRICES = 'no_prices';

    /** The one settings row's id, so two first saves can never make two rows. */
    public const ROW_ID = 1;

    public function __construct(private readonly LindaBudget $budget) {}

    public function row(): ?LindaSetting
    {
        return LindaSetting::query()->find(self::ROW_ID);
    }

    /** The model id Linda uses for its next model question. */
    public function model(): string
    {
        $stored = $this->row()?->model;

        return $stored !== null && $this->offers($stored) ? $stored : (string) config('linda.model');
    }

    public function offers(string $model): bool
    {
        return array_key_exists($model, (array) config('linda.models'));
    }

    /**
     * Save the model and write one audit row in words. Saving the model already
     * in use changes nothing and writes nothing.
     *
     * The row is always id 1: it is inserted if missing (a duplicate is
     * ignored), then read under a row lock, so two first saves at once queue
     * on the same row instead of making two or answering 500.
     */
    public function update(string $model, User $by): LindaSetting
    {
        return DB::transaction(function () use ($model, $by): LindaSetting {
            DB::table('linda_settings')->insertOrIgnore([
                'id' => self::ROW_ID,
                'model' => (string) config('linda.model'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = LindaSetting::query()->whereKey(self::ROW_ID)->lockForUpdate()->firstOrFail();
            $old = $this->offers($row->model) ? $row->model : (string) config('linda.model');

            if ($row->model === $model && $row->updated_by !== null) {
                return $row;
            }

            $row->forceFill(['model' => $model, 'updated_by' => $by->getKey()])->save();

            if ($old !== $model) {
                AuditLogService::log(
                    action: 'updated',
                    auditable: $row,
                    oldValues: ['model' => $old],
                    newValues: ['model' => $model],
                    description: sprintf('Linda model changed from %s to %s', $this->label($old), $this->label($model)),
                    userId: (int) $by->getKey(),
                );
            }

            return $row;
        });
    }

    /**
     * Whether this server lets the model run, and if not, why: the switch on,
     * prices, the exchange rate and the cap that can be read, the anthropic
     * driver and a key set. Never says anything about the key beyond whether
     * one is set.
     *
     * @return array{on: bool, reason: ?string}
     */
    public function aiStatus(): array
    {
        $reason = match (true) {
            ! config('linda.enabled') => self::REASON_LINDA_OFF,
            ! $this->pricesReadable() => self::REASON_NO_PRICES,
            config('linda.driver') === 'fake' => self::REASON_TEST_DRIVER,
            config('linda.driver') !== 'anthropic' => self::REASON_MODEL_OFF,
            trim((string) config('linda.anthropic.api_key')) === '' => self::REASON_NO_KEY,
            default => null,
        };

        return ['on' => $reason === null, 'reason' => $reason];
    }

    /**
     * The models on offer, in the price table's order, with peso prices per
     * million tokens and "about ₱X a question" at `linda.typical_question`,
     * all from LindaPrices (USD list price × `linda.usd_to_php`, exact, half
     * up to the centavo). When a price or the rate can't be read, every price
     * field is null (and aiStatus() says `no_prices`), never an error.
     *
     * @return list<array{id: string, label: string, description: string, input_per_million: ?string, output_per_million: ?string, per_question: ?string}>
     */
    public function models(): array
    {
        $models = [];

        foreach ((array) config('linda.models') as $id => $entry) {
            $id = (string) $id;
            $entry = is_array($entry) ? $entry : [];

            try {
                $perMillion = LindaPrices::perMillion($id);
                $prices = [$perMillion['input'], $perMillion['output'], LindaPrices::perQuestion($id)];
            } catch (LindaModelUnavailable) {
                $prices = [null, null, null];
            }

            $models[] = [
                'id' => $id,
                'label' => (string) ($entry['label'] ?? $id),
                'description' => (string) ($entry['description'] ?? ''),
                'input_per_million' => $prices[0],
                'output_per_million' => $prices[1],
                'per_question' => $prices[2],
            ];
        }

        return $models;
    }

    /** Whether every price, the exchange rate and the monthly cap can be read. */
    private function pricesReadable(): bool
    {
        try {
            foreach (array_keys((array) config('linda.models')) as $id) {
                LindaPrices::perMillion((string) $id);
            }
        } catch (LindaModelUnavailable) {
            return false;
        }

        return (array) config('linda.models') !== [] && $this->budget->capOrNull() !== null;
    }

    /** A model's label, or its id when the price table doesn't list it. */
    public function label(string $model): string
    {
        return (string) (config("linda.models.{$model}.label") ?? $model);
    }
}
