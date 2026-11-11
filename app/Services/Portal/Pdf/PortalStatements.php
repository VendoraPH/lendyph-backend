<?php

namespace App\Services\Portal\Pdf;

use App\Models\Borrower;
use App\Models\BrandingSetting;
use App\Models\Loan;
use App\Models\SavingsAccount;
use App\Models\TimeDeposit;
use App\Services\Portal\Auth\MemberNumber;
use App\Services\Portal\Read\MemberWords;
use App\Services\Portal\Read\Pesos;
use App\Services\Portal\Read\SavingsReader;
use App\Services\ReportService;
use App\Services\Savings\Reports\StatementBuilder;
use Carbon\CarbonImmutable;

/**
 * The member's printed statements and certificates (portal design §1, §3),
 * each built from the staff screen's own builder, so every printed figure is
 * the staff figure:
 *
 * - a loan's Statement of Account: ReportService::statementOfAccount(), "As of"
 *   today, its Ledger lines and summary;
 * - the Share Capital Statement and Certificate: ReportService::shareCapitalStatement();
 * - a savings statement: the savings StatementBuilder;
 * - a time deposit certificate: TimeDepositPresenter (through SavingsReader).
 *
 * They print the member's name, number and branch only: never an address, a
 * contact number, the account officer or who posted a line. Money is ₱ with
 * two decimals.
 */
