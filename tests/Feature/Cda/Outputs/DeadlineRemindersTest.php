<?php

namespace Tests\Feature\Cda\Outputs;

use App\Models\CdaNoticeSent;
use App\Models\CdaReport;
use App\Models\CdaReportingPeriod;
use App\Models\User;
use App\Services\Cda\PeriodOpener;
use App\Services\Cda\Reminders\DeadlineReminders;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\Cda\Foundation\CdaTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * `cda:send-reminders` (CDA design §10, 00:50 Manila): 30, 15, 7 and 3 days
 * before a report's effective deadline, on the day, and once overdue. Each
 * milestone goes out once per report and deadline; a missed run sends only
 * the latest milestone crossed; an extension starts them again; filed and
 * not-applicable reports are skipped. In-app only, to active users holding
 * `cda:prepare` or `cda:approve` with `branches:view_all`, and the restricted
 * view permission where the report needs one.
 */
class DeadlineRemindersTest extends TestCase
{
    use CdaTestHelpers, SetupLendyPH;

    /** The CY2026 annual reports a preparer without restricted permissions sees (no CTE, so no ATIR or ABR). */
    private const OPEN_ANNUAL = ['capr', 'social_audit', 'pesos', 'officers_trainings', 'sworn_statement'];

    private CdaReportingPeriod $period;

