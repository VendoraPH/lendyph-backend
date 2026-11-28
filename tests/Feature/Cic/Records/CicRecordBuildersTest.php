<?php

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\CicCodeMapping;
use App\Models\CicLoanOverride;
use App\Models\CicProductMapping;
use App\Models\Collateral;
use App\Models\CollateralType;
use App\Models\CoMaker;
use App\Models\Document;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Services\Cic\Records\CicFormat;
use App\Services\Cic\Records\CicRecord;
use App\Services\Cic\Records\CicRecordBuilder;
use App\Services\Cic\Records\CicRecordSet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cic\Records\CicRecordsFixtures;
use Tests\TestCase;

uses(TestCase::class, CicRecordsFixtures::class);

/*
 * The record builders, field by field against the mapping CSV: ID subjects
 * (members and member co-makers), CI and CN contracts (structure, mapping and
 * override), NE events (deceased only), with the existing services' figures
 * formatted in whole pesos.
 */

beforeEach(fn () => $this->cicOn());

function cicBuild(?string $date = null): CicRecordSet
{
    return app(CicRecordBuilder::class)->build(Carbon::parse($date ?? '2026-11-30'));
}

function cicRecordOf(CicRecordSet $set, string $type, string $key): ?CicRecord
{
    return collect($set->ofType($type))->first(fn (CicRecord $r) => $r->key === $key);
}

it('formats dates, whole pesos half away from zero, and text', function () {
    expect(CicFormat::date('2026-03-07'))->toBe('07032026')
        ->and(CicFormat::amount('1500.50'))->toBe('1501')
        ->and(CicFormat::amount('1500.49'))->toBe('1500')
        ->and(CicFormat::amount('-2.50'))->toBe('-3')
        ->and(CicFormat::amount('-0.40'))->toBe('0')
        ->and(CicFormat::amount(null))->toBe('')
        ->and(CicFormat::text("  Juan |  dela\nCruz "))->toBe('Juan dela Cruz')
        ->and(CicFormat::dateKey('31022026'))->toBeNull()
        ->and(CicFormat::dateKey('28022026'))->toBe(20260228);
});

