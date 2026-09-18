<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Points the new `borrower_advances` posting role at 2300 Other Liabilities on
 * charts that were seeded before the role existed.
 *
 * ## Why a migration and not "the seeder will handle it"
 *
 * ChartOfAccountsSeeder runs ONCE, and refuses to run over an existing chart —
 * correctly, because merging would resurrect accounts an administrator deleted
 * and re-point roles they deliberately changed. So a deployment that adopted
 * accounting before this branch has twelve mapping rows and will never get a
 * thirteenth from the seeder.
 *
 * That matters because the posting engine fails CLOSED: a repayment carrying an
 * overpayment asks for `borrower_advances`, finds nothing, and refuses to record
 * the payment. Shipping the role without this backfill would turn a correctness
 * improvement into an outage on precisely the deployments furthest along with
 * the module.
 *
 * The blast radius is the OVERPAYING payment only, and that is a property of
 * PostingRules::creditIfAny() rather than a happy accident: roles are resolved
 * per LEG, so a role behind a zero line is never asked for. An earlier draft of
 * this engine resolved every role in the array literal before dropping the zero
 * lines, which made this migration the difference between "overpayments fail"
 * and "EVERY repayment fails". Keep that helper, or this comment stops being
 * true.
 *
 * ## Deliberately narrow
 *
 * - Does nothing when there is no chart. An organisation that has not adopted
 *   accounting gets its mapping from the seeder when it does, and inserting a
 *   row here would leave it with a mapping table and no accounts.
 * - Does nothing when the role is already mapped, so a re-run — or a
 *   deployment that seeds after this migration — cannot overwrite an
 *   administrator's choice.
 * - Does nothing when 2300 is absent, deactivated, or has been turned into a
 *   heading. Guessing at "some other liability account" would put borrower
 *   money in an account nobody chose and report it, balanced and plausible, on
 *   the balance sheet. Leaving it unmapped means the engine says exactly which
 *   setting is missing the first time it needs it, which is the failure a
 *   person can act on.
 *
 * No new table, so nothing to register in TimezoneShift.
 */
return new class extends Migration
{
    private const ROLE = 'borrower_advances';

    /** 2300 Other Liabilities. See ChartOfAccountsSeeder::DEFAULT_MAPPING_CODES. */
    private const CODE = '2300';

    public function up(): void
    {
        // No chart means this organisation keeps no books yet. The seeder will
        // create the full mapping, this role included, if it ever does.
        if (! DB::table('accounting_accounts')->exists()) {
            return;
        }

        if (DB::table('accounting_account_mappings')->where('role', self::ROLE)->exists()) {
            return;
        }

        // The same shape AccountingAccountMapping::roleMismatch() enforces when
        // this mapping is set from the settings screen. Writing the row
        // directly bypasses that validator, and resolved() hands whatever is
        // stored straight to the engine — so a `cash_kind` on this account
        // would make AccountingDashboardBuilder::sumByCashKind() report every
        // borrower overpayment as cash on hand.
        $account = DB::table('accounting_accounts')
            ->where('code', self::CODE)
            ->where('type', 'liability')
            ->where('is_active', true)
            ->where('is_group', false)
            ->where('is_contra', false)
            ->whereNull('cash_kind')
            ->value('id');

        if ($account === null) {
            return;
        }

        DB::table('accounting_account_mappings')->insert([
            'role' => self::ROLE,
            'accounting_account_id' => $account,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Removes only the row this migration could have written.
     *
     * A rollback that also dropped an administrator's re-pointed mapping would
     * destroy a deliberate setting to undo an automatic one, so the delete is
     * keyed on the account this migration actually chose.
     *
     * NOTE it cannot tell that row apart from the one ChartOfAccountsSeeder
     * writes on a fresh install — they are byte-identical, and there is no
     * provenance column to distinguish them. Rolling this back on a
     * seeder-provisioned deployment therefore removes a legitimate mapping and
     * re-creates the outage the `up()` exists to prevent. Migrations are not
     * rolled back in production; do not make this one the exception.
     */
    public function down(): void
    {
        $account = DB::table('accounting_accounts')->where('code', self::CODE)->value('id');

        if ($account === null) {
            return;
        }

        DB::table('accounting_account_mappings')
            ->where('role', self::ROLE)
            ->where('accounting_account_id', $account)
            ->delete();
    }
};
