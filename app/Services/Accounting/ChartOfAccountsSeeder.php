<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * The default chart of accounts a new organisation starts from.
 *
 * A TEMPLATE, not a fixed structure — administrators add, rename and deactivate
 * accounts, and a cooperative's equity and liability sections look nothing like
 * a lending corporation's (share capital, statutory funds and undivided net
 * surplus in place of capital and retained earnings). This is day one, not
 * the law.
 *
 * Codes follow the classification convention {@see AccountRules::typeFromCode()}
 * relies on: 1xxx asset, 2xxx liability, 3xxx equity, 4xxx income, 5xxx expense.
 * Keep it that way — the leading digit is what every statement reads.
 *
 * ## Kept in step with the frontend, code for code
 *
 * {@see self::CHART} mirrors `DEFAULT_CHART_OF_ACCOUNTS` in
 * `src/constants/chart-of-accounts.ts`, and `AccountingChartOfAccountsTest`
 * asserts codes AND names against a fixture copied from it. The frontend renders
 * that constant as a read-only preview before a chart has ever been saved, so a
 * divergence shows a user one chart and seeds them another.
 *
 * TWO ROWS DEVIATE, deliberately, and the frontend constant needs the same two
 * changes:
 *
 * 1. **3040 Current Year Earnings is a group here** and postable there.
 *    `statements.ts` ALSO derives 3040 as a synthetic balance-sheet line from
 *    the period's net income, so anything posted to the real account would be
 *    counted twice on the same statement — once as the posting, once as the
 *    derivation. It stays in the chart because year-end closing needs somewhere
 *    to move the result to; it must not accept ordinary entries in the meantime.
 * 2. **3050 Opening Balance Equity does not exist there.** The opening-balance
 *    backfill needs a named plug for the difference between the assets and
 *    liabilities an organisation arrives with. Folding it into 3030 Retained
 *    Earnings hides it permanently, in the one account nobody ever reconciles.
 *    Deliberately NOT 3045: `statements.ts` already uses that code for its
 *    synthetic prior-period earnings line.
 */
