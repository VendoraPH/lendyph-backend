<?php

namespace Tests\Feature\Phase6\Amla\Detection;

use App\Models\AmlaAlert;
use App\Models\AmlaCtrCandidate;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Document;
use App\Models\GCashNonMember;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The detection rules (AMLA design §4.6–4.7, AMLA-B3), each at, below and
 * above its starting point: CTR candidates (one cash transaction above
 * ₱500,000.00, exactly ₱500,000.00 doesn't), structuring (same-day sums,
 * never a CTR) and categories B–F and H–K. G stays inactive and L waits for
 * screening. Every alert cites the rule version it ran under.
 */
class AmlaDetectionRulesTest extends TestCase
{
    use DetectionTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->startDetection();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_ctr_candidate_is_one_cash_transaction_above_the_threshold_and_exactly_the_threshold_is_not(): void
    {
        $below = $this->event($this->member(), '499999.99');
        $at = $this->event($this->member(), '500000.00');
        $above = $this->event($this->member(), '500000.01');
        $out = $this->event($this->member(), '750000.00', ['direction' => 'out', 'kind' => 'savings_withdrawal']);
        $bank = $this->event($this->member(), '900000.00', ['channel' => 'bank_transfer']);
        $internal = $this->event($this->member(), '900000.00', ['is_internal' => true, 'kind' => 'savings_transfer_in', 'channel' => 'savings']);

        $this->detect();

        $ids = AmlaCtrCandidate::query()->pluck('event_id')->all();
        $this->assertEqualsCanonicalizing([$above->id, $out->id], $ids);
        $candidate = AmlaCtrCandidate::query()->where('event_id', $above->id)->sole();
        $this->assertSame(['500000.01', '500000.00', 1, 'detected', 'cash'], [$candidate->amount, $candidate->threshold, $candidate->settings_version, $candidate->status, $candidate->channel]);
        $this->assertStringContainsString('above the CTR threshold of ₱500,000.00', $candidate->explanation);
        $this->assertMatchesRegularExpression('/^CTR-20261120-\d{6}$/', $candidate->reference);
        $this->assertNotContains($below->id, $ids);
        $this->assertNotContains($at->id, $ids);
        $this->assertNotContains($bank->id, $ids);
        $this->assertNotContains($internal->id, $ids);
    }

