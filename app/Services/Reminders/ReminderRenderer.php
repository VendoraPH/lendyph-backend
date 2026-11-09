<?php

namespace App\Services\Reminders;

use App\Models\BrandingSetting;
use App\Models\Loan;
use App\Models\ReminderBranchSetting;
use App\Models\ReminderSetting;
use Carbon\CarbonInterface;

/**
 * Fills a template's `{{variables}}` for one loan and period.
 *
 * Every figure comes from the loan engine: the amount is what
 * RepaymentService::amountDueNow() says is due, handed in by the caller. The
 * renderer only formats it: "PHP 2,500.00" on SMS (a "₱" would make the
 * whole SMS Unicode and roughly double its parts) and "₱2,500.00" on email.
 * Dates read "Sep 15, 2026".
 *
 * The variables are the frontend editor's (`TEMPLATE_VARIABLES` in
 * src/lib/reminders.ts); the task's own names are aliases of them.
 */
final class ReminderRenderer
{
    public const VARIABLES = [
        'borrower_first_name', 'borrower_full_name', 'loan_number', 'installment_number', 'due_date',
        'amount_due', 'remaining_balance', 'days_overdue', 'lender_name', 'branch_name', 'branch_phone',
        'payment_method',
    ];

    public const ALIASES = [
        'remaining_amount' => 'remaining_balance',
        'company_name' => 'lender_name',
        'company_phone' => 'branch_phone',
        'payment_reference' => 'loan_number',
    ];

    private const PATTERN = '/\{\{\s*([a-z0-9_]+)\s*\}\}/i';

    /**
     * @var array<int, ?ReminderBranchSetting>
     */
    private array $branchSettings = [];

    private ?BrandingSetting $branding = null;

    private bool $brandingLoaded = false;

    /**
     * @return array<string, string>
     */
    public function variables(Loan $loan, int $periodNumber, CarbonInterface $dueDate, float $amountDue, string $channel, ReminderSetting $settings, ?CarbonInterface $today = null): array
    {
        $today = ReminderClock::today($today);
        $due = ReminderClock::manila($dueDate)->startOfDay();
        $member = $loan->borrower;
        $branch = $loan->branch;
        $override = $this->branchOverride($loan->branch_id === null ? null : (int) $loan->branch_id);
        $branding = $this->branding();
        $amount = self::money($amountDue, $channel);

        return [
            'borrower_first_name' => trim((string) $member?->first_name),
            'borrower_full_name' => trim((string) $member?->full_name),
            'loan_number' => (string) ($loan->loan_account_number ?: $loan->application_number),
            'installment_number' => (string) $periodNumber,
            'due_date' => self::date($due),
            'amount_due' => $amount,
            'remaining_balance' => $amount,
            'days_overdue' => (string) max(0, (int) $due->diffInDays($today, false)),
            'lender_name' => $this->firstFilled($override?->sender_name, $branding?->organization_name, config('app.name')),
            'branch_name' => trim((string) $branch?->name),
            'branch_phone' => $this->firstFilled($override?->contact_phone, $branch?->contact_number, $branding?->organization_contact),
            'payment_method' => $this->firstFilled($override?->payment_instructions, $settings->payment_instructions),
        ];
    }

    /**
     * @param  array<string, string>  $values  from variables()
     */
    public function render(?string $subject, string $body, array $values, string $channel): RenderedReminder
    {
        $unknown = array_values(array_unique([...self::unknownVariables((string) $subject), ...self::unknownVariables($body)]));

        $renderedSubject = $subject === null || trim($subject) === '' ? null : trim($this->fill($subject, $values));
        $renderedBody = $this->fill($body, $values);
        $renderedBody = $channel === 'email'
            ? trim((string) preg_replace("/\n{3,}/", "\n\n", (string) preg_replace("/[ \t]+\n/", "\n", $renderedBody)))
            : trim((string) preg_replace('/ {2,}/', ' ', $renderedBody));

        return new RenderedReminder($channel === 'email' ? $renderedSubject : null, $renderedBody, $unknown);
    }

    /**
     * `{{names}}` in `$text` that are neither a variable nor an alias.
     *
     * @return list<string>
     */
    public static function unknownVariables(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches);

        return array_values(array_unique(array_filter(
            $matches[1],
            fn (string $name) => self::canonical($name) === null,
        )));
    }

    public static function money(float $amount, string $channel): string
    {
        return ($channel === 'email' ? '₱' : 'PHP ').number_format($amount, 2);
    }

    public static function date(CarbonInterface $date): string
    {
        return $date->format('M j, Y');
    }

    private static function canonical(string $name): ?string
    {
        $name = strtolower($name);

        if (in_array($name, self::VARIABLES, true)) {
            return $name;
        }

        return self::ALIASES[$name] ?? null;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function fill(string $text, array $values): string
    {
        return (string) preg_replace_callback(self::PATTERN, function (array $match) use ($values): string {
            $name = self::canonical($match[1]);

            return $name === null ? $match[0] : ($values[$name] ?? '');
        }, $text);
    }

    private function firstFilled(mixed ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function branchOverride(?int $branchId): ?ReminderBranchSetting
    {
        if ($branchId === null) {
            return null;
        }

        if (! array_key_exists($branchId, $this->branchSettings)) {
            $this->branchSettings[$branchId] = ReminderBranchSetting::query()
                ->where('branch_id', $branchId)
                ->where('use_company_default', false)
                ->first();
        }

        return $this->branchSettings[$branchId];
    }

    private function branding(): ?BrandingSetting
    {
        if (! $this->brandingLoaded) {
            $this->branding = BrandingSetting::query()->orderBy('id')->first();
            $this->brandingLoaded = true;
        }

        return $this->branding;
    }
}
