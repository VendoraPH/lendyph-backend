<?php

namespace Tests\Feature\Portal\Read;

use App\Models\Collateral;
use App\Models\CollateralType;
use App\Models\CoMaker;
use App\Models\Document;
use App\Models\Loan;
use App\Models\Repayment;
use App\Models\User;
use App\Notifications\PortalNotice;
use App\Services\Portal\Pdf\PortalPdf;
use App\Services\Portal\Pdf\PortalStatements;
use App\Services\ValidIdService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    private const FORBIDDEN = '/(^|_)(remarks?|notes?|approval|approvals|approved|journal|journals|score|scores|risk|flags?|officer|collateral|collaterals|photo|photos|image|images|url|password|token|spouse|income|birthdate|address|mobile|email|comaker|comakers)(_|$)|co_?makers?|valid_?id|id_number|contact_number|birth_?date|_by$|^created_by|^borrower_id$/';

    /** Values seeded on the member's records that no portal answer or PDF may carry. */
    private const SECRETS = [
        'mobile' => '09171234567', 'mobile digits' => '9171234567', 'email' => 'juana.secret@gmail.com',
        'birthdate' => '1988-03-14', 'street' => '77 Secretstreet Avenue', 'valid ID number' => 'VID-77889900',
        'account officer' => 'Zebulon', 'co-maker' => 'Quixote', 'collateral' => 'TCT-SECRET-4411',
        'loan remarks' => 'REMARK-LOAN-SECRET', 'payment remarks' => 'REMARK-PAY-SECRET', 'savings remarks' => 'REMARK-SAVINGS-SECRET',
        'monthly income' => '87654.32', 'spouse' => 'Spousename',
        'savings transaction remarks' => 'REMARK-SAVINGS-ROW-SECRET',
        // The forms the PDFs print, so a leak can't hide behind formatting.
        'printed monthly income' => '₱87,654.32', 'grouped monthly income' => '87,654.32',
        'printed birthdate' => '14 Mar 1988', 'long birthdate' => 'March 14, 1988', 'short birthdate' => 'Mar 14, 1988',
        'valid ID number without separators' => 'VID77889900', 'valid ID digits' => '77889900',
    ];

    private const LOAN_ROW = [
        'id', 'loan_account_number', 'product_name', 'display_status', 'release_date', 'maturity_date', 'principal_amount',
        'total_outstanding', 'outstanding_principal', 'overdue_amount', 'next_due_date', 'next_due_amount', 'paid_percent',
        'penalty_months',
    ];

    private const SAVINGS = [
        'id', 'account_number', 'product_name', 'status', 'ledger_balance', 'held', 'pending_withdrawal', 'maintaining_balance',
        'available_balance', 'withdrawable', 'annual_rate', 'rate_tiers', 'accrued_interest', 'interest_credited_through', 'opened_on',
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

        $uris = $this->readUris($loan->id, $account->id, $deposit->id);
        $leaks = [];

        foreach ($uris as $uri) {
            $this->walk($this->portalJson('GET', $uri, [], $token)->assertOk()->json(), $uri, $leaks);
        }

        $this->assertSame([], $leaks);
    }

    public function test_no_answer_or_pdf_carries_the_members_private_details(): void
    {
        $me = $this->portalMember([
            'contact_number' => '09171234567', 'email' => 'juana.secret@gmail.com', 'birthdate' => '1988-03-14',
            'street_address' => '77 Secretstreet Avenue', 'address' => '77 Secretstreet Avenue, San Roque',
            'monthly_income' => '87654.32', 'spouse_first_name' => 'Spousename',
        ]);
        $loan = $this->releasedLoanFor($me);
        $officer = User::factory()->create(['first_name' => 'Zebulon', 'last_name' => 'Officer']);
        Loan::query()->whereKey($loan->id)->update(['account_officer_id' => $officer->id, 'approval_remarks' => 'REMARK-LOAN-SECRET']);
        $coMaker = CoMaker::create(['borrower_id' => $me->borrower_id, 'first_name' => 'Quixote', 'last_name' => 'Comaker', 'relationship_to_borrower' => 'friend', 'status' => 'active']);
        $loan->coMakers()->attach($coMaker->id);
        $collateral = Collateral::create(['borrower_id' => $me->borrower_id, 'collateral_type_id' => CollateralType::factory()->create()->id, 'detail_value' => 'TCT-SECRET-4411', 'amount' => '50000.00']);
        $loan->collaterals()->attach($collateral->id, ['snapshot_value' => '50000.00', 'attached_at' => now()]);
        $payment = $this->pay($loan, 5000)->json('data');
        Repayment::query()->whereKey($payment['id'])->update(['remarks' => 'REMARK-PAY-SECRET']);
        $account = $this->savingsFor($me, 300_000, ['remarks' => 'REMARK-SAVINGS-SECRET']);
        // The rows the passbook and the savings statement read carry their own remarks.
        $this->deposit($account, 25_000);
        $this->assertGreaterThan(0, DB::table('savings_transactions')->where('account_id', $account->id)->update(['remarks' => 'REMARK-SAVINGS-ROW-SECRET']));
        $deposit = $this->timeDepositFor($me);
        $this->shareCapital($me, '2026-01-10', '1500.00');
        Document::create([
            'documentable_type' => $me->borrower->getMorphClass(), 'documentable_id' => $me->borrower_id,
            'type' => ValidIdService::DOCUMENT_TYPE, 'label' => 'umid', 'id_number' => 'VID-77889900', 'side' => 'front',
            'file_path' => "documents/valid_id/borrower/{$me->borrower_id}/front.png", 'original_filename' => 'front.png',
            'mime_type' => 'image/png', 'file_size' => 10,
        ]);
        $this->memberDocument($me->borrower);
        $token = $this->portalToken($me);

        $bodies = [];

        foreach ($this->readUris($loan->id, $account->id, $deposit->id) as $uri) {
            $bodies[$uri] = (string) $this->portalJson('GET', $uri, [], $token)->assertOk()->getContent();
        }

        $statements = app(PortalStatements::class);
        $pdf = app(PortalPdf::class);
        $loan = $loan->fresh();
        $bodies['loan statement PDF'] = $pdf->html($statements->loan($loan));
        $bodies['share capital statement PDF'] = $pdf->html($statements->shareCapitalStatement($me->borrower, '2026-01-01', '2026-11-10'));
        $bodies['share capital certificate PDF'] = $pdf->html($statements->shareCapitalCertificate($me->borrower, '2026-11-10'));
        $bodies['savings statement PDF'] = $pdf->html($statements->savingsStatement($account->fresh(), null, null));
        $bodies['time deposit certificate PDF'] = $pdf->html($statements->timeDepositCertificate($deposit->fresh()));

        $leaks = [];

        foreach ($bodies as $where => $body) {
            $text = html_entity_decode(json_encode(json_decode($body), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $body);

            foreach (self::SECRETS as $what => $secret) {
                if (str_contains($text, $secret) || str_contains($body, $secret)) {
                    $leaks[] = "{$where} carries the {$what}";
                }
            }
        }

        $this->assertSame([], $leaks);
    }

    /** @return list<string> every signed-in JSON read */
    private function readUris(int $loanId, int $accountId, int $depositId): array
    {
        return [
            '/api/portal/dashboard', '/api/portal/membership', '/api/portal/profile', '/api/portal/loans', "/api/portal/loans/{$loanId}",
            "/api/portal/loans/{$loanId}/schedule", "/api/portal/loans/{$loanId}/payments", '/api/portal/payments',
            '/api/portal/share-capital', '/api/portal/share-capital/transactions', '/api/portal/savings', "/api/portal/savings/{$accountId}",
            "/api/portal/savings/{$accountId}/transactions", '/api/portal/time-deposits', "/api/portal/time-deposits/{$depositId}",
            '/api/portal/documents', '/api/portal/notifications', '/api/portal/notifications/unread-count',
        ];
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
        return str_ends_with($path, '/membership.data.branch') && in_array($key, ['address', 'contact_number'], true);
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
