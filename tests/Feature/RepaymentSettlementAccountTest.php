<?php

/**
 * A payment's journal debits the account its method maps to.
 *
 * Until the payment screens saved the method, nearly every payment was stored
 * as `cash`, so the other settlement accounts were hardly exercised. Now that
 * they are, each method has to reach the account its settlement role names in
 * the chart's mapping, through the real endpoint and the real posting engine:
 * a collection on the wrong asset account still balances, and is only found by
 * counting the drawer.
 */

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Models\AccountingJournal;
use App\Models\Loan;
use App\Models\Repayment;
use App\Services\Accounting\SettlementMethod;
use App\Services\RepaymentService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

uses(TestCase::class, SetupLendyPH::class, PostsJournals::class);

beforeEach(function () {
    $this->seedAndLogin();
    $this->seedChartOfAccounts();
    $this->loan = $this->createReleasedLoan();
});

function collectionJournal(Repayment $repayment): AccountingJournal
{
    return AccountingJournal::query()
        ->where('postable_type', $repayment->getMorphClass())
        ->where('postable_id', $repayment->getKey())
        ->where('source', 'loan_collection')
        ->firstOrFail();
}

/** The account the chart's mapping names for a settlement role. */
function accountMappedTo(string $role): int
{
    return (int) AccountingAccountMapping::query()->where('role', $role)->value('accounting_account_id');
}

/**
 * What the journal put on each settlement account, role by role.
 *
 * @return array<string, int>
 */
function settlementDebits(AccountingJournal $journal, string $side = 'debit'): array
{
    $debits = [];
    foreach (SettlementMethod::ROLES as $role) {
        $debits[$role] = (int) $journal->lines()->where('accounting_account_id', accountMappedTo($role))->sum($side);
    }

    return $debits;
}

dataset('payment methods the API accepts', [
    'cash' => ['cash', 'cash', '1010', null],
    'gcash' => ['gcash', 'gcash', '1020', 'GC-1001'],
    'maya' => ['maya', 'maya', '1030', 'MY-1001'],
    'bank transfer' => ['bank_transfer', 'bank', '1040', 'TRF-1001'],
    'online' => ['online', 'bank', '1040', 'ONL-1001'],
]);

it('debits the mapped account for a payment recorded with its method', function (string $method, string $role, string $code, ?string $reference) {
    $response = $this->postJson("/api/loans/{$this->loan->id}/repayments", [
        'amount_paid' => 5000,
        'payment_date' => now()->toDateString(),
        'method' => $method,
        'reference_number' => $reference,
    ])
        ->assertCreated()
        ->assertJsonPath('data.method', $method)
        ->assertJsonPath('data.reference_number', $reference);

    $repayment = Repayment::findOrFail($response->json('data.id'));
    expect($repayment->method)->toBe($method)
        ->and($repayment->reference_number)->toBe($reference);

    $journal = collectionJournal($repayment);

    expect(SettlementMethod::fromLendingMethod($method))->toBe($role)
        ->and(accountMappedTo($role))->toBe($this->account($code));

    $expected = array_fill_keys(SettlementMethod::ROLES, 0);
    $expected[$role] = 500_000;

    expect(settlementDebits($journal))->toBe($expected)
        ->and((int) $journal->total_debit)->toBe((int) $journal->total_credit);
})->with('payment methods the API accepts');

it('debits the bank for an auto-pay collection', function () {
    $repayment = app(RepaymentService::class)->processRepayment(
        $this->loan->fresh(), 5000, now()->toDateString(), $this->admin, method: 'auto_pay',
    );

    expect($repayment->method)->toBe('auto_pay')
        ->and(settlementDebits(collectionJournal($repayment)))
        ->toBe(['cash' => 0, 'gcash' => 0, 'maya' => 0, 'bank' => 500_000]);
});

it('follows the mapping when the GCash role points at another wallet account', function () {
    $parent = AccountingAccount::where('code', '1020')->firstOrFail();
    $wallet = AccountingAccount::create([
        'code' => '1021',
        'name' => 'GCash Branch Wallet',
        'type' => 'asset',
        'parent_id' => $parent->parent_id,
        'cash_kind' => 'gcash',
        'created_by' => $this->admin->id,
    ]);
    AccountingAccountMapping::where('role', 'gcash')->update(['accounting_account_id' => $wallet->id]);

    $id = $this->postJson("/api/loans/{$this->loan->id}/repayments", [
        'amount_paid' => 5000,
        'payment_date' => now()->toDateString(),
        'method' => 'gcash',
        'reference_number' => 'GC-1002',
    ])->assertCreated()->json('data.id');

    $journal = collectionJournal(Repayment::findOrFail($id));

    expect((int) $journal->lines()->where('accounting_account_id', $wallet->id)->sum('debit'))->toBe(500_000)
        ->and((int) $journal->lines()->where('accounting_account_id', $parent->id)->sum('debit'))->toBe(0);
});

it('takes a voided GCash payment back off the GCash account', function () {
    $id = $this->postJson("/api/loans/{$this->loan->id}/repayments", [
        'amount_paid' => 5000,
        'payment_date' => now()->toDateString(),
        'method' => 'gcash',
        'reference_number' => 'GC-1003',
    ])->assertCreated()->json('data.id');

    $this->patchJson("/api/repayments/{$id}/void", ['void_reason' => 'Keyed twice'])->assertOk();

    $mirror = AccountingJournal::findOrFail(collectionJournal(Repayment::findOrFail($id))->reversed_by_journal_id);

    expect(settlementDebits($mirror, 'credit'))->toBe(['cash' => 0, 'gcash' => 500_000, 'maya' => 0, 'bank' => 0]);
});

it('has a settlement role for every value of the method column', function () {
    $type = DB::selectOne("SHOW COLUMNS FROM repayments WHERE Field = 'method'")->Type;
    preg_match_all("/'([^']+)'/", $type, $matches);

    expect($matches[1])->toContain('cash', 'gcash', 'maya', 'bank_transfer', 'online', 'auto_pay');

    foreach ($matches[1] as $method) {
        expect(SettlementMethod::ROLES)->toContain(SettlementMethod::fromLendingMethod($method));
    }
});

it('requires a reference for anything but cash, and refuses auto_pay from a client', function (string $method, ?string $reference, bool $accepted) {
    $response = $this->postJson("/api/loans/{$this->loan->id}/repayments", [
        'amount_paid' => 5000,
        'payment_date' => now()->toDateString(),
        'method' => $method,
        'reference_number' => $reference,
    ]);

    $accepted ? $response->assertCreated() : $response->assertStatus(422);
    expect(Loan::find($this->loan->id)->repayments()->count())->toBe($accepted ? 1 : 0);
})->with([
    'cash needs none' => ['cash', null, true],
    'gcash without one' => ['gcash', null, false],
    'bank transfer without one' => ['bank_transfer', null, false],
    'auto_pay is server only' => ['auto_pay', 'AP-1', false],
]);
