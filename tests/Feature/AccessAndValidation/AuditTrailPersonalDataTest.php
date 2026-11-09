<?php

namespace Tests\Feature\AccessAndValidation;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\CoMaker;
use App\Models\CreditApplicationProfile;
use App\Models\CreditDecision;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The Audit Trail and members' personal data.
 *
 * Member and co-maker audit rows store the whole record, and credit decision
 * and application profile rows store the decision's reasons and the member's
 * income and expenses. A caller who reads the trail (`audit_logs:view`,
 * `audit_logs:export` and `branches:view_all`) without `borrowers:view` sees
 * every row, its action and target, but none of those personal fields, on the
 * list, the detail or the CSV. Stored rows are never changed.
 */
class AuditTrailPersonalDataTest extends TestCase
{
    use SetupLendyPH;

    /**
     * A value of each personal field the rows below store: none may appear
     * anywhere in a reply to a caller without `borrowers:view`.
     */
    private const SECRETS = [
        '1987-03-14', 'Spouse-Firstname', 'Spouse-Lastname', '09171110000', '09172220000',
        'member@private.test', '12 Hidden Street', 'Barangay Lihim', 'Employer Lihim Inc', 'Spouse Occupation Lihim',
        '77 Co-maker Lane', '09173330000', 'Co-maker Occupation Lihim', 'Co-maker Employer Lihim',
        'Reason about the member Lihim', 'Remarks about the member Lihim', 'Recommendation Lihim',
        'Rejected for a private reason Lihim',
    ];

    /**
     * The personal keys each target's rows may hold, written out here so the
     * test doesn't read the list it checks.
     */
    private const PERSONAL_KEYS = [
        'Borrower' => [
            'birthdate', 'civil_status', 'gender', 'address', 'street_address', 'barangay', 'city', 'province',
            'contact_number', 'email', 'employer_or_business', 'monthly_income', 'date_hired', 'photo_path',
            'spouse_first_name', 'spouse_middle_name', 'spouse_last_name', 'spouse_contact_number', 'spouse_occupation',
            'rejection_reason',
        ],
        'CoMaker' => ['address', 'contact_number', 'occupation', 'employer', 'monthly_income'],
        'CreditDecision' => ['reason', 'remarks', 'score_snapshot', 'recommendation_snapshot'],
        'CreditApplicationProfile' => [
            'other_income', 'business_income', 'household_expenses', 'outside_debt_payments', 'outside_lenders_count',
            'employment_status', 'business_start_date', 'years_at_residence', 'verified_references_count', 'income_verification',
        ],
    ];