it('builds a member\'s ID record from the member record', function () {
    $member = $this->cicMember();
    Document::query()->forceCreate(['documentable_type' => Borrower::class, 'documentable_id' => $member->id, 'type' => 'valid_id', 'label' => 'sss', 'id_number' => '34-1234567-8', 'side' => 'front', 'file_path' => 'x', 'original_filename' => 'x.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 1]);
    Document::query()->forceCreate(['documentable_type' => Borrower::class, 'documentable_id' => $member->id, 'type' => 'valid_id', 'label' => 'sss', 'id_number' => '34-1234567-8', 'side' => 'back', 'file_path' => 'y', 'original_filename' => 'y.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 1]);
    Document::query()->forceCreate(['documentable_type' => Borrower::class, 'documentable_id' => $member->id, 'type' => 'valid_id', 'label' => 'tin_id', 'id_number' => '123-456-789', 'file_path' => 'z', 'original_filename' => 'z.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 1]);
    Document::query()->forceCreate(['documentable_type' => Borrower::class, 'documentable_id' => $member->id, 'type' => 'valid_id', 'label' => 'passport', 'id_number' => 'P1234567A', 'file_path' => 'p', 'original_filename' => 'p.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 1]);
    $this->cicLoan($member);

    $v = cicRecordOf(cicBuild(), 'ID', $member->borrower_code)->values;

    expect($v)->toMatchArray([
        'ID1' => 'ID', 'ID2' => 'LENDY001', 'ID4' => '30112026', 'ID5' => $member->borrower_code,
        'ID7' => 'Maria', 'ID8' => 'Cruz', 'ID9' => 'Santos', 'ID13' => 'F', 'ID14' => '12041985', 'ID19' => '2',
        'ID22' => 'Jose', 'ID23' => 'Cruz',
        'ID32' => 'MI', 'ID34' => '12 Rizal St', 'ID37' => 'Poblacion', 'ID38' => 'Naga', 'ID39' => 'Camarines Sur',
        'ID43' => 'AI', 'ID45' => '12 Rizal St', 'ID49' => 'Naga', 'ID50' => 'Camarines Sur',
        'ID54' => '10', 'ID55' => '123-456-789', 'ID56' => '11', 'ID57' => '34-1234567-8',
        'ID60' => '12', 'ID61' => 'P1234567A',
        'ID78' => '3', 'ID79' => '09171234567', 'ID80' => '7', 'ID81' => 'maria@example.com',
        'ID82' => 'Sari-sari store', 'ID86' => '25001', 'ID87' => 'M', 'ID88' => 'PHP', 'ID90' => '01062015',
    ])->and($v)->not->toHaveKey('ID33')->not->toHaveKey('ID58');
});

it('sends a one-line address as FullAddress, maps "others" IDs only through staff\'s map, and reads a landline as a main phone', function () {
    $member = $this->cicMember(['city' => null, 'province' => null, 'address' => '45 Mabini St, Iriga', 'contact_number' => '0542123456']);
    Document::query()->forceCreate(['documentable_type' => Borrower::class, 'documentable_id' => $member->id, 'type' => 'valid_id', 'label' => 'others', 'custom_type_name' => 'GSIS', 'id_number' => 'G-1', 'file_path' => 'x', 'original_filename' => 'x.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 1]);
    $this->cicLoan($member);

    $before = cicRecordOf(cicBuild(), 'ID', $member->borrower_code)->values;
    CicCodeMapping::factory()->create(['domain' => CicCodeMapping::DOMAIN_ID_KIND, 'lendy_value' => 'GSIS', 'cic_domain' => 'IdentificationTypeDomain', 'cic_code' => '12']);
    $after = cicRecordOf(cicBuild(), 'ID', $member->borrower_code)->values;

    expect($before)->toMatchArray(['ID32' => 'MI', 'ID33' => '45 Mabini St, Iriga', 'ID43' => 'AI', 'ID44' => '45 Mabini St, Iriga', 'ID78' => '1'])
        ->and($before)->not->toHaveKey('ID34')->not->toHaveKey('ID54')
        ->and($after)->toMatchArray(['ID54' => '12', 'ID55' => 'G-1']);
});

it('routes each valid-ID kind by its mapping\'s code list, and falls back to the built-in defaults', function () {
    $member = $this->cicMember();
    $document = fn (string $label, string $number, ?string $custom = null) => Document::query()->forceCreate([
        'documentable_type' => Borrower::class, 'documentable_id' => $member->id, 'type' => 'valid_id', 'label' => $label,
        'custom_type_name' => $custom, 'id_number' => $number, 'file_path' => $number, 'original_filename' => 'x.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 1,
    ]);
    $document('drivers_license', 'N01-23-456789');
    $document('sss', '34-1234567-8');
    $document('umid', '0111-2222333-4');
    $document('others', 'PH-5', 'PhilHealth');
    $document('others', '9-9', 'Card 12');
    $document('others', 'X-1');
    $this->cicLoan($member);

    $defaults = cicRecordOf(cicBuild(), 'ID', $member->borrower_code)->values;

    // sss moves to the ID types (code 10 there is a driver's licence, not a TIN), the licence moves to the
    // identifications, and PhilHealth (an "others" name) is mapped as an ID type. umid keeps its default.
    CicCodeMapping::factory()->create(['domain' => CicCodeMapping::DOMAIN_ID_KIND, 'lendy_value' => 'sss', 'cic_domain' => 'IDTypeDomain', 'cic_code' => '14']);
    CicCodeMapping::factory()->create(['domain' => CicCodeMapping::DOMAIN_ID_KIND, 'lendy_value' => 'drivers_license', 'cic_domain' => 'IdentificationTypeDomain', 'cic_code' => '19']);
    CicCodeMapping::factory()->create(['domain' => CicCodeMapping::DOMAIN_ID_KIND, 'lendy_value' => 'PhilHealth', 'cic_domain' => 'IDTypeDomain', 'cic_code' => '20']);
    $mapped = cicRecordOf(cicBuild(), 'ID', $member->borrower_code)->values;

    expect($defaults)->toMatchArray([
        'ID54' => '11', 'ID55' => '34-1234567-8', 'ID56' => '15', 'ID57' => '0111-2222333-4',
        'ID60' => '10', 'ID61' => 'N01-23-456789',
    ])->and($defaults)->not->toHaveKey('ID58')->not->toHaveKey('ID66')
        ->and($mapped)->toMatchArray([
            'ID54' => '19', 'ID55' => 'N01-23-456789', 'ID56' => '15', 'ID57' => '0111-2222333-4',
            'ID60' => '14', 'ID61' => '34-1234567-8', 'ID66' => '20', 'ID67' => 'PH-5',
        ])->and($mapped)->not->toHaveKey('ID58')->not->toHaveKey('ID72');
});

it('builds an instalment loan\'s CI record from the existing figures', function () {
    $member = $this->cicMember();
    $loan = $this->cicLoan($member, overrides: ['insurance_remaining_balance' => 0]);
    Repayment::factory()->create(['loan_id' => $loan->id, 'payment_date' => '2026-10-10', 'amount_paid' => 500.50, 'status' => 'posted']);
    Repayment::factory()->create(['loan_id' => $loan->id, 'payment_date' => '2026-12-01', 'amount_paid' => 999, 'status' => 'posted']);
    $loan->amortizationSchedules()->where('period_number', 1)->update(['interest_paid' => 300.0, 'principal_paid' => 200.5, 'status' => 'partial']);

    $v = cicRecordOf(cicBuild(), 'CI', $loan->loan_account_number)->values;

    expect($v)->toMatchArray([
        'CI1' => 'CI', 'CI2' => 'LENDY001', 'CI4' => '30112026', 'CI5' => $member->borrower_code, 'CI6' => 'B',
        'CI7' => $loan->loan_account_number, 'CI8' => '12', 'CI9' => 'AC', 'CI10' => 'PD', 'CI11' => 'PHP', 'CI12' => 'PHP',
        'CI13' => '15092026', 'CI15' => '15122026', 'CI17' => '10102026', 'CI18' => '0',
        'CI20' => '30000', 'CI21' => '3', 'CI22' => 'NA', 'CI24' => 'M', 'CI26' => '10300', 'CI27' => '15102026',
        'CI28' => '501', 'CI29' => '15102026', 'CI30' => '9800', 'CI31' => '3', 'CI32' => '30400',
        'CI33' => '2', 'CI34' => '20100', 'CI35' => '2',
    ])->and($v)->not->toHaveKey('CI16');
});

it('reports a lump-sum loan as CN, warns when its product is mapped to CI, and lets an override decide', function () {
    $product = $this->cicProduct('CI');
    $loan = $this->cicLoan(product: $product, overrides: ['frequency' => 'upon_maturity'], periods: 1);

    $record = cicRecordOf(cicBuild(), 'CN', $loan->loan_account_number);

    expect($record->values)->toMatchArray(['CN1' => 'CN', 'CN9' => 'AC', 'CN20' => '10000', 'CN23' => '10300', 'CN24' => '10300'])
        ->and($record->values)->not->toHaveKey('CN8')->not->toHaveKey('CN21')
        ->and(collect($record->buildIssues)->pluck('code')->all())->toBe(['CLASSIFICATION_MISMATCH', 'CONTRACT_TYPE_FOR_OTHER_RECORD']);

    CicLoanOverride::factory()->create(['loan_id' => $loan->id, 'record_type' => 'CN', 'contract_type' => '48', 'transaction_type' => 'BUL', 'reason' => 'One payment at maturity.']);
    $overridden = cicRecordOf(cicBuild(), 'CN', $loan->loan_account_number);

    expect($overridden->values)->toMatchArray(['CN8' => '48', 'CN21' => 'BUL'])
        ->and($overridden->buildIssues)->toBe([]);
});

it('gives the product\'s codes, purpose included, only to the record type it is mapped to', function () {
    $product = $this->cicProduct('CI');
    CicProductMapping::query()->where('loan_product_id', $product->id)->sole()->forceFill(['purpose_code' => '10'])->save();
    $instalments = $this->cicLoan(product: $product);
    $lumpSum = $this->cicLoan(product: $product, overrides: ['frequency' => 'upon_maturity'], periods: 1);
    CicLoanOverride::factory()->create(['loan_id' => $lumpSum->id, 'record_type' => null, 'contract_type' => '48', 'reason' => 'One payment at maturity.']);
    $asCi = $this->cicLoan(product: $product, overrides: ['frequency' => 'upon_maturity'], periods: 1);
    CicLoanOverride::factory()->create(['loan_id' => $asCi->id, 'record_type' => 'CI', 'reason' => 'Reported as instalment.']);

    $set = cicBuild();
    $ci = cicRecordOf($set, 'CI', $instalments->loan_account_number);
    $cn = cicRecordOf($set, 'CN', $lumpSum->loan_account_number);
    $overridden = cicRecordOf($set, 'CI', $asCi->loan_account_number);

    expect($ci->values)->toMatchArray(['CI8' => '12', 'CI22' => 'NA', 'CI23' => '10'])
        ->and($cn->values)->toMatchArray(['CN8' => '48'])->and($cn->values)->not->toHaveKey('CN21')
        ->and(collect($cn->buildIssues)->pluck('code')->all())->toBe(['CLASSIFICATION_MISMATCH', 'CONTRACT_TYPE_FOR_OTHER_RECORD'])
        ->and($cn->buildIssues[1]['message'])->toEndWith('Give the loan its own transaction type.')
        ->and($overridden->values)->toMatchArray(['CI8' => '12', 'CI22' => 'NA', 'CI23' => '10'])
        ->and(collect($overridden->buildIssues)->pluck('code')->all())->toBe(['OVERRIDE_AGAINST_STRUCTURE']);
});

it('makes an unmapped product an error on the contract, in words', function () {
    $loan = $this->cicLoan(product: LoanProduct::factory()->create(['name' => 'Salary Loan']));

    $record = cicRecordOf(cicBuild(), 'CI', $loan->loan_account_number);

    expect($record->buildIssues[0])->toMatchArray(['field_id' => 'CI8', 'severity' => 'error', 'code' => 'UNMAPPED_PRODUCT'])
        ->and($record->buildIssues[0]['message'])->toContain('"Salary Loan" has no CIC mapping');
});

it('reports an imported loan by its file\'s Loan No., with no request date', function () {
    $loan = $this->cicLoan(overrides: ['loan_account_number' => null, 'external_loan_no' => 'BC-07', 'imported_arrears_baseline' => '2026-09-30']);

    $v = cicRecordOf(cicBuild(), 'CI', 'BC-07')->values;

    expect($v['CI7'])->toBe('BC-07')->and($v)->not->toHaveKey('CI14')->and($v['CI28'])->toBe('0');
});

it('keys a loan number over 38 characters by its id and reports it, never cut', function () {
    $number = str_repeat('X', 45);
    $loan = $this->cicLoan(overrides: ['loan_account_number' => null, 'external_loan_no' => $number]);

    $record = cicRecordOf(cicBuild(), 'CI', "loan-{$loan->id}");

    expect($record->values['CI7'])->toBe($number)
        ->and($record->buildIssues[0]['code'])->toBe('CONTRACT_NO_TOO_LONG');
});

it('reports a member co-maker as a linked subject with an ID record, and a non-member co-maker by name only', function () {
    $loan = $this->cicLoan();
    $coMember = $this->cicMember(['first_name' => 'Pedro', 'last_name' => 'Reyes']);
    $member = CoMaker::factory()->create(['borrower_id' => $loan->borrower_id, 'member_borrower_id' => $coMember->id, 'first_name' => 'Pedro', 'last_name' => 'Reyes']);
    $outsider = CoMaker::factory()->create(['borrower_id' => $loan->borrower_id, 'first_name' => 'Ana', 'middle_name' => 'B.', 'last_name' => 'Lopez', 'suffix' => 'Jr.']);
    $loan->coMakers()->attach($member->id);
    $loan->coMakers()->attach($outsider->id);

    $set = cicBuild();
    $v = cicRecordOf($set, 'CI', $loan->loan_account_number)->values;

    expect($v)->toMatchArray(['CI126' => $coMember->borrower_code, 'CI127' => 'G', 'CI130' => 'G', 'CI131' => 'Ana B. Lopez Jr.'])
        ->and($v)->not->toHaveKey('CI128')->not->toHaveKey('CI129')
        ->and(cicRecordOf($set, 'ID', $coMember->borrower_code))->not->toBeNull()
        ->and($set->count('ID'))->toBe(2);
});

it('reports pledges as guarantee blocks, highest value first, with mapped and default guarantee codes', function () {
    $loan = $this->cicLoan();
    $land = CollateralType::query()->where('name', 'Land Title')->firstOrFail();
    $custom = CollateralType::factory()->create(['name' => 'Jewelry']);
    $small = Collateral::factory()->create(['borrower_id' => $loan->borrower_id, 'collateral_type_id' => $custom->id, 'amount' => 5000, 'detail_value' => 'RING-1']);
    $big = Collateral::factory()->create(['borrower_id' => $loan->borrower_id, 'collateral_type_id' => $land->id, 'amount' => 80000, 'detail_value' => 'TCT-12345']);
    $loan->collaterals()->attach($small->id, ['snapshot_value' => 5000, 'attached_at' => '2026-09-10 09:00:00']);
    $loan->collaterals()->attach($big->id, ['snapshot_value' => 80000.5, 'attached_at' => '2026-09-11 09:00:00']);

    $record = cicRecordOf(cicBuild(), 'CI', $loan->loan_account_number);
    $v = $record->values;

    expect($v)->toMatchArray([
        'CI43' => $loan->borrower->borrower_code, 'CI45' => '80001', 'CI46' => 'PHP', 'CI47' => '11092026', 'CI49' => '201',
        'CI50' => 'TCT-12345', 'CI51' => 'Land Title', 'CI53' => '80000',
        'CI59' => '5000', 'CI64' => 'RING-1', 'CI65' => 'Jewelry',
    ])->and($v)->not->toHaveKey('CI63')
        ->and($record->buildIssues[0])->toMatchArray(['field_id' => 'CI63', 'code' => 'UNMAPPED_COLLATERAL_TYPE']);

    CicCodeMapping::factory()->create(['domain' => CicCodeMapping::DOMAIN_COLLATERAL_TYPE, 'lendy_value' => (string) $custom->id, 'cic_code' => '299']);

    expect(cicRecordOf(cicBuild(), 'CI', $loan->loan_account_number)->values['CI63'])->toBe('299');
});

it('reports a loan completed in the month as closed, and leaves out one completed earlier', function () {
    $early = $this->cicLoan(overrides: ['status' => 'completed']);
    $inMonth = $this->cicLoan(overrides: ['status' => 'completed']);
    $inMonth->amortizationSchedules()->update(['status' => 'paid', 'principal_paid' => 10000, 'interest_paid' => 300]);
    Repayment::factory()->create(['loan_id' => $early->id, 'payment_date' => '2026-10-20', 'status' => 'posted']);
    Repayment::factory()->create(['loan_id' => $inMonth->id, 'payment_date' => '2026-11-20', 'status' => 'posted', 'amount_paid' => 30900]);

    $set = cicBuild();
    $v = cicRecordOf($set, 'CI', $inMonth->loan_account_number)->values;

    expect(cicRecordOf($set, 'CI', $early->loan_account_number))->toBeNull()
        ->and($v)->toMatchArray(['CI9' => 'CA', 'CI16' => '20112026', 'CI33' => '0', 'CI34' => '0', 'CI35' => '0'])
        ->and($v)->not->toHaveKey('CI31')->not->toHaveKey('CI32');
});

it('reports a restructured loan as closed with status CR, and its new loan with reorganized code 2', function () {
    $source = $this->cicLoan(overrides: ['status' => 'restructured', 'restructured_at' => '2026-11-10 09:00:00']);
    $new = $this->cicLoan($source->borrower, overrides: ['source_loan_id' => $source->id], releasedAt: '2026-11-10 09:00:00');

    $set = cicBuild();

    expect(cicRecordOf($set, 'CI', $source->loan_account_number)->values)->toMatchArray(['CI9' => 'CL', 'CI10' => 'CR', 'CI16' => '10112026'])
        ->and(cicRecordOf($set, 'CI', $new->loan_account_number)->values)->toMatchArray(['CI18' => '2', 'CI35' => 'N']);
});

it('never reports drafts, approved, void or rejected loans, nor a loan released after the reference date', function () {
    foreach (['draft', 'for_review', 'approved', 'void', 'rejected'] as $status) {
        $this->cicLoan(overrides: ['status' => $status]);
    }
    $this->cicLoan(releasedAt: '2026-12-01 09:00:00');

    expect(cicBuild()->count())->toBe(0);
});

it('builds an NE record for a reported member recorded as deceased, and none for other members', function () {
    $member = $this->cicMember(['status' => 'deceased', 'membership_ended_on' => '2026-11-02', 'membership_end_reason' => 'Passed away']);
    $this->cicLoan($member);
    $this->cicLoan();

    $set = cicBuild();

    expect($set->count('NE'))->toBe(1)
        ->and(cicRecordOf($set, 'NE', $member->borrower_code)->values)->toBe([
            'NE1' => 'NE', 'NE2' => 'LENDY001', 'NE3' => $set->context->branchCode($member->branch_id), 'NE4' => '30112026',
            'NE5' => $member->borrower_code, 'NE6' => 'U', 'NE7' => 'Passed away', 'NE8' => '02112026', 'NE9' => 'AC', 'NE10' => '02112026',
        ]);
});

it('builds each chunk in a fixed number of queries, not one per loan', function () {
    $count = function (int $loans): int {
        foreach (range(1, $loans) as $i) {
            $loan = $this->cicLoan();
            $co = CoMaker::factory()->create(['borrower_id' => $loan->borrower_id]);
            $loan->coMakers()->attach($co->id);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        cicBuild();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $few = $count(2);
    $more = $count(8);

    expect($more)->toBe($few);
});

it('uses the server\'s overdue rule from each bare due date', function () {
    $loan = $this->cicLoan(releasedAt: '2026-10-28 09:00:00');
    AmortizationSchedule::query()->where('loan_id', $loan->id)->where('period_number', 1)->update(['due_date' => '2026-11-29']);

    $v = cicRecordOf(cicBuild(), 'CI', $loan->loan_account_number)->values;

    expect($v)->toMatchArray(['CI33' => '1', 'CI34' => '10300', 'CI35' => '1'])
        ->and($v)->not->toHaveKey('CI10');
});