final class PortalStatements
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly StatementBuilder $savingsStatements,
        private readonly SavingsReader $savings,
    ) {}

    public function loan(Loan $loan): PortalDocument
    {
        $loan->loadMissing('borrower.branch');
        $soa = $this->reports->statementOfAccount($loan);
        $summary = $soa['summary'];
        $asOf = $soa['as_of'];

        $rows = array_map(fn (array $line): array => [
            $this->day($line['date']),
            $line['description'].($line['voided'] ? ' (voided)' : ''),
            $line['receipt_number'],
            $line['debit'] === null ? null : Pesos::printed($line['debit']),
            $line['credit'] === null ? null : Pesos::printed($line['credit']),
        ], $soa['transactions']);

        return $this->document(
            title: 'Statement of Account',
            subtitle: 'As of '.$this->day($asOf),
            member: $loan->borrower,
            lines: [
                ['Loan account', $loan->loan_account_number],
                ['Product', $soa['loan']['product_name']],
                ['Released', $this->day($soa['loan']['released_at'])],
                ['Maturity date', $this->day($soa['loan']['maturity_date'])],
                ['Term', $soa['loan']['term_label']],
                ['Interest rate', $soa['loan']['rate_label']],
                ['Status', $soa['loan']['status_label']],
            ],
            sections: [
                $this->fields('What is still owed', [
                    ['Principal Balance', Pesos::printed($summary['outstanding_principal'])],
                    ['Interest', Pesos::printed($summary['outstanding_interest'])],
                    ['Penalty', Pesos::printed($summary['outstanding_penalty'])],
                    ['Insurance premium', Pesos::printed($summary['insurance_owed'])],
                    ['Outstanding', Pesos::printed($summary['outstanding_balance'])],
                    ['Past due', Pesos::printed($summary['overdue_amount'])],
                    ['Total paid', Pesos::printed($summary['total_paid'])],
                ]),
                $this->table('Transactions', ['Date', 'Particulars', 'Receipt', 'Debit', 'Credit'], [3, 4], $rows, [
                    'Total', null, null,
                    Pesos::printed($soa['transaction_totals']['debit']),
                    Pesos::printed($soa['transaction_totals']['credit']),
                ], 'No transactions yet.'),
            ],
            fileName: 'statement-of-account-'.$loan->loan_account_number.'-'.str_replace('-', '', $asOf).'.pdf',
        );
    }

    public function shareCapitalStatement(Borrower $member, string $from, string $to): PortalDocument
    {
        $statement = $this->reports->shareCapitalStatement($member, ['date_from' => $from, 'date_to' => $to]);

        $rows = array_map(fn (array $entry): array => [
            $this->day($entry['date']),
            $entry['reference'],
            $entry['description'],
            Pesos::printed($entry['debit']),
            Pesos::printed($entry['credit']),
            Pesos::printed($entry['running_balance']),
        ], $statement['entries']);

        return $this->document(
            title: 'Share Capital Statement',
            subtitle: $this->day($statement['date_from']).' to '.$this->day($statement['date_to']),
            member: $member,
            lines: [],
            sections: [
                $this->fields(null, [
                    ['Opening balance', Pesos::printed($statement['opening_balance'])],
                    ['Closing balance', Pesos::printed($statement['closing_balance'])],
                    ['Pledged', $statement['pledge'] === null ? null : Pesos::printed($statement['pledge']['amount'])],
                ]),
                $this->table('Entries', ['Date', 'Reference', 'Description', 'Debit', 'Credit', 'Balance'], [3, 4, 5], $rows, [
                    'Total', null, null,
                    Pesos::printed($statement['totals']['debits']),
                    Pesos::printed($statement['totals']['credits']),
                    Pesos::printed($statement['closing_balance']),
                ], 'No entries in this period.'),
            ],
            fileName: 'share-capital-statement-'.str_replace('-', '', $from).'-'.str_replace('-', '', $to).'.pdf',
        );
    }

    public function shareCapitalCertificate(Borrower $member, string $asOf): PortalDocument
    {
        $statement = $this->reports->shareCapitalStatement($member, ['date_to' => $asOf]);
        $coop = $this->coopName();

        return $this->document(
            title: 'Share Capital Certificate',
            subtitle: 'As of '.$this->day($asOf),
            member: $member,
            lines: [],
            sections: [
                $this->text(null, [
                    sprintf(
                        'This certifies that %s, member number %s, holds paid-up share capital of %s in %s as of %s.',
                        $member->full_name,
                        MemberNumber::of($member),
                        Pesos::printed($statement['closing_balance']),
                        $coop,
                        $this->day($asOf),
                    ),
                ]),
                $this->fields(null, [
                    ['Paid-up share capital', Pesos::printed($statement['closing_balance'])],
                    ['Pledged', $statement['pledge'] === null ? null : Pesos::printed($statement['pledge']['amount'])],
                    ['Contributions to date', Pesos::printed($statement['totals']['credits'])],
                ]),
            ],
            fileName: 'share-capital-certificate-'.str_replace('-', '', $asOf).'.pdf',
        );
    }

    public function savingsStatement(SavingsAccount $account, ?string $from, ?string $to): PortalDocument
    {
        $account->loadMissing('borrower.branch');
        $statement = $this->savingsStatements->build($account, $from, $to);

        $rows = array_map(fn (array $row): array => [
            $this->day($row['date']),
            $row['reference'],
            MemberWords::of(MemberWords::SAVINGS_TYPE, $row['type']),
            Pesos::printed($row['debit']),
            Pesos::printed($row['credit']),
            Pesos::printed($row['balance_after']),
        ], $statement['rows']);

        return $this->document(
            title: 'Savings Statement',
            subtitle: $this->day($statement['date_from']).' to '.$this->day($statement['date_to']),
            member: $account->borrower,
            lines: [
                ['Account number', $account->account_number],
                ['Product', $statement['account']['product_name']],
            ],
            sections: [
                $this->fields(null, [
                    ['Opening balance', Pesos::printed($statement['opening_balance'])],
                    ['Closing balance', Pesos::printed($statement['closing_balance'])],
                ]),
                $this->table('Transactions', ['Date', 'Reference', 'Type', 'Debit', 'Credit', 'Balance'], [3, 4, 5], $rows, [
                    'Total', null, null,
                    Pesos::printed($statement['totals']['debit']),
                    Pesos::printed($statement['totals']['credit']),
                    Pesos::printed($statement['closing_balance']),
                ], 'No transactions in this period.'),
            ],
            fileName: 'savings-statement-'.$account->account_number.'-'.str_replace('-', '', $statement['date_to']).'.pdf',
        );
    }

    public function timeDepositCertificate(TimeDeposit $deposit): PortalDocument
    {
        $deposit->loadMissing('borrower.branch');
        $figures = $this->savings->timeDeposit($deposit);

        return $this->document(
            title: 'Time Deposit Certificate',
            subtitle: 'Certificate '.$figures['certificate_number'],
            member: $deposit->borrower,
            lines: [],
            sections: [
                $this->fields(null, [
                    ['Principal', Pesos::printed($figures['principal'])],
                    ['Annual rate', $figures['annual_rate'].'%'],
                    ['Term', $figures['term_days'].' days'],
                    ['Placed on', $this->day($figures['placement_date'])],
                    ['Matures on', $this->day($figures['maturity_date'])],
                    ['Interest', Pesos::printed($figures['gross_interest'])],
                    ['Withholding tax', Pesos::printed($figures['withholding_tax'])],
                    ['Net interest', Pesos::printed($figures['net_interest'])],
                    ['Amount at maturity', Pesos::printed($figures['maturity_amount'])],
                    ['At maturity', MemberWords::of(MemberWords::MATURITY_INSTRUCTION, $figures['maturity_instruction'])],
                ]),
            ],
            fileName: 'time-deposit-certificate-'.$figures['certificate_number'].'.pdf',
        );
    }

    /**
     * @param  list<array{0: string, 1: string|null}>  $lines
     * @param  list<array<string, mixed>>  $sections
     */
    private function document(string $title, string $subtitle, Borrower $member, array $lines, array $sections, string $fileName): PortalDocument
    {
        $member->loadMissing('branch');
        $branding = BrandingSetting::current();

        return new PortalDocument(
            title: $title,
            subtitle: $subtitle,
            coopName: $this->coopName(),
            coopAddress: $branding->organization_address,
            memberLines: [
                ['Member', $member->full_name],
                ['Member number', MemberNumber::of($member)],
                ['Branch', $member->branch?->name],
                ...$lines,
            ],
            sections: $sections,
            footer: 'Printed from the Member Portal on '.now()->timezone('Asia/Manila')->format('j M Y, g:i A').'. Figures are as recorded by the cooperative.',
            fileName: $fileName,
        );
    }

    private function coopName(): string
    {
        return (string) (BrandingSetting::current()->organization_name ?: config('app.name'));
    }

    /**
     * @param  list<array{0: string, 1: string|null}>  $fields
     * @return array<string, mixed>
     */
    private function fields(?string $heading, array $fields): array
    {
        return ['type' => 'fields', 'heading' => $heading, 'fields' => $fields];
    }

    /**
     * @param  list<string>  $columns
     * @param  list<int>  $numeric
     * @param  list<list<string|null>>  $rows
     * @param  list<string|null>  $totals
     * @return array<string, mixed>
     */
    private function table(string $heading, array $columns, array $numeric, array $rows, array $totals, string $empty): array
    {
        return ['type' => 'table', 'heading' => $heading, 'columns' => $columns, 'numeric' => $numeric, 'rows' => $rows, 'totals' => $totals, 'empty' => $empty];
    }

    /**
     * @param  list<string>  $paragraphs
     * @return array<string, mixed>
     */
    private function text(?string $heading, array $paragraphs): array
    {
        return ['type' => 'text', 'heading' => $heading, 'paragraphs' => $paragraphs];
    }

    private function day(?string $date): ?string
    {
        return $date === null ? null : CarbonImmutable::parse($date)->format('j M Y');
    }
}
