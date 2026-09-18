<?php

namespace Tests\Traits;

use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\JournalPoster;

/**
 * Chart and journal fixtures for the accounting tests.
 *
 * Everything here goes through the real ChartOfAccountsSeeder and the real
 * JournalPoster rather than inserting rows directly. A test fixture that writes
 * its own journals would be testing arithmetic against data the posting engine
 * would never have produced — and the engine's rules (recomputed totals, the
 * allocated number, the postable check) are precisely what the reports depend
 * on being true.
 *
 * The exception is the constraint tests, which insert raw ON PURPOSE: their
 * whole point is that the database refuses shapes no code path would create.
 */
trait PostsJournals
{
    /** @var array<string, int> */
    private array $accountIds = [];

    /**
     * A user holding exactly one role's permissions.
     *
     * Roles rather than `revokePermissionTo()`, because the super admin is
     * granted everything by a `Gate::before` hook in AppServiceProvider —
     * revoking a permission from that user changes nothing at all, and the test
     * passes for the wrong reason or fails confusingly. `general_bookkeeper` is
     * the role the permission split was designed around: it drafts entries and
     * reads reports, and deliberately holds neither `journals:post` nor
     * `journals:reverse`.
     */
    protected function userWithRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole($role);

        return $user;
    }

    /** Authenticated, but holding no permissions whatsoever. */
    protected function userWithNoRole(): User
    {
        return User::factory()->create(['branch_id' => $this->branch->id]);
    }

    protected function seedChartOfAccounts(): void
    {
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id);

        $this->accountIds = AccountingAccount::query()
            ->pluck('id', 'code')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /** The id of an account by its code — "1010", "3010". */
    protected function account(string $code): int
    {
        if (! isset($this->accountIds[$code])) {
            $this->fail("The default chart has no account {$code}.");
        }

        return $this->accountIds[$code];
    }

    /**
     * A draft, through the poster. Amounts are CENTAVOS.
     *
     * @param  list<array{account_id:int, debit?:int, credit?:int, description?:string|null}>  $lines
     * @param  array<string, mixed>  $attributes
     */
    protected function draftJournal(array $lines, array $attributes = []): AccountingJournal
    {
        return app(JournalPoster::class)->draft(array_merge([
            'date' => '2026-09-15',
            'source' => 'manual',
            'description' => 'Test entry',
            'branch_id' => $this->branch->id,
        ], $attributes), $lines, $this->admin->id);
    }

    /**
     * A posted entry, through the poster.
     *
     * @param  list<array{account_id:int, debit?:int, credit?:int, description?:string|null}>  $lines
     * @param  array<string, mixed>  $attributes
     */
    protected function postJournal(array $lines, array $attributes = []): AccountingJournal
    {
        return app(JournalPoster::class)->post(
            $this->draftJournal($lines, $attributes),
            $this->admin->id,
        );
    }

    /**
     * The commonest shape: one debit against one credit, in centavos.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function postSimpleJournal(
        string $debitCode,
        string $creditCode,
        int $centavos,
        array $attributes = [],
    ): AccountingJournal {
        return $this->postJournal([
            ['account_id' => $this->account($debitCode), 'debit' => $centavos, 'credit' => 0],
            ['account_id' => $this->account($creditCode), 'debit' => 0, 'credit' => $centavos],
        ], $attributes);
    }
}
