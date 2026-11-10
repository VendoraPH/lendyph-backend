<?php

namespace Tests\Feature\Cda\Outputs;

use App\Services\Cda\PeriodOpener;
use App\Services\Cda\Sources\SourceCatalog;
use App\Services\Cda\Sources\SourceContext;
use App\Services\Cda\Sources\SourceRegistry;
use App\Services\Cda\Sources\SourceResult;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Every key in SourceCatalog resolves (CDA design §2, C4): its family has a
 * resolver, the resolver knows the key, and the value has the catalogue's
 * unit. Keys whose family another slice builds are listed in
 * PENDING_FAMILIES until that slice merges; the lead empties the list then.
 */
class SourceCatalogResolvesTest extends TestCase
{
    use SetupLendyPH;

    /** Families built by C2 (core sources) and C3 (registers), not merged into this branch yet. */
    private const PENDING_FAMILIES = [
        'members', 'loans', 'share_capital', 'savings', 'accounting', 'fs', 'report',
        'officers', 'social', 'mediation',
    ];

    /** Params for keys that require one. */
    private const PARAMS = [
        'profile.field' => ['name' => 'cda_registration_no'],
        'members.missing' => ['field' => 'membership_date'],
        'accounting.balance' => ['code' => '1200'],
        'accounting.flow' => ['code' => '3073'],
        'officers.current' => ['position' => 'chairperson'],
        'social_programs.summary' => ['measure' => 'count'],
        'report.field' => ['type' => 'capr', 'field' => 'membership.members_total'],
        'pesos.result' => ['field' => 'score'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_every_catalogue_key_resolves_with_its_unit(): void
    {
        $context = new SourceContext(app(PeriodOpener::class)->ensure(2026));
        $registry = app(SourceRegistry::class);
        $checked = 0;

        foreach (array_keys(SourceCatalog::KEYS) as $key) {
            if (in_array(SourceCatalog::family($key), self::PENDING_FAMILIES, true)) {
                continue;
            }

            $params = self::PARAMS[$key] ?? [];
            $this->assertTrue($registry->has($key), "{$key} has a resolver.");
            $result = $registry->resolve($key, $params, $context);
            $this->assertInstanceOf(SourceResult::class, $result);
            $this->assertNotSame('', $result->label, "{$key} has a label.");
            $this->assertValueFits($key, SourceCatalog::unitFor($key, $params), $result);
            $checked++;
        }

        $this->assertGreaterThanOrEqual(12, $checked);
    }

    public function test_the_pending_list_names_only_known_families(): void
    {
        $this->assertSame([], array_values(array_diff(self::PENDING_FAMILIES, SourceCatalog::FAMILIES)));
    }

    private function assertValueFits(string $key, string $unit, SourceResult $result): void
    {
        $value = $result->value;
        $fits = match ($unit) {
            'count' => is_int($value),
            'money' => is_string($value) && preg_match('/^-?\d+\.\d{2}$/', $value) === 1,
            'percent' => $value === null || (is_string($value) && preg_match('/^-?\d+\.\d{2}$/', $value) === 1),
            'number' => $value === null || is_int($value) || (is_string($value) && is_numeric($value)),
            'text' => $value === null || is_string($value),
            'date' => $value === null || (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1),
            'boolean' => is_bool($value),
            'table' => is_array($value) && array_is_list($value),
            default => true,
        };

        $this->assertTrue($fits, "{$key} gives a {$unit}; got ".json_encode($value).'.');
    }
}