final class ChartOfAccountsSeeder
{
    /**
     * The seed rows, parents before children so ids resolve in one pass.
     *
     * @var list<array{code: string, name: string, type: string, parent?: string, is_group?: bool, is_contra?: bool, cash_kind?: string}>
     */
    public const CHART = [
        // ── Assets ──
        ['code' => '1000', 'name' => 'Assets', 'type' => 'asset', 'is_group' => true],

        ['code' => '1010', 'name' => 'Cash on Hand', 'type' => 'asset', 'parent' => '1000', 'cash_kind' => 'cash'],
        ['code' => '1020', 'name' => 'GCash', 'type' => 'asset', 'parent' => '1000', 'cash_kind' => 'gcash'],
        ['code' => '1030', 'name' => 'Maya', 'type' => 'asset', 'parent' => '1000', 'cash_kind' => 'maya'],
        ['code' => '1040', 'name' => 'Bank Accounts', 'type' => 'asset', 'parent' => '1000', 'cash_kind' => 'bank'],

        ['code' => '1100', 'name' => 'Loans Receivable', 'type' => 'asset', 'parent' => '1000', 'is_group' => true],
        ['code' => '1110', 'name' => 'Current Loans Receivable', 'type' => 'asset', 'parent' => '1100'],
        ['code' => '1120', 'name' => 'Past Due Loans Receivable', 'type' => 'asset', 'parent' => '1100'],

        // These three hang off 1000, NOT off 1100. They are not loan principal,
        // and rolling them into Loans Receivable would overstate the portfolio
        // by the interest and penalties it has not collected yet.
        ['code' => '1150', 'name' => 'Interest Receivable', 'type' => 'asset', 'parent' => '1000'],
        ['code' => '1160', 'name' => 'Penalty Receivable', 'type' => 'asset', 'parent' => '1000'],
        ['code' => '1170', 'name' => 'Other Receivables', 'type' => 'asset', 'parent' => '1000'],

        // Credit-balanced asset: it REDUCES loans receivable. Net Loans
        // Receivable is gross minus this, which is why `is_contra` has to be set.
        ['code' => '1200', 'name' => 'Allowance for Credit Losses', 'type' => 'asset', 'parent' => '1000', 'is_contra' => true],

        ['code' => '1300', 'name' => 'Prepaid Expenses', 'type' => 'asset', 'parent' => '1000'],

        ['code' => '1400', 'name' => 'Property and Equipment', 'type' => 'asset', 'parent' => '1000', 'is_group' => true],
        ['code' => '1410', 'name' => 'Office Equipment', 'type' => 'asset', 'parent' => '1400'],
        ['code' => '1420', 'name' => 'Computer Equipment', 'type' => 'asset', 'parent' => '1400'],
        ['code' => '1490', 'name' => 'Accumulated Depreciation', 'type' => 'asset', 'parent' => '1400', 'is_contra' => true],

        // ── Liabilities ──
        ['code' => '2000', 'name' => 'Liabilities', 'type' => 'liability', 'is_group' => true],

        ['code' => '2010', 'name' => 'Accounts Payable', 'type' => 'liability', 'parent' => '2000'],
        ['code' => '2020', 'name' => 'Accrued Expenses', 'type' => 'liability', 'parent' => '2000'],

        // 2110 is a SIBLING of 2100, not a child of it. Money the organisation
        // borrowed from a bank and money it borrowed elsewhere are both
        // liabilities of its own; neither is a component of the other, and 2100
        // is a postable account rather than a heading.
        ['code' => '2100', 'name' => 'Loans Payable', 'type' => 'liability', 'parent' => '2000'],
        ['code' => '2110', 'name' => 'Other Borrowings', 'type' => 'liability', 'parent' => '2000'],

        ['code' => '2200', 'name' => 'Taxes Payable', 'type' => 'liability', 'parent' => '2000', 'is_group' => true],
        ['code' => '2210', 'name' => 'Withholding Tax Payable', 'type' => 'liability', 'parent' => '2200'],
        ['code' => '2220', 'name' => 'Other Taxes Payable', 'type' => 'liability', 'parent' => '2200'],

        ['code' => '2300', 'name' => 'Other Liabilities', 'type' => 'liability', 'parent' => '2000'],

        // ── Equity ──
        // A cooperative replaces these with Share Capital, Statutory Funds,
        // Reserve Fund and Undivided Net Surplus. Left as the corporate default;
        // the organisation type decides which set is seeded.
        ['code' => '3000', 'name' => 'Equity', 'type' => 'equity', 'is_group' => true],
        ['code' => '3010', 'name' => 'Capital', 'type' => 'equity', 'parent' => '3000'],
        ['code' => '3020', 'name' => 'Additional Capital', 'type' => 'equity', 'parent' => '3000'],
        ['code' => '3030', 'name' => 'Retained Earnings', 'type' => 'equity', 'parent' => '3000'],

        // Non-postable on purpose — see the class docblock. The balance sheet
        // derives this line from the period's net income, so a posting to it
        // would appear on the statement twice.
        ['code' => '3040', 'name' => 'Current Year Earnings', 'type' => 'equity', 'parent' => '3000', 'is_group' => true],

        // The named plug for opening balances. Also see the class docblock.
        ['code' => '3050', 'name' => 'Opening Balance Equity', 'type' => 'equity', 'parent' => '3000'],

        // ── Income ──
        ['code' => '4000', 'name' => 'Income', 'type' => 'income', 'is_group' => true],
        ['code' => '4010', 'name' => 'Interest Income', 'type' => 'income', 'parent' => '4000'],
        ['code' => '4020', 'name' => 'Penalty Income', 'type' => 'income', 'parent' => '4000'],
        ['code' => '4030', 'name' => 'Loan Processing Fee Income', 'type' => 'income', 'parent' => '4000'],
        ['code' => '4040', 'name' => 'Service Fee Income', 'type' => 'income', 'parent' => '4000'],
        ['code' => '4050', 'name' => 'Membership Fee Income', 'type' => 'income', 'parent' => '4000'],
        ['code' => '4060', 'name' => 'Other Lending Income', 'type' => 'income', 'parent' => '4000'],
        ['code' => '4070', 'name' => 'Other Income', 'type' => 'income', 'parent' => '4000'],

        // ── Expenses ──
        ['code' => '5000', 'name' => 'Expenses', 'type' => 'expense', 'is_group' => true],
        ['code' => '5010', 'name' => 'Salaries and Wages', 'type' => 'expense', 'parent' => '5000'],
        ['code' => '5020', 'name' => 'Rent', 'type' => 'expense', 'parent' => '5000'],
        ['code' => '5030', 'name' => 'Electricity', 'type' => 'expense', 'parent' => '5000'],
        ['code' => '5040', 'name' => 'Internet', 'type' => 'expense', 'parent' => '5000'],
        ['code' => '5050', 'name' => 'Transportation', 'type' => 'expense', 'parent' => '5000'],
        ['code' => '5060', 'name' => 'Office Supplies', 'type' => 'expense', 'parent' => '5000'],
        ['code' => '5070', 'name' => 'Bank Charges', 'type' => 'expense', 'parent' => '5000'],
        ['code' => '5080', 'name' => 'GCash Charges', 'type' => 'expense', 'parent' => '5000'],
        ['code' => '5090', 'name' => 'Software Expenses', 'type' => 'expense', 'parent' => '5000'],
        ['code' => '5100', 'name' => 'Professional Fees', 'type' => 'expense', 'parent' => '5000'],
        ['code' => '5110', 'name' => 'Advertising', 'type' => 'expense', 'parent' => '5000'],
        ['code' => '5120', 'name' => 'Communication', 'type' => 'expense', 'parent' => '5000'],
        ['code' => '5130', 'name' => 'Depreciation Expense', 'type' => 'expense', 'parent' => '5000'],
        ['code' => '5140', 'name' => 'Credit Loss Expense', 'type' => 'expense', 'parent' => '5000'],
        ['code' => '5150', 'name' => 'Miscellaneous Expense', 'type' => 'expense', 'parent' => '5000'],
    ];