    /**
     * @var array<string, int>
     */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $member = Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'birthdate' => '1987-03-14',
            'civil_status' => 'married',
            'gender' => 'female',
            'address' => '12 Hidden Street',
            'street_address' => '12 Hidden Street',
            'barangay' => 'Barangay Lihim',
            'contact_number' => '09171110000',
            'email' => 'member@private.test',
            'employer_or_business' => 'Employer Lihim Inc',
            'monthly_income' => 48123.45,
            'spouse_first_name' => 'Spouse-Firstname',
            'spouse_last_name' => 'Spouse-Lastname',
            'spouse_contact_number' => '09172220000',
            'spouse_occupation' => 'Spouse Occupation Lihim',
        ]);
        $this->rows['member_created'] = $this->lastRowFor($member);

        // An update's old values hold the whole original record.
        $member->update(['status' => 'inactive', 'rejection_reason' => 'Rejected for a private reason Lihim']);
        $this->rows['member_updated'] = $this->lastRowFor($member);

        $coMaker = CoMaker::factory()->create([
            'borrower_id' => $member->id,
            'address' => '77 Co-maker Lane',
            'contact_number' => '09173330000',
            'occupation' => 'Co-maker Occupation Lihim',
            'employer' => 'Co-maker Employer Lihim',
            'monthly_income' => 31987.65,
            'relationship_to_borrower' => 'Sister',
        ]);
        $this->rows['co_maker_created'] = $this->lastRowFor($coMaker);

        $coMaker->delete();
        $this->rows['co_maker_deleted'] = $this->lastRowFor($coMaker);

        $loan = $this->createReleasedLoan();
        $profile = CreditApplicationProfile::query()->create([
            'loan_id' => $loan->id,
            'other_income' => 12345.67,
            'business_income' => 23456.78,
            'household_expenses' => 15432.10,
            'outside_debt_payments' => 2109.87,
            'outside_lenders_count' => 3,
            'employment_status' => 'self_employed',
            'years_at_residence' => 7,
        ]);
        $this->rows['profile_created'] = $this->lastRowFor($profile);

        $profile->update(['household_expenses' => 16543.21]);
        $this->rows['profile_updated'] = $this->lastRowFor($profile);

        // A decision row as CreditDecision's own audit writes it.
        $decision = (new CreditDecision)->forceFill(['id' => 987654]);
        $decision->exists = true;
        $this->rows['decision_created'] = AuditLogService::log('created', $decision, null, [
            'id' => 987654,
            'borrower_id' => $member->id,
            'loan_id' => $loan->id,
            'credit_score_id' => 1,
            'decision' => 'declined',
            'reason' => 'Reason about the member Lihim',
            'remarks' => 'Remarks about the member Lihim',
            'score_snapshot' => ['score' => 512, 'capacity' => ['monthly_income' => 48123.45]],
            'recommendation_snapshot' => 'Recommendation Lihim',
            'revision_version' => 'v1',
            'decided_by' => $this->admin->id,
        ])->id;
        $this->rows['decision_updated'] = AuditLogService::log('updated', $decision, [
            'decision' => 'declined',
            'remarks' => 'Remarks about the member Lihim',
        ], [
            'decision' => 'approved',
            'remarks' => 'Remarks about the member Lihim, revised',
        ])->id;
    }

    private function lastRowFor(object $model): int
    {
        return (int) AuditLog::query()
            ->where('auditable_type', $model::class)
            ->where('auditable_id', $model->getKey())
            ->latest('id')
            ->value('id');
    }

    /**
     * @param  list<string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $role = Role::create(['name' => 'probe_'.uniqid(), 'guard_name' => 'web']);
        $role->syncPermissions($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole($role);

        return $user;
    }

    private function auditReader(): User
    {
        return $this->userWith(['audit_logs:view', 'audit_logs:export', 'branches:view_all']);
    }

    /**
     * Every list page this test's rows are on, decoded.
     *
     * @return list<array<string, mixed>>
     */
    private function listedRows(): array
    {
        $rows = [];

        foreach (['Borrower', 'CoMaker', 'CreditApplicationProfile', 'CreditDecision'] as $type) {
            $rows = [...$rows, ...$this->getJson("/api/audit-logs?auditable_type={$type}&per_page=100")->assertOk()->json('data')];
        }

        return array_values(array_filter($rows, fn (array $row) => in_array($row['id'], $this->rows, true)));
    }

    private function csv(): string
    {
        return $this->get('/api/audit-logs/export')->assertOk()->streamedContent();
    }

    /**
     * @param  array<string, mixed>  $row  a list or detail row
     */
    private function assertNoPersonalKey(array $row): void
    {
        $personal = self::PERSONAL_KEYS[$row['auditable_type']];

        foreach (['old_values', 'new_values'] as $side) {
            $this->assertSame([], array_values(array_intersect(array_keys($row[$side] ?? []), $personal)), "Row {$row['id']} {$side}");
        }

        $this->assertSame([], array_values(array_intersect(array_column($row['changes'], 'field'), $personal)), "Row {$row['id']} changes");
    }

    private function assertNoSecretIn(string $body): void
    {
        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }

        foreach (['48123.45', '31987.65', '12345.67', '23456.78', '15432.1', '16543.21', '2109.87'] as $figure) {
            $this->assertStringNotContainsString($figure, $body);
        }
    }

    public function test_the_list_leaves_out_personal_fields_without_borrowers_view(): void
    {
        $this->actingAs($this->auditReader());

        $rows = $this->listedRows();

        $this->assertCount(count($this->rows), $rows);

        foreach ($rows as $row) {
            $this->assertNoPersonalKey($row);
        }

        $this->assertNoSecretIn(json_encode($rows));
    }

    public function test_the_detail_leaves_out_personal_fields_without_borrowers_view(): void
    {
        $this->actingAs($this->auditReader());

        foreach ($this->rows as $id) {
            $response = $this->getJson("/api/audit-logs/{$id}")->assertOk();

            $this->assertNoPersonalKey($response->json('data'));
            $this->assertNoSecretIn($response->getContent());
        }
    }

    public function test_the_csv_leaves_out_personal_fields_without_borrowers_view(): void
    {
        $this->actingAs($this->auditReader());

        $csv = $this->csv();

        foreach (['"""birthdate""', '"""spouse_first_name""', '"""contact_number""', '"""reason""', '"""household_expenses""'] as $key) {
            $this->assertStringNotContainsString($key, $csv);
        }

        $this->assertNoSecretIn($csv);
    }

    public function test_the_rows_their_action_target_and_other_fields_stay(): void
    {
        $this->actingAs($this->auditReader());

        $updated = $this->getJson("/api/audit-logs/{$this->rows['member_updated']}")->assertOk()->json('data');
        $this->assertSame('updated', $updated['action']);
        $this->assertSame('Borrower', $updated['target']['type']);
        $this->assertSame('inactive', $updated['new_values']['status']);
        $this->assertSame('active', $updated['old_values']['status']);
        $this->assertArrayHasKey('first_name', $updated['old_values']);
        $this->assertContains('status', array_column($updated['changes'], 'field'));

        $decision = $this->getJson("/api/audit-logs/{$this->rows['decision_updated']}")->assertOk()->json('data');
        $this->assertSame(['field' => 'decision', 'old' => 'declined', 'new' => 'approved'], $decision['changes'][0]);
        $this->assertCount(1, $decision['changes']);

        $profile = $this->getJson("/api/audit-logs/{$this->rows['profile_created']}")->assertOk()->json('data');
        $this->assertSame($profile['new_values']['loan_id'], CreditApplicationProfile::query()->value('loan_id'));
    }

    public function test_an_admin_still_sees_every_personal_field(): void
    {
        $admin = User::factory()->inBranches($this->branch)->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->assertTrue($admin->can('borrowers:view'));

        $list = json_encode($this->listedRows());
        $csv = $this->csv();

        foreach ([$list, $csv] as $body) {
            foreach (['12 Hidden Street', 'Spouse-Firstname', '09173330000', 'Reason about the member Lihim', '15432.1'] as $value) {
                $this->assertStringContainsString($value, $body);
            }
        }

        $member = $this->getJson("/api/audit-logs/{$this->rows['member_created']}")->assertOk()->json('data');
        $this->assertSame('1987-03-14', substr((string) $member['new_values']['birthdate'], 0, 10));
        $this->assertSame('member@private.test', $member['new_values']['email']);

        $decision = $this->getJson("/api/audit-logs/{$this->rows['decision_updated']}")->assertOk()->json('data');
        $this->assertContains('remarks', array_column($decision['changes'], 'field'));
    }

    public function test_stored_rows_are_never_changed(): void
    {
        $before = AuditLog::query()->whereKey(array_values($this->rows))->orderBy('id')->get(['id', 'old_values', 'new_values'])->toArray();

        $this->actingAs($this->auditReader());
        $this->listedRows();
        $this->csv();
        $this->getJson("/api/audit-logs/{$this->rows['member_created']}")->assertOk();

        $this->assertSame($before, AuditLog::query()->whereKey(array_values($this->rows))->orderBy('id')->get(['id', 'old_values', 'new_values'])->toArray());
        $this->assertSame('12 Hidden Street', AuditLog::find($this->rows['member_created'])->new_values['address']);
    }
}