    private User $preparer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00', 'Asia/Manila'));
        $this->period = app(PeriodOpener::class)->ensure(2026);
        $this->preparer = $this->userWith(['cda:prepare', 'branches:view_all']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function runOn(string $date): void
    {
        Carbon::setTestNow(Carbon::parse($date.' 00:50:00', 'Asia/Manila'));
        $this->artisan('cda:send-reminders')->assertSuccessful();
    }

    /** @return list<array<string, mixed>> the user's CDA reminders */
    private function reminders(User $user): array
    {
        return $user->notifications()->where('type', DeadlineReminders::TYPE)->get()
            ->map(fn ($notification) => $notification->data)->all();
    }

    public function test_each_milestone_is_sent_once(): void
    {
        $this->runOn('2027-03-31');

        $sent = $this->reminders($this->preparer);
        $this->assertCount(5, $sent);
        $this->assertEqualsCanonicalizing(self::OPEN_ANNUAL, array_column(array_column($sent, 'data'), 'report_type'));
        $capr = collect($sent)->firstWhere('data.report_type', 'capr');
        $this->assertSame('CDA report due in 30 days', $capr['title']);
        $this->assertStringContainsString('Cooperative Annual Progress Report (CAPR) for CY2026 is due on Apr 30, 2027', $capr['body']);
        $this->assertSame('/cda/capr?year=2026', $capr['url']);

        $this->runOn('2027-03-31');
        $this->runOn('2027-04-01');
        $this->assertCount(5, $this->reminders($this->preparer));

        $this->runOn('2027-04-15');
        $this->assertCount(10, $this->reminders($this->preparer));
    }

    public function test_a_missed_run_sends_only_the_latest_milestone_crossed(): void
    {
        $this->runOn('2027-04-26');

        $sent = $this->reminders($this->preparer);
        $this->assertCount(5, $sent);
        $this->assertSame(['days_7'], array_values(array_unique(array_column(array_column($sent, 'data'), 'milestone'))));

        $this->runOn('2027-04-27');
        $this->assertSame(['days_3' => 5, 'days_7' => 5], collect($this->reminders($this->preparer))->countBy('data.milestone')->sortKeys()->all());
    }

    public function test_the_due_day_and_overdue_are_sent_once(): void
    {
        $this->runOn('2027-04-30');
        $due = collect($this->reminders($this->preparer))->firstWhere('data.report_type', 'capr');
        $this->assertSame('due', $due['data']['milestone']);
        $this->assertSame('CDA report due today', $due['title']);

        $this->runOn('2027-05-02');
        $this->runOn('2027-05-20');
        $overdue = collect($this->reminders($this->preparer))->where('data.report_type', 'capr')->where('data.milestone', 'overdue');
        $this->assertCount(1, $overdue);
        $this->assertSame('CDA report overdue', $overdue->first()['title']);
    }

    public function test_an_extension_starts_the_milestones_again(): void
    {
        $this->runOn('2027-03-31');
        $this->period->forceFill(['extended_deadline' => '2027-05-31', 'extension_reference' => 'CDA-EXT-1'])->save();

        $this->runOn('2027-05-01');

        $capr = collect($this->reminders($this->preparer))->where('data.report_type', 'capr')->values();
        $this->assertCount(2, $capr);
        $this->assertSame(['2027-04-30' => 'days_30', '2027-05-31' => 'days_30'], $capr->pluck('data.milestone', 'data.deadline')->sortKeys()->all());
        $this->assertSame(2, CdaNoticeSent::query()->where('kind', DeadlineReminders::KIND)
            ->where('subject_id', CdaReport::query()->where('period_id', $this->period->id)->where('report_type', 'capr')->value('id'))->count());
    }

    public function test_filed_and_not_applicable_reports_are_skipped(): void
    {
        CdaReport::query()->where('period_id', $this->period->id)->where('report_type', 'capr')->update(['status' => CdaReport::STATUS_SUBMITTED]);
        CdaReport::query()->where('period_id', $this->period->id)->where('report_type', 'pesos')->update(['status' => CdaReport::STATUS_ACCEPTED]);
        CdaReport::query()->where('period_id', $this->period->id)->where('report_type', 'sworn_statement')->update(['status' => CdaReport::STATUS_FOR_CORRECTION]);
        $taxViewer = $this->userWith(['cda:prepare', 'branches:view_all', 'cda:view_tax']);

        $this->runOn('2027-03-31');

        $types = array_column(array_column($this->reminders($this->preparer), 'data'), 'report_type');
        $this->assertEqualsCanonicalizing(['social_audit', 'officers_trainings', 'sworn_statement'], $types, 'A report sent back for correction still needs work.');
        $this->assertNotContains('atir', array_column(array_column($this->reminders($taxViewer), 'data'), 'report_type'), 'No CTE: ATIR does not apply.');
    }

    public function test_recipients_are_filtered_by_permission_branch_access_and_status(): void
    {
        $approver = $this->userWith(['cda:approve', 'branches:view_all']);
        $mediator = $this->userWith(['cda:prepare', 'branches:view_all', 'cda:view_mediation']);
        $noViewAll = $this->userWith(['cda:prepare', 'cda:view_mediation']);
        $viewer = $this->userWith(['cda:view', 'branches:view_all', 'cda:view_mediation']);
        $inactive = $this->userWith(['cda:prepare', 'branches:view_all', 'cda:view_mediation']);
        $inactive->update(['status' => 'inactive']);

        $this->runOn('2027-01-27');

        $mediation = fn (User $user) => collect($this->reminders($user))->where('data.report_type', 'mediation')->values();
        $this->assertSame(['h1' => 'overdue', 'h2' => 'days_3'], $mediation($mediator)->pluck('data.milestone', 'data.part')->sortKeys()->all());
        $this->assertStringContainsString('Second half (July–December)', $mediation($mediator)->firstWhere('data.part', 'h2')['body']);
        $this->assertCount(0, $mediation($this->preparer), 'Mediation needs cda:view_mediation.');
        $this->assertCount(0, $mediation($approver));
        $this->assertCount(0, $this->reminders($noViewAll));
        $this->assertCount(0, $this->reminders($viewer));
        $this->assertCount(0, $this->reminders($inactive));
    }

    public function test_it_is_scheduled_at_0050_manila(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn (Event $event) => Str::contains((string) $event->command, 'cda:send-reminders'));

        $this->assertNotNull($event);
        $this->assertSame('50 0 * * *', $event->getExpression());
        $this->assertSame('Asia/Manila', $event->timezone instanceof \DateTimeZone ? $event->timezone->getName() : $event->timezone);
    }
}