    public function test_structuring_is_same_day_sums_above_the_threshold_of_transactions_at_or_below_it_and_never_a_ctr(): void
    {
        $below = $this->member();
        $this->event($below, '200000.00');
        $this->event($below, '200000.00');
        $at = $this->member();
        $this->event($at, '250000.00');
        $this->event($at, '250000.00');
        $above = $this->member();
        $first = $this->event($above, '500000.00');
        $second = $this->event($above, '0.01');
        $otherDay = $this->member();
        $this->event($otherDay, '300000.00');
        $this->event($otherDay, '300000.00', ['occurred_on' => '2026-11-19']);

        $this->detect();

        $alerts = $this->alerts('A');
        $this->assertCount(1, $alerts);
        $this->assertSame([$above->id, 'high', 1, 'open'], [$alerts[0]->borrower_id, $alerts[0]->priority, $alerts[0]->rule_version, $alerts[0]->status]);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $alerts[0]->events()->pluck('amla_events.id')->all());
        $this->assertStringContainsString('2 cash transactions', $alerts[0]->reason);
        $this->assertSame(0, AmlaCtrCandidate::query()->count());
    }

    public function test_unusual_frequency_fires_at_ten_customer_transactions_in_a_week(): void
    {
        foreach ([9 => 0, 10 => 1, 11 => 1] as $count => $expected) {
            $member = $this->member();
            foreach (range(1, $count) as $i) {
                $this->event($member, '1000.00', ['occurred_on' => '2026-11-'.(16 + $i % 5)]);
            }
            $this->detect();
            $this->assertCount($expected, AmlaAlert::query()->where('rule_code', 'B')->where('borrower_id', $member->id)->get(), "{$count} transactions");
        }
    }

    public function test_unusual_volume_compares_one_transaction_with_the_members_own_average(): void
    {
        foreach (['49999.99' => 0, '50000.00' => 1, '50000.01' => 1] as $amount => $expected) {
            $member = $this->member();
            foreach (['2026-10-01', '2026-10-15', '2026-11-01'] as $day) {
                $this->event($member, '10000.00', ['occurred_on' => $day]);
            }
            $this->event($member, (string) $amount);
            $this->detect();
            $this->assertCount($expected, AmlaAlert::query()->where('rule_code', 'C')->where('borrower_id', $member->id)->get(), $amount);
        }

        $noHistory = $this->member();
        $this->event($noHistory, '400000.00');
        $this->detect();
        $this->assertCount(0, AmlaAlert::query()->where('rule_code', 'C')->where('borrower_id', $noHistory->id)->get());
    }

    public function test_income_mismatch_compares_a_months_money_in_with_the_members_monthly_income(): void
    {
        foreach (['119999.99' => 0, '120000.00' => 0, '120000.01' => 1] as $amount => $expected) {
            $member = $this->member(attributes: ['monthly_income' => 40000]);
            $this->event($member, (string) $amount, ['occurred_on' => '2026-11-03', 'channel' => 'bank_transfer']);
            $this->detect();
            $this->assertCount($expected, AmlaAlert::query()->where('rule_code', 'D')->where('borrower_id', $member->id)->get(), $amount);
        }

        $noIncome = $this->member(attributes: ['monthly_income' => null]);
        $this->event($noIncome, '450000.00', ['channel' => 'bank_transfer']);
        $this->detect();
        $this->assertCount(0, AmlaAlert::query()->where('rule_code', 'D')->where('borrower_id', $noIncome->id)->get());
    }

    public function test_rapid_movement_fires_when_most_of_a_large_receipt_leaves_within_two_days(): void
    {
        foreach (['79999.99' => 0, '80000.00' => 1, '80000.01' => 1] as $out => $expected) {
            $member = $this->member();
            $this->event($member, '100000.00', ['occurred_on' => '2026-11-18', 'channel' => 'bank_transfer']);
            $this->event($member, (string) $out, ['direction' => 'out', 'kind' => 'savings_withdrawal', 'channel' => 'bank_transfer']);
            $this->detect();
            $this->assertCount($expected, AmlaAlert::query()->where('rule_code', 'E')->where('borrower_id', $member->id)->get(), $out);
        }
    }

    public function test_repeated_deposits_and_withdrawals_fire_at_three_of_each_in_a_week(): void
    {
        foreach ([[3, 2, 0], [3, 3, 1], [4, 4, 1]] as [$deposits, $withdrawals, $expected]) {
            $member = $this->member();
            foreach (range(1, $deposits) as $i) {
                $this->event($member, '10000.00', ['channel' => 'bank_transfer', 'occurred_on' => '2026-11-'.(16 + $i)]);
            }
            foreach (range(1, $withdrawals) as $i) {
                $this->event($member, '10000.00', ['direction' => 'out', 'kind' => 'savings_withdrawal', 'channel' => 'bank_transfer', 'occurred_on' => '2026-11-'.(16 + $i)]);
            }
            $this->detect();
            $this->assertCount($expected, AmlaAlert::query()->where('rule_code', 'F')->where('borrower_id', $member->id)->get(), "{$deposits}/{$withdrawals}");
        }
    }

    public function test_gcash_anomalies_fire_at_five_gcash_transactions_or_one_hundred_thousand_pesos_in_a_day(): void
    {
        foreach ([4 => 0, 5 => 1, 6 => 1] as $count => $expected) {
            $member = $this->member();
            foreach (range(1, $count) as $i) {
                $this->event($member, '1000.00', ['source' => 'gcash', 'kind' => 'gcash_cash_in']);
            }
            $this->detect();
            $this->assertCount($expected, AmlaAlert::query()->where('rule_code', 'H')->where('borrower_id', $member->id)->get(), "{$count} GCash");
        }

        foreach (['99999.99' => 0, '100000.00' => 1] as $amount => $expected) {
            $member = $this->member();
            $this->event($member, (string) $amount, ['source' => 'gcash', 'kind' => 'gcash_cash_in']);
            $this->detect();
            $this->assertCount($expected, AmlaAlert::query()->where('rule_code', 'H')->where('borrower_id', $member->id)->get(), $amount);
        }
    }

    public function test_a_large_cash_payment_soon_after_a_release_is_a_loan_review_alert(): void
    {
        foreach (['99999.99' => 0, '100000.00' => 1, '100000.01' => 1] as $amount => $expected) {
            $member = $this->member();
            $this->event($member, '200000.00', ['source' => 'loan', 'kind' => 'loan_release', 'direction' => 'out', 'occurred_on' => '2026-11-10']);
            $this->event($member, (string) $amount, ['source' => 'repayment', 'kind' => 'loan_payment']);
            $this->detect();
            $alerts = AmlaAlert::query()->where('rule_code', 'I')->where('borrower_id', $member->id)->get();
            $this->assertCount($expected, $alerts, $amount);
            $expected === 1 && $this->assertSame('low', $alerts[0]->priority);
        }
    }

    public function test_cross_branch_activity_fires_at_three_branches_in_a_week_and_is_head_offices(): void
    {
        $branches = [$this->branch->id, Branch::factory()->create()->id, Branch::factory()->create()->id, Branch::factory()->create()->id];

        foreach ([2 => 0, 3 => 1, 4 => 1] as $count => $expected) {
            $member = $this->member();
            foreach (array_slice($branches, 0, $count) as $branchId) {
                $this->event($member, '1000.00', ['branch_id' => $branchId]);
            }
            $this->detect();
            $alerts = AmlaAlert::query()->where('rule_code', 'J')->where('borrower_id', $member->id)->get();
            $this->assertCount($expected, $alerts, "{$count} branches");
            $expected === 1 && $this->assertNull($alerts[0]->branch_id);
        }
    }

    public function test_a_cash_transaction_above_the_review_amount_with_no_id_on_file_is_a_category_k_alert(): void
    {
        foreach (['50000.00' => 0, '50000.01' => 1] as $amount => $expected) {
            $walkIn = GCashNonMember::factory()->create(['id_type' => null, 'id_number' => null]);
            $this->event($this->member(), (string) $amount, ['borrower_id' => null, 'gcash_non_member_id' => $walkIn->id, 'source' => 'gcash', 'kind' => 'gcash_cash_in']);
            $this->detect();
            $this->assertCount($expected, AmlaAlert::query()->where('rule_code', 'K')->where('gcash_non_member_id', $walkIn->id)->get(), $amount);
        }

        $withId = GCashNonMember::factory()->create(['id_type' => 'Passport', 'id_number' => 'P1234567']);
        $this->event($this->member(), '90000.00', ['borrower_id' => null, 'gcash_non_member_id' => $withId->id, 'source' => 'gcash', 'kind' => 'gcash_cash_in']);
        $noId = $this->member();
        $this->event($noId, '90000.00');
        $hasId = $this->member();
        Document::query()->create([
            'documentable_type' => Borrower::class, 'documentable_id' => $hasId->id, 'type' => 'valid_id', 'label' => 'passport', 'id_number' => 'ID-77',
            'side' => 'front', 'file_path' => 'borrowers/'.$hasId->id.'/id.jpg', 'original_filename' => 'id.jpg', 'mime_type' => 'image/jpeg',
            'file_size' => 1024, 'uploaded_by' => $this->admin->id,
        ]);
        $this->event($hasId, '90000.00');
        $this->detect();

        $this->assertCount(0, AmlaAlert::query()->where('rule_code', 'K')->where('gcash_non_member_id', $withId->id)->get());
        $this->assertCount(1, AmlaAlert::query()->where('rule_code', 'K')->where('borrower_id', $noId->id)->get());
        $this->assertCount(0, AmlaAlert::query()->where('rule_code', 'K')->where('borrower_id', $hasId->id)->get());
    }

    public function test_third_party_rule_g_is_inactive_and_watchlist_rule_l_waits_for_screening(): void
    {
        $member = $this->member();
        foreach (range(1, 12) as $i) {
            $this->event($member, '60000.00', ['source' => 'gcash', 'kind' => 'gcash_cash_in']);
        }
        $this->detect();

        $this->assertSame(0, AmlaAlert::query()->whereIn('rule_code', ['G', 'L'])->count());
        $this->assertGreaterThan(0, AmlaAlert::query()->count());
    }

    public function test_one_open_alert_per_rule_subject_and_window_gathers_later_transactions(): void
    {
        $member = $this->member();
        foreach (range(1, 5) as $i) {
            $this->event($member, '1000.00', ['source' => 'gcash', 'kind' => 'gcash_cash_in']);
        }
        $this->detect();
        $this->event($member, '1000.00', ['source' => 'gcash', 'kind' => 'gcash_cash_in']);
        $this->detect();
        $this->detect();

        $alerts = $this->alerts('H');
        $this->assertCount(1, $alerts);
        $this->assertSame(6, $alerts[0]->events()->count());
    }

    public function test_detection_does_nothing_while_monitoring_is_off(): void
    {
        config(['amla.monitoring_enabled' => false]);
        $this->event($this->member(), '900000.00');

        $this->assertNull($this->detect());
        $this->assertSame(0, AmlaCtrCandidate::query()->count());
        $this->artisan('amla:detect')->expectsOutputToContain('AMLA monitoring is off')->assertSuccessful();
    }
}