    /**
     * Which seeded account each posting role points at by default.
     *
     * Mirrors `DEFAULT_ACCOUNT_MAPPING_CODES` on the frontend. Note
     * `loans_receivable` points at 1110, the LEAF — 1100 is the heading above
     * it, and a posting rule that resolved to a group would double-count the
     * subtree it sums.
     *
     * @var array<string, string>
     */
    public const DEFAULT_MAPPING_CODES = [
        'cash' => '1010',
        'gcash' => '1020',
        'maya' => '1030',
        'bank' => '1040',
        'loans_receivable' => '1110',
        'interest_receivable' => '1150',
        'penalty_receivable' => '1160',
        'interest_income' => '4010',
        'penalty_income' => '4020',
        'processing_fee_income' => '4030',
        'credit_loss_expense' => '5140',
        'allowance_credit_losses' => '1200',
        'accounts_payable' => '2010',
    ];

    /** Whether this organisation already has a chart. */
    public function hasChart(): bool
    {
        return AccountingAccount::query()->exists();
    }

    /**
     * Create the default chart and its posting defaults. One-time.
     *
     * Refuses over an existing chart rather than merging into it. Merging would
     * silently resurrect accounts an administrator deliberately removed, and
     * re-point roles they deliberately changed — and it would do both to books
     * that may already have history hanging off them. The check runs inside the
     * transaction, and the unique index on `code` is what settles a genuine race
     * between two callers.
     *
     * @return Collection<int, AccountingAccount> the whole chart, in code order
     */
    public function seed(?int $createdBy = null): Collection
    {
        try {
            return $this->seedInTransaction($createdBy);
        } catch (UniqueConstraintViolationException $e) {
            /*
             * TWO SEEDS AT ONCE — a double-clicked button, or a retried request.
             *
             * The check below is a SELECT, so both callers can pass it before
             * either inserts. `accounting_accounts.code` is uniquely indexed, so
             * the database settles it correctly and only one chart is ever
             * created — but the loser's unhandled QueryException surfaced as a
             * 500 on what is, from the operator's point of view, exactly the
             * situation the 409 exists to describe. The whole losing attempt
             * rolled back inside the transaction, so re-answering here reports
             * the outcome rather than papering over a partial write.
             *
             * The re-check is what keeps this honest. Without it EVERY unique
             * violation became "you already have a chart" — including a
             * duplicate `code` inside the CHART constant itself, which would
             * report that forever against a completely empty table and send
             * whoever hit it looking for accounts that do not exist. If no
             * chart is there, this was not a race and the real error is the
             * useful one.
             */
            if (! $this->hasChart()) {
                throw $e;
            }

            throw $this->alreadySeeded();
        }
    }

