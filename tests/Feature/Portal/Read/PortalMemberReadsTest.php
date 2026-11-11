<?php

namespace Tests\Feature\Portal\Read;

use App\Models\Document;
use App\Models\ShareCapitalPledge;
use App\Services\ValidIdService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The member's profile, membership and share capital (portal design §1, §3).
 * The profile follows the masking table: a stolen session must not hand over
 * the birthdate or the mobile number, which verify the member.
 */
class PortalMemberReadsTest extends TestCase
{
    use PortalReadFixtures, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->enablePortal();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_the_profile_masks_every_field_the_masking_table_names(): void
    {
        $me = $this->portalMember([
            'first_name' => 'Juana', 'middle_name' => null, 'last_name' => 'Dela Cruz', 'suffix' => null,
            'contact_number' => '09171234567', 'email' => 'juana.delacruz@gmail.com', 'birthdate' => '1988-03-14',
            'street_address' => '12 Mabini Street', 'barangay' => 'San Roque', 'city' => 'Tarlac City', 'province' => 'Tarlac',
            'address' => '12 Mabini Street, San Roque, Tarlac City, Tarlac',
            'civil_status' => 'married', 'gender' => 'female', 'employer_or_business' => 'Sari-sari store',
            'monthly_income' => '25000.00', 'spouse_first_name' => 'Pedro', 'spouse_last_name' => 'Dela Cruz',
            'spouse_contact_number' => '09998887777', 'photo_path' => 'photos/juana.jpg', 'member_type' => 'regular',
        ]);
        foreach (['front', 'back'] as $side) {
            Document::create([
                'documentable_type' => $me->borrower->getMorphClass(), 'documentable_id' => $me->borrower_id,
                'type' => ValidIdService::DOCUMENT_TYPE, 'label' => 'umid', 'id_number' => '0111-2222333-4821', 'side' => $side,
                'file_path' => "documents/valid_id/borrower/{$me->borrower_id}/{$side}.png", 'original_filename' => "{$side}.png",
                'mime_type' => 'image/png', 'file_size' => 10,
            ]);
        }

        $response = $this->portalJson('GET', '/api/portal/profile', [], $this->portalToken($me))->assertOk();
        $fields = collect($response->json('data.fields'));
        $value = fn (string $key) => $fields->firstWhere('key', $key)['value'] ?? null;

        $this->assertSame('Juana Dela Cruz', $value('full_name'));
        $this->assertSame('63917*****67', $value('contact_number'));
        $this->assertSame('ju***@gmail.com', $value('email'));
        $this->assertSame('1988', $value('birth_year'));
        $this->assertSame('On file', $value('street_address'));
        $this->assertSame('San Roque', $value('barangay'));
        $this->assertSame('Tarlac City', $value('city'));
        $this->assertSame('Married', $value('civil_status'));
        $this->assertSame('Female', $value('gender'));
        $this->assertSame('Sari-sari store', $value('employer_or_business'));
        $this->assertSame(['UMID •••• 4821'], $fields->where('key', 'government_id')->pluck('value')->values()->all(), 'One line per ID, not per side.');

        $body = (string) $response->getContent();
        foreach (['1988-03-14', '09171234567', '917123', 'juana.delacruz', '12 Mabini', '0111-2222333', '25000', 'Pedro', '09998887777', 'photos/', 'photo_url'] as $secret) {
            $this->assertStringNotContainsString($secret, $body, "The profile sent {$secret}.");
        }

        $this->assertSame(
            ['street_address', 'barangay', 'city', 'province', 'contact_number', 'email', 'civil_status', 'employer_or_business'],
            $response->json('data.editable_fields'),
        );
    }

