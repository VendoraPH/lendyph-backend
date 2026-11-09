<?php

namespace Tests\Feature\Reminders\Api;

use App\Models\Branch;
use App\Models\Loan;
use App\Models\ReminderMessage;
use App\Models\ReminderQueueItem;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Reminders\BuildsReminderLoans;

/**
 * Staff with chosen permissions, loans in a branch the caller can't see, and
 * hand-built queue rows and messages for the reminders API tests.
 */
trait UsesReminderApi
{
    use BuildsReminderLoans;

    public const REMINDER_PERMISSIONS = ['reminders:view', 'reminders:send', 'reminders:pause', 'reminders:settings'];

    /**
     * @param  list<string>  $permissions
     */
    protected function staff(array $permissions, ?Branch $branch = null): User
    {
        $role = Role::create(['name' => 'rem_'.Str::lower(Str::random(10)), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->inBranches($branch ?? $this->branch)->create();
        $user->assignRole($role);

        return $user;
    }

    protected function farBranch(): Branch
    {
        return Branch::factory()->create(['name' => 'Far Branch '.Str::random(4), 'code' => 'F'.Str::upper(Str::random(5))]);
    }

    protected function farLoanDue(string $dueDate, ?Branch $far = null): Loan
    {
        $far ??= $this->farBranch();

        return $this->loanDue($dueDate, ['branch_id' => $far->id], [], ['branch_id' => $far->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function queueRow(Loan $loan, array $attributes = []): ReminderQueueItem
    {
        $period = $loan->amortizationSchedules->first();

        return ReminderQueueItem::query()->create(array_merge([
            'loan_id' => $loan->id,
            'borrower_id' => $loan->borrower_id,
            'branch_id' => $loan->branch_id,
            'amortization_schedule_id' => $period?->id,
            'period_number' => 1,
            'due_date' => $period?->due_date?->toDateString() ?? now()->toDateString(),
            'channel' => 'sms',
            'template_type' => 'due_today',
            'amount_due' => 5000,
            'fire_date' => now()->toDateString(),
            'scheduled_at' => now(),
            'status' => 'scheduled',
            'source' => 'system',
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function messageRow(ReminderQueueItem $item, array $attributes = []): ReminderMessage
    {
        $item->update(['status' => 'sent', 'recipient_masked' => '63917*****01']);

        return ReminderMessage::query()->create(array_merge([
            'queue_item_id' => $item->id,
            'loan_id' => $item->loan_id,
            'borrower_id' => $item->borrower_id,
            'rule_id' => $item->rule_id,
            'period_number' => $item->period_number,
            'channel' => $item->channel,
            'template_type' => $item->template_type,
            'subject' => $item->channel === 'email' ? 'Your payment is due today' : null,
            'body' => 'Hi Juan, PHP 5,000.00 is due today.',
            'recipient' => $item->channel === 'email' ? 'juan1@example.com' : '639171234501',
            'recipient_masked' => $item->channel === 'email' ? 'ju***@example.com' : '63917*****01',
            'provider' => 'log',
            'status' => 'sent',
            'attempts' => 1,
            'sms_parts' => $item->channel === 'sms' ? 1 : null,
            'test_mode' => true,
            'send_date' => $item->fire_date->toDateString(),
            'scheduled_at' => $item->scheduled_at,
            'sent_at' => now(),
            'source' => $item->source,
        ], $attributes));
    }
}
