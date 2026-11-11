<?php

namespace Tests\Feature\Portal\Read;

use App\Notifications\PortalNotice;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Pins every portal read's keys (portal design §3, §5, §8). Portal presenters
 * are allow-lists: a key not listed here fails, and so does any key, at any
 * depth, that reads like a remark, a note, a staff user (`*_by`), an
 * approval, a journal, a score or a risk flag.
 */
class PortalContractTest extends TestCase
{
    use PortalReadFixtures, SetupLendyPH;

    private const FORBIDDEN = '/(^|_)(remarks?|notes?|approval|approvals|approved|journal|journals|score|scores|risk|flags?|officer|collateral|collaterals|photo|url|password|token|spouse|income|birthdate|address)(_|$)|_by$|^created_by|^borrower_id$/';

    private const LOAN_ROW = [
        'id', 'loan_account_number', 'product_name', 'display_status', 'release_date', 'maturity_date', 'principal_amount',
        'total_outstanding', 'outstanding_principal', 'overdue_amount', 'next_due_date', 'next_due_amount', 'paid_percent',
        'penalty_months',
    ];

    private const SAVINGS = [
        'id', 'account_number', 'product_name', 'status', 'ledger_balance', 'held', 'pending_withdrawal', 'maintaining_balance',
        'available_balance', 'withdrawable', 'annual_rate', 'accrued_interest', 'interest_credited_through', 'opened_on',
        'branch_name', 'last_transaction_on',
    ];

    private const TIME_DEPOSIT = [
        'id', 'certificate_number', 'product_name', 'principal', 'annual_rate', 'term_days', 'placement_date', 'maturity_date',
        'days_remaining', 'maturing_soon', 'gross_interest', 'withholding_tax', 'net_interest', 'maturity_amount',
        'maturity_instruction', 'status',
    ];

