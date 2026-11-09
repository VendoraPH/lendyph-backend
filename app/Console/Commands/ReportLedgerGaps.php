<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Accounting\LedgerGapsReport;
use App\Services\Accounting\Money;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The past events the books never heard of, or heard of differently from
 * today's rules: the same rows and totals as `GET /accounting/ledger-gaps`,
 * for every branch. See {@see LedgerGapsReport} for what each kind is.
 *
 * ## It only ever reads
 *
 * Every statement is a SELECT: no transaction is opened and nothing is
 * written, so it is safe to run on production as it is. It is a report, not
 * a fix: nothing is back-posted.
 */
#[Signature('accounting:ledger-gaps-report
    {--from= : Only events on or after this date (Y-m-d)}
    {--to= : Only events on or before this date (Y-m-d)}
    {--kind= : Only one kind: release, collection, build_up, restructure, adjustment, share_capital, cash_in or cash_out}')]
#[Description('List past events that posted nothing to the books, or posted differently from today\'s rules. Read only')]
class ReportLedgerGaps extends Command
{
    private const NOTHING = '—';

    public function handle(LedgerGapsReport $report): int
    {
        $filters = $this->filters();

        if ($filters === null) {
            return self::FAILURE;
        }

        $this->info('Read only. Nothing is written.');
        $this->newLine();

        if (! $report->booksKept()) {
            $this->line(LedgerGapsReport::NO_BOOKS_NOTICE);

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($report->rows($filters) as $row) {
            $rows[] = [
                $row['kind_label'],
                (string) ($row['reference'] ?? "#{$row['record_id']}"),
                $row['date'] ?? self::NOTHING,
                $row['branch']['name'] ?? self::NOTHING,
                $row['member'] === null ? self::NOTHING : "{$row['member']['name']} ({$row['member']['code']})",
                Money::format($this->centavos($row['amount'])),
                $row['posted'],
                $row['reason'],
            ];
        }

        $this->line('Events that posted nothing, or posted differently from today\'s rules');

        if ($rows === []) {
            $this->line('  None.');
        } else {
            $this->table(['Kind', 'Record', 'Date', 'Branch', 'Member', 'Amount', 'Posted', 'Why'], $rows);
        }

        $totals = $report->totals($filters, null);

        $this->newLine();
        $this->line('Totals by kind');
        $this->table(['Kind', 'Events', 'Amount'], array_map(
            fn (string $kind): array => [
                LedgerGapsReport::KIND_LABELS[$kind],
                number_format($totals['by_kind'][$kind]['count']),
                Money::format($this->centavos($totals['by_kind'][$kind]['amount'])),
            ],
            array_keys($totals['by_kind']),
        ));

        $this->line(sprintf('  %-16s %s', 'Events listed:', number_format($totals['count'])));

        return self::SUCCESS;
    }

    /**
     * The options as the report takes them, or null (after saying why) when
     * one is not usable.
     *
     * @return array{kind: string|null, from: string|null, to: string|null}|null
     */
    private function filters(): ?array
    {
        $kind = $this->option('kind');

        if (filled($kind) && ! in_array($kind, LedgerGapsReport::KINDS, true)) {
            $this->error('Unknown --kind "'.$kind.'". Use one of: '.implode(', ', LedgerGapsReport::KINDS).'.');

            return null;
        }

        $dates = [];

        foreach (['from', 'to'] as $option) {
            $value = $this->option($option);

            if (filled($value) && ! $this->isDate((string) $value)) {
                $this->error("--{$option} must be a date written Y-m-d, such as 2026-10-31.");

                return null;
            }

            $dates[$option] = filled($value) ? (string) $value : null;
        }

        if ($dates['from'] !== null && $dates['to'] !== null && $dates['from'] > $dates['to']) {
            $this->error('--from must be on or before --to.');

            return null;
        }

        return ['kind' => filled($kind) ? (string) $kind : null, ...$dates];
    }

    private function isDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    /** The report's "1500.00" (always two decimals) back to centavos, for Money::format(). */
    private function centavos(string $pesos): int
    {
        return (int) str_replace('.', '', $pesos);
    }
}
