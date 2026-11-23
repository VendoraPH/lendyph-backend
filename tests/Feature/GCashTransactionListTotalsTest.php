<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\GCashNonMember;
use App\Models\GCashTransaction;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /gcash/transactions `meta.totals`: the GCash report prints the server's
 * totals, never a browser sum (product decisions: "Report totals come from the
 * server"). They cover every row the list's filters and branch scope match,
 * across every page, as two-decimal peso strings. A pending Cash In counts
 * whenever the status filter lets it into the list, and `pending` says how
 * much of each figure is still pending.
 */
class GCashTransactionListTotalsTest extends TestCase
{
    use SetupLendyPH;

    private const ZERO_PENDING = ['count' => 0, 'amount' => '0.00', 'charges' => '0.00', 'total' => '0.00'];

    private Borrower $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    }

    public function test_the_totals_cover_every_matching_row_on_every_page_not_just_the_page(): void
    {
        $this->row('cash_in', 'paid', '1000.00', '20.00');
        $this->row('cash_in', 'pending', '2500.50', '30.00');
        $this->row('cash_out', 'completed', '1200.00', '15.00');
        $this->row('cash_in', 'paid', '300.25', '10.00');
        $this->row('cash_out', 'completed', '4999.99', '25.00');
        $this->row('cash_in', 'paid', '750.00', '15.00');
        $this->row('cash_out', 'completed', '100.10', '5.05');

        $expected = [
            'count' => 7,
            'amount' => '10850.84',
            'cash_in' => '4550.75',
            'cash_out' => '6300.09',
            'charges' => '120.05',
            'total' => '10880.79',
            'pending' => ['count' => 1, 'amount' => '2500.50', 'charges' => '30.00', 'total' => '2530.50'],
        ];

        $drained = [];
        foreach ([1, 2, 3] as $page) {
            $response = $this->getJson("/api/gcash/transactions?per_page=3&page={$page}")->assertOk();

            $this->assertSame($expected, $response->json('meta.totals'), "Page {$page} carries the whole list's totals.");
            $drained = [...$drained, ...$response->json('data')];
        }

        $this->assertCount(7, $drained);
        $this->assertSame($expected['amount'], $this->pesos(array_sum(array_map(fn (array $r): int => $this->centavos($r['amount']), $drained))));
        $this->assertSame($expected['charges'], $this->pesos(array_sum(array_map(fn (array $r): int => $this->centavos($r['charge_amount']), $drained))));
        $this->assertSame($expected['total'], $this->pesos(array_sum(array_map(fn (array $r): int => $this->centavos($r['total_amount']), $drained))));
    }

    public function test_the_totals_follow_the_type_status_and_date_filters_with_both_dates_inclusive(): void
    {
        $this->row('cash_in', 'paid', '1000.00', '20.00', '2026-11-01 00:00:00');
        $this->row('cash_in', 'pending', '500.00', '10.00', '2026-11-15 10:30:00');
        $this->row('cash_out', 'completed', '2000.00', '25.00', '2026-11-30 23:59:59');
        $this->row('cash_in', 'paid', '4000.00', '30.00', '2026-10-31 23:59:59');
        $this->row('cash_out', 'completed', '3000.00', '25.00', '2026-12-01 00:00:00');

        $november = 'start_date=2026-11-01&end_date=2026-11-30';
        $pending = ['count' => 1, 'amount' => '500.00', 'charges' => '10.00', 'total' => '510.00'];

        $this->assertSame(
            ['count' => 3, 'amount' => '3500.00', 'cash_in' => '1500.00', 'cash_out' => '2000.00', 'charges' => '55.00', 'total' => '3505.00', 'pending' => $pending],
            $this->totals($november),
            'Both ends of the range are whole days and count; the days either side do not.',
        );
        $this->assertSame(
            ['count' => 2, 'amount' => '1500.00', 'cash_in' => '1500.00', 'cash_out' => '0.00', 'charges' => '30.00', 'total' => '1530.00', 'pending' => $pending],
            $this->totals("{$november}&type=cash_in"),
        );
        $this->assertSame(
            ['count' => 1, 'amount' => '2000.00', 'cash_in' => '0.00', 'cash_out' => '2000.00', 'charges' => '25.00', 'total' => '1975.00', 'pending' => self::ZERO_PENDING],
            $this->totals("{$november}&type=cash_out"),
        );
        $this->assertSame(
            ['count' => 1, 'amount' => '500.00', 'cash_in' => '500.00', 'cash_out' => '0.00', 'charges' => '10.00', 'total' => '510.00', 'pending' => $pending],
            $this->totals("{$november}&status=pending"),
        );
        $this->assertSame(
            ['count' => 1, 'amount' => '1000.00', 'cash_in' => '1000.00', 'cash_out' => '0.00', 'charges' => '20.00', 'total' => '1020.00', 'pending' => self::ZERO_PENDING],
            $this->totals("{$november}&status=paid"),
            'A status filter that leaves pending rows out leaves them out of the totals too.',
        );
        $this->assertSame(
            ['count' => 1, 'amount' => '2000.00', 'cash_in' => '0.00', 'cash_out' => '2000.00', 'charges' => '25.00', 'total' => '1975.00', 'pending' => self::ZERO_PENDING],
            $this->totals('start_date=2026-11-30&end_date=2026-11-30'),
            'A one-day range covers that whole day.',
        );
        $this->assertSame(
            ['count' => 5, 'amount' => '10500.00', 'cash_in' => '5500.00', 'cash_out' => '5000.00', 'charges' => '110.00', 'total' => '10510.00', 'pending' => $pending],
            $this->totals(),
        );
    }

    public function test_a_branch_users_totals_cover_only_the_rows_they_can_see(): void
    {
        $far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        $farMember = Borrower::factory()->create(['branch_id' => $far->id]);
        $walkIn = GCashNonMember::factory()->create();

        $this->row('cash_in', 'paid', '1000.00', '20.00');
        $this->row('cash_in', 'paid', '2000.00', '30.00', member: $farMember);
        $this->walkInRow($walkIn, $this->branch->id, 'cash_out', 'completed', '1500.00', '15.00');
        $this->walkInRow($walkIn, $far->id, 'cash_out', 'completed', '3000.00', '25.00');
        $this->walkInRow($walkIn, null, 'cash_in', 'paid', '4000.00', '30.00');
        $this->walkInRow($walkIn, $this->branch->id, 'cash_in', 'pending', '500.00', '10.00');

        $pending = ['count' => 1, 'amount' => '500.00', 'charges' => '10.00', 'total' => '510.00'];

        $this->actingAs($this->userWith(['gcash:view'], $this->branch));
        $this->assertSame(
            ['count' => 3, 'amount' => '3000.00', 'cash_in' => '1500.00', 'cash_out' => '1500.00', 'charges' => '45.00', 'total' => '3015.00', 'pending' => $pending],
            $this->totals(),
            'Their member and walk-in rows only: not the other branch\'s, and not head office\'s walk-in.',
        );

        $this->actingAs($this->admin);
        $this->assertSame(
            ['count' => 6, 'amount' => '12000.00', 'cash_in' => '7500.00', 'cash_out' => '4500.00', 'charges' => '130.00', 'total' => '12050.00', 'pending' => $pending],
            $this->totals(),
            'branches:view_all counts every row, the walk-in with no branch included.',
        );

        $this->actingAs($this->userWith(['gcash:view'], null));
        $this->assertSame(
            ['count' => 0, 'amount' => '0.00', 'cash_in' => '0.00', 'cash_out' => '0.00', 'charges' => '0.00', 'total' => '0.00', 'pending' => self::ZERO_PENDING],
            $this->totals(),
            'A user with no branch and no view_all sees no rows, so no totals.',
        );

        $this->actingAs($this->userWith(['loans:view'], $this->branch));
        $this->getJson('/api/gcash/transactions')->assertForbidden()->assertJsonMissingPath('meta.totals');
    }

    public function test_every_peso_total_is_a_two_decimal_string_summed_to_the_centavo(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->row('cash_in', 'paid', '100.10', '0.10');
        }
        $this->row('cash_out', 'completed', '1234.50', '0.05');

        $totals = $this->totals();

        $this->assertSame(
            ['count' => 11, 'amount' => '2235.50', 'cash_in' => '1001.00', 'cash_out' => '1234.50', 'charges' => '1.05', 'total' => '2236.45', 'pending' => self::ZERO_PENDING],
            $totals,
        );
        $this->assertTwoDecimalPesos(array_diff_key($totals, array_flip(['count', 'pending'])));
        $this->assertTwoDecimalPesos(array_diff_key($totals['pending'], array_flip(['count'])));

        $this->assertSame(
            ['count' => 0, 'amount' => '0.00', 'cash_in' => '0.00', 'cash_out' => '0.00', 'charges' => '0.00', 'total' => '0.00', 'pending' => self::ZERO_PENDING],
            $this->totals('type=cash_out&status=pending'),
            'No matching rows reads ₱0.00, never a missing figure.',
        );
    }

    /**
     * @param  array<string, mixed>  $figures
     */
    private function assertTwoDecimalPesos(array $figures): void
    {
        $this->assertNotEmpty($figures);

        foreach ($figures as $key => $value) {
            $this->assertIsString($value, "{$key} is a string, so no float can drop a centavo on the way.");
            $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $value, "{$key} has exactly two decimals.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function totals(string $query = ''): array
    {
        $totals = $this->getJson('/api/gcash/transactions'.($query === '' ? '' : "?{$query}"))->assertOk()->json('meta.totals');
        $this->assertIsArray($totals, 'The list sends meta.totals.');

        return $totals;
    }

    /** A member's row; its total is the amount plus the charge for a Cash In, less it for a Cash Out. */
    private function row(string $type, string $status, string $amount, string $charge, ?string $date = null, ?Borrower $member = null): GCashTransaction
    {
        return GCashTransaction::factory()->create([
            ...$this->money($type, $amount, $charge),
            'status' => $status,
            'transaction_date' => $date ?? now(),
            'borrower_id' => ($member ?? $this->member)->id,
            'transactor_user_id' => $this->admin->id,
        ]);
    }

    private function walkInRow(GCashNonMember $walkIn, ?int $branchId, string $type, string $status, string $amount, string $charge): GCashTransaction
    {
        return GCashTransaction::factory()->forNonMember($walkIn)->create([
            ...$this->money($type, $amount, $charge),
            'status' => $status,
            'transaction_date' => now(),
            'branch_id' => $branchId,
            'transactor_user_id' => $this->admin->id,
        ]);
    }

    /**
     * @return array{type: string, amount: string, charge_amount: string, total_amount: string}
     */
    private function money(string $type, string $amount, string $charge): array
    {
        $total = $type === 'cash_in'
            ? $this->centavos($amount) + $this->centavos($charge)
            : $this->centavos($amount) - $this->centavos($charge);

        return ['type' => $type, 'amount' => $amount, 'charge_amount' => $charge, 'total_amount' => $this->pesos($total)];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function userWith(array $permissions, ?Branch $branch): User
    {
        $user = $branch === null
            ? User::factory()->create(['branch_id' => null])
            : User::factory()->inBranches($branch)->create();

        $role = Role::firstOrCreate(['name' => 'gcash_totals_'.implode('_', str_replace(':', '_', $permissions)), 'guard_name' => 'web']);
        $role->syncPermissions($permissions);
        $user->assignRole($role);

        return $user;
    }

    private function centavos(string|int|float $pesos): int
    {
        return (int) round(((float) $pesos) * 100);
    }

    private function pesos(int $centavos): string
    {
        return sprintf('%d.%02d', intdiv($centavos, 100), $centavos % 100);
    }
}
