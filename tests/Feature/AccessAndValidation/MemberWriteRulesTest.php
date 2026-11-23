<?php

namespace Tests\Feature\AccessAndValidation;

use App\Models\Borrower;
use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Four write rules on members and their documents: valid IDs have their own
 * upload, a street address reaches `address`, a status change goes through
 * its own route, and member dates are written year-month-day.
 */
class MemberWriteRulesTest extends TestCase
{
    use SetupLendyPH;

    private Borrower $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Storage::fake('private');
        $this->member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    }

    // ── L1: the generic document upload ──

    /**
     * @return array<string, array{string}>
     */
    public static function validIdSpellings(): array
    {
        return [
            'as stored' => ['valid_id'],
            'capitals' => ['VALID_ID'],
            'hyphen' => ['Valid-ID'],
            'space' => ['valid id'],
            'run together' => ['ValidID'],
        ];
    }

    #[DataProvider('validIdSpellings')]
    public function test_the_generic_upload_refuses_a_valid_id_however_it_is_spelled(string $type): void
    {
        $this->post("/api/borrowers/{$this->member->id}/documents", [
            'type' => $type,
            'file' => UploadedFile::fake()->image('id.jpg'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);

        $this->assertSame(0, Document::where('documentable_id', $this->member->id)->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeTypes(): array
    {
        return [
            'path traversal' => ['../../etc'],
            'slash' => ['a/b'],
            'backslash' => ['a\\b'],
            'dot' => ['a.b'],
            'markup' => ['<b>x</b>'],
        ];
    }

    #[DataProvider('unsafeTypes')]
    public function test_the_generic_upload_refuses_unsafe_characters_in_a_type(string $type): void
    {
        $this->post("/api/borrowers/{$this->member->id}/documents", [
            'type' => $type,
            'file' => UploadedFile::fake()->image('doc.jpg'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function typesTheScreensSend(): array
    {
        return [
            'members tab' => ['PDF'],
            'co-maker dialog' => ['jpg'],
            'loan letter' => ['policy_exception_letter'],
            'other words' => ['proof of income'],
        ];
    }

    #[DataProvider('typesTheScreensSend')]
    public function test_the_generic_upload_keeps_the_types_the_screens_send(string $type): void
    {
        $this->post("/api/borrowers/{$this->member->id}/documents", [
            'type' => $type,
            'file' => UploadedFile::fake()->image('doc.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    // ── L2: a street address reaches `address` ──

    public function test_creating_with_only_a_street_address_fills_address(): void
    {
        $id = $this->postJson('/api/borrowers', [
            'first_name' => 'Rosalinda',
            'last_name' => 'Macaraeg',
            'branch_id' => $this->branch->id,
            'birthdate' => '1985-06-15',
            'gender' => 'female',
            'civil_status' => 'single',
            'contact_number' => '09171234567',
            'street_address' => '9 Rizal Ave',
            'city' => 'Butuan',
            'province' => 'Agusan del Norte',
        ])->assertCreated()->json('data.id');

        $this->assertSame('9 Rizal Ave', Borrower::find($id)->address);
    }

    public function test_an_update_fills_a_blank_address_but_never_overwrites_one(): void
    {
        $this->member->update(['address' => '12 Mabini St']);

        $this->putJson("/api/borrowers/{$this->member->id}", ['street_address' => '9 Rizal Ave'])->assertOk();
        $this->assertSame('12 Mabini St', $this->member->fresh()->address);

        $this->putJson("/api/borrowers/{$this->member->id}", ['address' => null, 'street_address' => '9 Rizal Ave'])->assertOk();
        $this->assertSame('9 Rizal Ave', $this->member->fresh()->address);

        Borrower::whereKey($this->member->id)->update(['address' => null]);
        $this->putJson("/api/borrowers/{$this->member->id}", ['street_address' => '44 Luna St'])->assertOk();
        $this->assertSame('44 Luna St', $this->member->fresh()->address);
    }

    // ── L7: status has its own routes ──

    public function test_an_update_cannot_activate_a_pending_registration(): void
    {
        $pending = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'pending']);

        $this->putJson("/api/borrowers/{$pending->id}", ['status' => 'active'])->assertOk();

        $pending->refresh();
        $this->assertSame('pending', $pending->status);
        $this->assertNull($pending->approved_by);
        $this->assertNull($pending->approved_at);
    }

    public function test_an_update_cannot_change_a_members_status_and_the_routes_still_can(): void
    {
        $this->putJson("/api/borrowers/{$this->member->id}", ['status' => 'blacklisted'])->assertOk();
        $this->assertSame('active', $this->member->fresh()->status);

        $this->patchJson("/api/borrowers/{$this->member->id}/deactivate")->assertOk();
        $this->assertSame('inactive', $this->member->fresh()->status);

        $this->patchJson("/api/borrowers/{$this->member->id}/reactivate")->assertOk();
        $this->assertSame('active', $this->member->fresh()->status);
    }

    // ── Member dates are written year-month-day ──

    private const DATE_REFUSALS = [
        'birthdate' => 'The birthdate must be a date written as year-month-day, such as 1990-01-15.',
        'date_hired' => 'The date hired must be a date written as year-month-day, such as 2020-06-01.',
        'membership_date' => 'The membership date must be a date written as year-month-day, such as 2026-01-15.',
    ];

    /**
     * A number passes the `date` rule (19900115 reads as 1990-01-15) but the
     * model's date cast stores it as a Unix timestamp (1970-08-19).
     *
     * @return array<string, array{string, int|float|string}>
     */
    public static function numericMemberDates(): array
    {
        return [
            'birthdate as a whole number' => ['birthdate', 19900115],
            'birthdate as a decimal' => ['birthdate', 19900115.0],
            'birthdate as digits' => ['birthdate', '19900115'],
            'date hired as a whole number' => ['date_hired', 20150601],
            'date hired as digits' => ['date_hired', '20150601'],
        ];
    }

    /**
     * @return array<string, array{string, int|float|string}>
     */
    public static function numericMemberDatesOnAnEdit(): array
    {
        return [
            ...self::numericMemberDates(),
            'membership date as a whole number' => ['membership_date', 20250101],
        ];
    }

    #[DataProvider('numericMemberDates')]
    public function test_the_public_form_refuses_a_member_date_sent_as_a_number(string $field, int|float|string $value): void
    {
        $this->app['auth']->forgetGuards();
        $before = Borrower::count();

        $this->postJson('/api/borrowers', $this->registration([$field => $value]))
            ->assertUnprocessable()
            ->assertJsonPath("errors.{$field}", [self::DATE_REFUSALS[$field]]);

        $this->assertSame($before, Borrower::count());
    }

    #[DataProvider('numericMemberDatesOnAnEdit')]
    public function test_a_member_edit_refuses_a_member_date_sent_as_a_number(string $field, int|float|string $value): void
    {
        $stored = $this->member->{$field}?->toDateString();

        $this->putJson("/api/borrowers/{$this->member->id}", [$field => $value])
            ->assertUnprocessable()
            ->assertJsonPath("errors.{$field}", [self::DATE_REFUSALS[$field]]);

        $this->assertSame($stored, $this->member->fresh()->{$field}?->toDateString());
    }

    public function test_the_public_form_saves_year_month_day_dates_as_sent(): void
    {
        $this->app['auth']->forgetGuards();

        $id = $this->postJson('/api/borrowers', $this->registration(['birthdate' => '1988-02-29', 'date_hired' => '2015-06-01']))
            ->assertCreated()
            ->json('data.id');

        $member = Borrower::findOrFail($id);
        $this->assertSame('1988-02-29', $member->birthdate->toDateString());
        $this->assertSame('2015-06-01', $member->date_hired->toDateString());
    }

    public function test_a_member_edit_saves_year_month_day_dates_as_sent(): void
    {
        $this->putJson("/api/borrowers/{$this->member->id}", [
            'birthdate' => '1979-12-31',
            'date_hired' => '2016-07-04',
            'membership_date' => '2025-01-01',
        ])->assertOk();

        $member = $this->member->fresh();
        $this->assertSame('1979-12-31', $member->birthdate->toDateString());
        $this->assertSame('2016-07-04', $member->date_hired->toDateString());
        $this->assertSame('2025-01-01', $member->membership_date->toDateString());
    }

    /**
     * A complete public registration, as the form sends it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function registration(array $overrides = []): array
    {
        return [
            'status' => 'pending',
            'branch_id' => $this->branch->id,
            'first_name' => 'Rosalinda',
            'last_name' => 'Macaraeg',
            'birthdate' => '1985-06-15',
            'gender' => 'female',
            'civil_status' => 'single',
            'contact_number' => '09171234567',
            'address' => '9 Rizal Ave',
            'city' => 'Butuan',
            'province' => 'Agusan del Norte',
            ...$overrides,
        ];
    }
}
