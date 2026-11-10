<?php

namespace Tests\Feature\Cda\Foundation;

use App\Models\Branch;
use App\Models\BrandingSetting;
use App\Models\CdaRegulatoryProfile;
use App\Models\CdaReportingPeriod;
use App\Services\Cda\PeriodOpener;
use App\Services\Cda\Sources\SourceCatalog;
use App\Services\Cda\Sources\SourceContext;
use App\Services\Cda\Sources\SourceRegistry;
use App\Services\Cda\Sources\SourceResult;
use App\Services\Cda\Sources\SourceUnavailable;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The source contracts C2–C4 build on (CDA design §2): the catalogue holds
 * every v1 key with its family, unit and params; resolvers are found by
 * convention (`Cda\Sources\{Family}Source`) and memoized per context; params
 * are checked and defaulted before a resolver sees them; dates resolve from
 * the period. C1 implements the coop and profile families.
 */
class CdaSourceContractsTest extends TestCase
{
    use SetupLendyPH;

    private const V1_KEYS = [
        'coop.name', 'coop.address', 'coop.contact', 'profile.field', 'branches.count', 'branches.list', 'users.count',
        'members.count', 'members.new', 'members.ended', 'members.missing',
        'loans.released', 'loans.outstanding', 'loans.past_due', 'loans.par', 'loans.by_product', 'loans.classification',
        'loans.provision_required', 'loans.allowance_booked', 'loans.provision_gap', 'loans.collection_efficiency',
        'share_capital.paid_up', 'share_capital.holders', 'share_capital.movements',
        'savings.balance', 'savings.accounts', 'savings.depositors',
        'accounting.year_closed', 'accounting.balance', 'accounting.flow',
        'fs.balance_sheet', 'fs.total_assets', 'fs.total_liabilities', 'fs.total_equity', 'fs.is_balanced',
        'fs.income_statement', 'fs.total_income', 'fs.total_expenses', 'fs.net_surplus', 'fs.cash_flow', 'fs.equity_changes',
        'officers.current', 'officers.list', 'officers.count', 'trainings.summary',
        'social_programs.list', 'social_programs.summary', 'mediation.cases', 'mediation.summary',
        'report.field', 'pesos.result',
    ];

    private CdaReportingPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2027-02-01 09:00:00', 'Asia/Manila'));
        $this->period = app(PeriodOpener::class)->open(2026, $this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_catalogue_holds_every_v1_key_with_its_family_unit_and_params(): void
    {
        $this->assertEqualsCanonicalizing(self::V1_KEYS, array_keys(SourceCatalog::KEYS));

        foreach (SourceCatalog::KEYS as $key => $entry) {
            $this->assertContains($entry['family'], SourceCatalog::FAMILIES, $key);
            $this->assertContains($entry['unit'], SourceCatalog::UNITS, $key);
            $this->assertIsArray($entry['params'], $key);
            $this->assertNotSame('', $entry['label'], $key);
        }

        $this->assertSame('classification', SourceCatalog::KEYS['loans.provision_gap']['family']);
        $this->assertSame('count', SourceCatalog::unitFor('loans.released', ['measure' => 'count']));
        $this->assertSame('money', SourceCatalog::unitFor('loans.released', ['measure' => 'amount']));
    }

    public function test_params_are_checked_and_defaulted_and_dates_resolve_from_the_period(): void
    {
        $this->assertSame(['as_of' => 'period_end'], SourceCatalog::normalize('members.count', []));
        $this->assertSame([], SourceCatalog::errors('members.count', ['gender' => 'female', 'age_from' => 18]));
        $this->assertNotSame([], SourceCatalog::errors('members.count', ['gender' => 'other']));
        $this->assertNotSame([], SourceCatalog::errors('accounting.balance', []));
        $this->assertNotSame([], SourceCatalog::errors('loans.par', ['days' => 45]));

        $context = new SourceContext($this->period);
        $this->assertSame('2026-12-31', $context->date('period_end'));
        $this->assertSame('2026-01-01', $context->date('period_start'));
        $this->assertSame('2026-06-30', $context->date('h1_end'));
        $this->assertSame('2026-07-01', $context->date('h2_start'));
        $this->assertSame('2025-12-31', $context->date('prior_period_end'));
        $this->assertSame('2026-03-15', $context->date('2026-03-15'));

        $this->expectException(InvalidArgumentException::class);
        app(SourceRegistry::class)->resolve('members.count', ['gender' => 'other'], $context);
    }

    public function test_coop_and_profile_resolve_with_a_label_and_are_memoized_per_context(): void
    {
        BrandingSetting::current()->update(['organization_name' => 'BINHS MPC', 'organization_address' => 'Butuan City', 'organization_contact' => '0917 000 0000']);
        CdaRegulatoryProfile::current()->update(['cda_registration_no' => '9520-16000123']);
        $registry = app(SourceRegistry::class);
        $context = new SourceContext($this->period);

        $name = $registry->resolve('coop.name', [], $context);
        $this->assertInstanceOf(SourceResult::class, $name);
        $this->assertSame('BINHS MPC', $name->value);
        $this->assertSame('text', $name->unit);
        $this->assertSame('Settings › Branding', $name->label);
        $this->assertFalse($name->provisional);

        $this->assertSame(Branch::query()->where('is_active', true)->count(), $registry->resolve('branches.count', [], $context)->value);
        $this->assertSame('9520-16000123', $registry->resolve('profile.field', ['name' => 'cda_registration_no'], $context)->value);
        $this->assertSame(['value', 'label', 'unit', 'as_of', 'provisional', 'meta'], array_keys($name->toArray()));

        BrandingSetting::current()->update(['organization_name' => 'Renamed']);
        $this->assertSame('BINHS MPC', $registry->resolve('coop.name', [], $context)->value, 'Memoized for the context.');
        $this->assertSame('Renamed', $registry->resolve('coop.name', [], new SourceContext($this->period))->value);
    }

    public function test_a_family_whose_resolver_isnt_built_yet_says_so(): void
    {
        $this->assertTrue(app(SourceRegistry::class)->has('coop.name'));
        $this->assertSame('App\\Services\\Cda\\Sources\\ShareCapitalSource', SourceRegistry::resolverClass('share_capital.paid_up'));

        if (! class_exists(SourceRegistry::resolverClass('pesos.result'))) {
            $this->expectException(SourceUnavailable::class);
            app(SourceRegistry::class)->resolve('pesos.result', ['field' => 'score'], new SourceContext($this->period));
        } else {
            $this->assertTrue(app(SourceRegistry::class)->has('pesos.result'));
        }
    }

    public function test_the_label_reads_what_and_as_of_when(): void
    {
        $this->assertSame('Members · as of Dec 31, 2026', SourceResult::labelFor('Members', '2026-12-31'));
        $this->assertSame('Members', SourceResult::labelFor('Members', null));
    }
}