    public function test_a_short_id_number_is_masked_entirely(): void
    {
        $me = $this->portalMember();
        Document::create([
            'documentable_type' => $me->borrower->getMorphClass(), 'documentable_id' => $me->borrower_id,
            'type' => ValidIdService::DOCUMENT_TYPE, 'label' => 'passport', 'id_number' => 'P12-345', 'side' => 'front',
            'file_path' => "documents/valid_id/borrower/{$me->borrower_id}/front.png", 'original_filename' => 'front.png',
            'mime_type' => 'image/png', 'file_size' => 10,
        ]);

        $fields = collect($this->portalJson('GET', '/api/portal/profile', [], $this->portalToken($me))->assertOk()->json('data.fields'));

        $this->assertSame(['Passport ••••'], $fields->where('key', 'government_id')->pluck('value')->values()->all());
    }

    public function test_profile_requests_switched_off_leave_nothing_editable(): void
    {
        $this->enablePortal(['features' => ['profile_requests' => false]]);
        $me = $this->portalMember();

        $this->portalJson('GET', '/api/portal/profile', [], $this->portalToken($me))->assertOk()->assertJsonPath('data.editable_fields', []);
    }

    public function test_membership_lists_factual_standing_only(): void
    {
        $me = $this->portalMember(['member_type' => 'regular']);
        $loan = $this->releasedLoanFor($me);
        $this->shareCapital($me, '2026-01-10', '1500.00');

        $data = $this->portalJson('GET', '/api/portal/membership', [], $this->portalToken($me))->assertOk()->json('data');

        $this->assertSame($me->borrower->membership_number ?: $me->borrower->borrower_code, $data['member_number']);
        $this->assertSame('regular', $data['member_type']);
        $this->assertSame('2024-01-15', $data['membership_date']);
        $this->assertSame($me->borrower->branch->name, $data['branch']['name']);

        $standing = collect($data['standing'])->keyBy('key');
        $this->assertSame('past_due', $standing['loans']['status'], "{$loan->loan_account_number} has late periods.");
        $this->assertSame('1500.00', $standing['share_capital']['amount']);
        $this->assertArrayNotHasKey('good_standing', $data);
    }

    public function test_share_capital_equals_the_staff_ledger(): void
    {
        $me = $this->portalMember();
        ShareCapitalPledge::query()->updateOrCreate(['borrower_id' => $me->borrower_id], ['amount' => '10000.00']);
        $this->shareCapital($me, '2026-01-10', '1500.00');
        $this->shareCapital($me, '2026-02-10', '2500.25');
        $this->shareCapital($me, '2026-03-10', '0.00', '500.00', 'Correction');

        $token = $this->portalToken($me);
        $summary = $this->portalJson('GET', '/api/portal/share-capital', [], $token)->assertOk()->json('data');

        $this->assertSame('3500.25', $summary['paid_up']);
        $this->assertSame('10000.00', $summary['pledged']);
        $this->assertSame('6499.75', $summary['remaining_pledge']);
        $this->assertSame('4000.25', $summary['total_contributions']);
        $this->assertSame(['date' => '2026-02-10', 'amount' => '2500.25'], $summary['last_contribution']);

        $staff = $this->staffGet("/api/share-capital/ledger?borrower_id={$me->borrower_id}")->json();
        $this->assertSame($this->pesosOf($staff['meta']['summary']['ending_balance']), $summary['paid_up']);

        $rows = $this->portalJson('GET', '/api/portal/share-capital/transactions', [], $token)->assertOk()->json('data');
        $this->assertSame(array_map(fn ($r) => $this->pesosOf($r['running_balance']), $staff['data']), array_column($rows, 'running_balance'));
        $this->assertSame(['date', 'reference', 'description', 'debit', 'credit', 'running_balance'], array_keys($rows[0]));
    }

    public function test_a_fully_paid_pledge_leaves_nothing_remaining(): void
    {
        $me = $this->portalMember();
        ShareCapitalPledge::query()->updateOrCreate(['borrower_id' => $me->borrower_id], ['amount' => '1000.00']);
        $this->shareCapital($me, '2026-01-10', '1500.00');

        $this->portalJson('GET', '/api/portal/share-capital', [], $this->portalToken($me))
            ->assertOk()
            ->assertJsonPath('data.remaining_pledge', '0.00');
    }

    private function pesosOf(float|int|string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
