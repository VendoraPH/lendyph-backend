<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use Illuminate\Validation\ValidationException;

/**
 * Moving the organisation's own money between its own accounts.
 *
 * ## This is not income, and getting that wrong is expensive
 *
 * The Cash & Bank screen states it before anything else on the page, because it
 * is the single most damaging mistake a naive lending system makes: a
 * GCash-to-bank sweep recorded as revenue inflates the income statement by the
 * entire amount swept, every time money is moved, and the books still balance.
 * A co-op that sweeps ₱200,000 a week would report ₱10.4M of income it never
 * earned and pay tax on it.
 *
 * So this posts the `fund_transfer` rule from
 * `src/lib/accounting/posting-rules.ts` and nothing else: one asset down, one
 * asset up, no income account anywhere near it.
 *
 *     Dr  destination account      amount
 *     Dr  charge expense account   charge      (only when there is a charge)
 *     Cr  source account           amount + charge
 *
 * ## Who bears the charge
 *
 * The SOURCE. The dialog asks for an amount and a charge as two separate
 * figures, so `amount` is what arrives at the destination and the charge is
 * taken on top, out of the account the money left. The alternative reading —
 * netting the charge out of what arrives — would make the destination's balance
 * disagree with the figure the user typed, and they would have no way to tell
 * which of the two the system had used.
 *
 * ## One divergence from the rule set
 *
 * `fund_transfer` names its two sides by SettlementMethod (`cash`, `gcash`,
 * `maya`, `bank`) and resolves them through the account mapping. This takes
 * account ids, because the transfer dialog picks from the real money accounts
 * on the Cash & Bank screen and an organisation with three bank accounts has
 * one `bank` role and three places the money could have gone. Same journal,
 * accounts named explicitly.
 *
 * MONEY IS IN CENTAVOS.
 */
class FundTransferRecorder
{
    use ResolvesPostingAccounts;

    /**
     * Where a transfer charge lands when the caller does not say.
     *
     * The codes of the default chart's two charge accounts, by the cash kind of
     * the account the money left. A convenience, not a contract: an
     * organisation that renamed or removed them gets a 422 naming
     * `charge_account_id`, never a charge quietly posted somewhere else. The
     * transfer dialog sends no account for the charge, so without this a charge
     * could not be recorded at all on a default chart.
     */
    private const DEFAULT_CHARGE_ACCOUNT_CODES = [
        'gcash' => '5080',  // GCash Charges
        'maya' => '5080',
        'bank' => '5070',   // Bank Charges
        'cash' => '5070',
        'wallet' => '5070',
    ];

    public function __construct(private JournalPoster $poster) {}

    /**
     * Posts the transfer and returns the journal entry it wrote.
     *
     * @param  array{
     *     date: string,
     *     from_account_id: int,
     *     to_account_id: int,
     *     amount: int,
     *     charge?: int|null,
     *     charge_account_id?: int|null,
     *     description: string,
     *     branch_id?: int|null,
     *     reference?: string|null,
     * }  $data
     */
    public function record(array $data, ?int $userId = null): AccountingJournal
    {
        $amount = (int) $data['amount'];
        $charge = (int) ($data['charge'] ?? 0);

        $from = $this->requireMoneyAccount((int) $data['from_account_id'], 'from_account_id');
        $to = $this->requireMoneyAccount((int) $data['to_account_id'], 'to_account_id');

        if ($from->id === $to->id) {
            // Two lines against one account net to nothing and read as activity
            // that never happened. The dialog refuses it too; this is the half
            // that holds for every other caller.
            throw ValidationException::withMessages([
                'to_account_id' => ['A transfer cannot move money into the same account it came from.'],
            ]);
        }

        $lines = [
            ['account_id' => $to->id, 'debit' => $amount, 'credit' => 0],
        ];

        if ($charge > 0) {
            $chargeAccount = $this->resolveChargeAccount($data['charge_account_id'] ?? null, $from);

            $lines[] = ['account_id' => $chargeAccount->id, 'debit' => $charge, 'credit' => 0];
        }

        // The source gives up both. Summed through Money so the arithmetic is
        // the same arithmetic the poster will recompute the totals with.
        $lines[] = ['account_id' => $from->id, 'debit' => 0, 'credit' => Money::sum([$amount, $charge])];

        return $this->poster->postImmediately(
            [
                'date' => $data['date'],
                'source' => 'transfer',
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'],
                'branch_id' => $data['branch_id'] ?? null,
                // No postable, and so no idempotency key. A transfer is not
                // raised BY a document — it is the document — so there is
                // nothing for the poster's (postable, source) guard to dedupe
                // on. Two identical submissions produce two journals, both
                // real and both reversible.
            ],
            $lines,
            $userId,
        );
    }

    /**
     * The expense account a transfer charge lands in.
     *
     * Explicit if given, the default chart's charge account otherwise, and a
     * refusal if neither resolves. Never a silent skip: a charge dropped from
     * the entry would leave the source account credited for the full amount
     * while the journal only accounted for part of it — which cannot happen,
     * because the entry would not balance — or, worse, would quietly shrink the
     * credit so the books balanced and the money simply never left.
     */
    private function resolveChargeAccount(?int $explicitId, AccountingAccount $from): AccountingAccount
    {
        if ($explicitId !== null) {
            return $this->requireExpenseAccount($explicitId, 'charge_account_id');
        }

        $code = self::DEFAULT_CHARGE_ACCOUNT_CODES[$from->cash_kind] ?? '5070';

        $account = AccountingAccount::query()
            ->where('code', $code)
            ->postable()
            ->where('type', 'expense')
            ->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'charge_account_id' => [
                    'There is a charge on this transfer and no expense account to put it in. This chart has no '
                    ."active expense account {$code}, so send `charge_account_id` with the account the charge "
                    .'belongs to. Nothing has been recorded.',
                ],
            ]);
        }

        return $account;
    }
}