    /**
     * @throws HttpResponseException when a chart already exists
     */
    private function seedInTransaction(?int $createdBy): Collection
    {
        return DB::transaction(function () use ($createdBy) {
            if ($this->hasChart()) {
                throw $this->alreadySeeded();
            }

            $idByCode = [];

            /*
             * One summary audit row instead of sixty-nine.
             *
             * Both models are Auditable, so creating the chart row by row would
             * write an audit entry per account and per mapping for what is a
             * single administrative act — burying every other entry of that day
             * under it. The importer solved the same problem the same way; note
             * this suppresses the audit LISTENER, not the model events, so
             * anything else hanging off `created` still runs.
             */
            AuditLogService::withoutModelAuditing(function () use (&$idByCode, $createdBy): void {
                foreach (self::CHART as $seed) {
                    $account = AccountingAccount::create([
                        'code' => $seed['code'],
                        'name' => $seed['name'],
                        'type' => $seed['type'],
                        'is_contra' => $seed['is_contra'] ?? false,
                        'is_group' => $seed['is_group'] ?? false,
                        'is_active' => true,
                        'cash_kind' => $seed['cash_kind'] ?? null,
                        // Parents always precede their children in CHART, so one
                        // pass is enough and a missing parent is a broken
                        // constant rather than an ordering accident.
                        'parent_id' => isset($seed['parent']) ? $idByCode[$seed['parent']] : null,
                        'created_by' => $createdBy,
                    ]);

                    $idByCode[$seed['code']] = $account->id;
                }

                foreach (self::DEFAULT_MAPPING_CODES as $role => $code) {
                    AccountingAccountMapping::create([
                        'role' => $role,
                        'accounting_account_id' => $idByCode[$code],
                    ]);
                }
            });

            AuditLogService::log(
                'chart_of_accounts_seeded',
                null,
                null,
                [
                    'accounts' => count(self::CHART),
                    'mappings' => count(self::DEFAULT_MAPPING_CODES),
                ],
                'Seeded the default chart of accounts and its posting defaults.',
                $createdBy,
            );

            return AccountingAccount::query()->inCodeOrder()->get();
        });
    }

    /**
     * Refusing to seed over an existing chart.
     *
     * 409 rather than 422: nothing about the request is invalid, the resource
     * simply already exists. Merging instead would resurrect accounts an
     * administrator deliberately removed and re-point posting roles they
     * deliberately changed — silently, over a chart that may already carry
     * history.
     */
    private function alreadySeeded(): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => 'This organisation already has a chart of accounts. Seeding again would overwrite accounts that may already carry history.',
        ], 409));
    }
}