    private const PAYMENT = [
        'receipt_number', 'loan_account_number', 'payment_date', 'amount_paid', 'principal', 'interest', 'penalty', 'insurance',
        'build_up', 'method', 'reference_number',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'Asia/Manila'));
        $this->enablePortal();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_every_read_answers_exactly_its_listed_keys(): void
    {
        $me = $this->portalMember();
        $loan = $this->releasedLoanFor($me);
        $this->pay($loan, 5000);
        $account = $this->savingsFor($me, 300_000);
        $deposit = $this->timeDepositFor($me);
        $this->shareCapital($me, '2026-01-10', '1500.00');
        $this->memberDocument($me->borrower);
        $me->notify(new PortalNotice('security', 'New sign-in', 'Chrome', '/member/security'));
        $token = $this->portalToken($me);
        $get = fn (string $uri) => $this->portalJson('GET', $uri, [], $token)->assertOk()->json();

        $dashboard = $get('/api/portal/dashboard')['data'];
        $this->assertKeys([
            'member', 'share_capital', 'savings', 'time_deposits', 'loans', 'next_payment', 'pending_requests', 'unread',
            'documents', 'announcements', 'recent_activity',
        ], $dashboard, 'dashboard');
        $this->assertKeys(['first_name', 'member_number', 'member_since', 'status', 'branch_name'], $dashboard['member'], 'dashboard.member');
        $this->assertKeys(['paid_up'], $dashboard['share_capital'], 'dashboard.share_capital');
        $this->assertKeys(['total', 'available', 'accounts'], $dashboard['savings'], 'dashboard.savings');
        $this->assertKeys(['total', 'count'], $dashboard['time_deposits'], 'dashboard.time_deposits');
        $this->assertKeys(['total_outstanding', 'active_count', 'overdue_amount'], $dashboard['loans'], 'dashboard.loans');
        $this->assertKeys(['due_date', 'amount', 'loans_count'], $dashboard['next_payment'], 'dashboard.next_payment');
        $this->assertKeys(['notifications', 'announcements'], $dashboard['unread'], 'dashboard.unread');
        $this->assertKeys(['date', 'kind', 'description', 'amount'], $dashboard['recent_activity'][0], 'dashboard.recent_activity');

        $membership = $get('/api/portal/membership')['data'];
        $this->assertKeys(['member_number', 'member_type', 'status', 'membership_date', 'branch', 'standing', 'support_contact'], $membership, 'membership');
        $this->assertKeys(['name', 'address', 'contact_number'], $membership['branch'], 'membership.branch');
        $this->assertKeys(['key', 'label', 'status', 'amount'], $membership['standing'][0], 'membership.standing');

        $profile = $get('/api/portal/profile')['data'];
        $this->assertKeys(['fields', 'editable_fields'], $profile, 'profile');
        $this->assertKeys(['key', 'label', 'value', 'masked'], $profile['fields'][0], 'profile.fields');

        $this->assertKeys(self::LOAN_ROW, $get('/api/portal/loans')['data'][0], 'loans');
        $this->assertKeys([...self::LOAN_ROW, 'term', 'term_unit', 'frequency', 'interest_rate', 'interest_rate_frequency', 'interest_method',
            'outstanding_interest', 'outstanding_penalty', 'insurance_owed', 'total_repaid', 'principal_paid', 'interest_paid',
            'penalty_paid'], $get("/api/portal/loans/{$loan->id}")['data'], 'loan');

        $schedule = $get("/api/portal/loans/{$loan->id}/schedule")['data'];
        $this->assertKeys(['periods', 'totals'], $schedule, 'schedule');
        $this->assertKeys(['period_number', 'due_date', 'status', 'principal', 'interest', 'penalty', 'balance', 'penalty_months'], $schedule['periods'][0], 'schedule.periods');
        $this->assertKeys(['principal', 'interest', 'penalty', 'paid', 'insurance_owed', 'balance', 'overdue'], $schedule['totals'], 'schedule.totals');

        $this->assertKeys(self::PAYMENT, $get("/api/portal/loans/{$loan->id}/payments")['data'][0], 'loan payments');
        $payments = $get('/api/portal/payments');
        $this->assertKeys(self::PAYMENT, $payments['data'][0], 'payments');
        $this->assertKeys(['current_page', 'last_page', 'per_page', 'total'], $payments['meta'], 'payments.meta');

        $this->assertKeys(['paid_up', 'pledged', 'remaining_pledge', 'total_contributions', 'last_contribution'], $get('/api/portal/share-capital')['data'], 'share capital');
        $this->assertKeys(['date', 'reference', 'description', 'debit', 'credit', 'running_balance'], $get('/api/portal/share-capital/transactions')['data'][0], 'share capital rows');

        $this->assertKeys(self::SAVINGS, $get('/api/portal/savings')['data'][0], 'savings');
        $this->assertKeys(self::SAVINGS, $get("/api/portal/savings/{$account->id}")['data'], 'savings account');
        $passbook = $get("/api/portal/savings/{$account->id}/transactions");
        $this->assertKeys(['date', 'reference', 'type', 'debit', 'credit', 'balance_after'], $passbook['data'][0], 'passbook');
        $this->assertKeys(['current_page', 'last_page', 'per_page', 'total', 'opening_balance'], $passbook['meta'], 'passbook.meta');

        $this->assertKeys(self::TIME_DEPOSIT, $get('/api/portal/time-deposits')['data'][0], 'time deposits');
        $this->assertKeys(self::TIME_DEPOSIT, $get("/api/portal/time-deposits/{$deposit->id}")['data'], 'time deposit');

        $documents = $get('/api/portal/documents')['data'];
        $this->assertKeys(['files', 'generated'], $documents, 'documents');
        $this->assertKeys(['id', 'title', 'category', 'shared_on', 'file_name', 'size'], $documents['files'][0], 'documents.files');
        $this->assertKeys(['kind', 'id', 'title', 'reference'], $documents['generated'][0], 'documents.generated');

        $this->assertKeys(['id', 'category', 'title', 'body', 'created_at', 'read_at', 'link'], $get('/api/portal/notifications')['data'][0], 'notifications');
        $this->assertKeys(['notifications', 'announcements', 'total'], $get('/api/portal/notifications/unread-count')['data'], 'unread count');
    }

    public function test_no_read_carries_a_staff_only_key_at_any_depth(): void
    {
        $me = $this->portalMember();
        $loan = $this->releasedLoanFor($me);
        $this->pay($loan, 5000);
        $account = $this->savingsFor($me, 300_000);
        $deposit = $this->timeDepositFor($me);
        $this->shareCapital($me, '2026-01-10', '1500.00');
        $this->memberDocument($me->borrower);
        $token = $this->portalToken($me);

        $uris = [
            '/api/portal/dashboard', '/api/portal/membership', '/api/portal/profile', '/api/portal/loans', "/api/portal/loans/{$loan->id}",
            "/api/portal/loans/{$loan->id}/schedule", "/api/portal/loans/{$loan->id}/payments", '/api/portal/payments',
            '/api/portal/share-capital', '/api/portal/share-capital/transactions', '/api/portal/savings', "/api/portal/savings/{$account->id}",
            "/api/portal/savings/{$account->id}/transactions", '/api/portal/time-deposits', "/api/portal/time-deposits/{$deposit->id}",
            '/api/portal/documents', '/api/portal/notifications', '/api/portal/notifications/unread-count',
        ];
        $leaks = [];

        foreach ($uris as $uri) {
            $this->walk($this->portalJson('GET', $uri, [], $token)->assertOk()->json(), $uri, $leaks);
        }

        $this->assertSame([], $leaks);
    }

    /** @param  list<string>  $leaks */
    private function walk(mixed $value, string $path, array &$leaks): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            if (is_string($key) && preg_match(self::FORBIDDEN, $key) === 1 && ! $this->allowed($path, $key)) {
                $leaks[] = "{$path} → {$key}";
            }

            $this->walk($child, is_string($key) ? "{$path}.{$key}" : $path, $leaks);
        }
    }

    /** The branch's own address and phone are public facts on the membership card. */
    private function allowed(string $path, string $key): bool
    {
        return str_ends_with($path, '/membership.data.branch') && $key === 'address';
    }

    /**
     * @param  list<string>  $expected
     * @param  array<string, mixed>  $actual
     */
    private function assertKeys(array $expected, array $actual, string $label): void
    {
        $this->assertSame($expected, array_keys($actual), "{$label} keys");
    }
}
