<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The reminders defaults (task 86d4ayucn, §5 and §14–§19): one settings row,
 * switched off; the five default rules; and a template for each of the six
 * types on SMS and on email, in English.
 *
 * Safe to run twice: the settings row and the rules are written only when
 * their table is empty, and a template only when its type, channel and
 * language have none. Every row is new in a new table, so no existing data
 * changes and no audit row is due.
 *
 * SMS templates carry no "₱": amounts render as "PHP 2,500.00" on SMS, since
 * one "₱" switches the whole message to Unicode and roughly doubles its parts.
 * Email amounts render as "₱2,500.00".
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (! DB::table('reminder_settings')->exists()) {
            DB::table('reminder_settings')->insert([
                'enabled' => false,
                'timezone' => 'Asia/Manila',
                'default_send_time' => '09:00:00',
                'sms_enabled' => true,
                'email_enabled' => true,
                'contact_hours_start' => '08:00:00',
                'contact_hours_end' => '18:00:00',
                'max_attempts' => 3,
                'retry_delay_minutes' => 15,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! DB::table('reminder_rules')->exists()) {
            $both = json_encode(['sms', 'email']);
            DB::table('reminder_rules')->insert(array_map(fn (array $rule) => $rule + [
                'channels' => $both,
                'borrower_type' => 'all',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], [
                ['name' => 'Upcoming due', 'trigger' => 'before_due', 'days' => 3, 'send_time' => '09:00:00', 'template_type' => 'upcoming'],
                ['name' => 'Due today', 'trigger' => 'on_due', 'days' => 0, 'send_time' => '08:00:00', 'template_type' => 'due_today'],
                ['name' => '3 days overdue', 'trigger' => 'after_due', 'days' => 3, 'send_time' => '09:00:00', 'template_type' => 'past_due'],
                ['name' => '7 days overdue', 'trigger' => 'after_due', 'days' => 7, 'send_time' => '09:00:00', 'template_type' => 'past_due'],
                ['name' => '15 days overdue', 'trigger' => 'after_due', 'days' => 15, 'send_time' => '09:00:00', 'template_type' => 'long_past_due'],
            ]));
        }

        foreach ($this->templates() as $template) {
            $exists = DB::table('reminder_templates')
                ->where('type', $template['type'])
                ->where('channel', $template['channel'])
                ->where('language', 'en')
                ->exists();

            if (! $exists) {
                DB::table('reminder_templates')->insert($template + [
                    'language' => 'en',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Seeded rows live and die with their tables (the create migration's down()).
    }

    /**
     * @return list<array{name: string, type: string, channel: string, subject: ?string, body: string}>
     */
    private function templates(): array
    {
        $closing = "If your payment has already been processed, please disregard this reminder.\n\nFor questions about your account, please contact {{lender_name}}.\n\nThank you.";

        return [
            ['name' => 'Upcoming due (SMS)', 'type' => 'upcoming', 'channel' => 'sms', 'subject' => null,
                'body' => 'Hi {{borrower_first_name}}, this is a friendly reminder that your {{amount_due}} payment for loan {{loan_number}} is due on {{due_date}}. Thank you. {{lender_name}}'],
            ['name' => 'Due today (SMS)', 'type' => 'due_today', 'channel' => 'sms', 'subject' => null,
                'body' => 'Hi {{borrower_first_name}}, your {{amount_due}} payment for loan {{loan_number}} is due today, {{due_date}}. Please disregard this message if your payment has already been processed. Thank you. {{lender_name}}'],
            ['name' => 'Past due (SMS)', 'type' => 'past_due', 'channel' => 'sms', 'subject' => null,
                'body' => 'Hi {{borrower_first_name}}, our records show that your {{amount_due}} payment for loan {{loan_number}} was due on {{due_date}}. Please contact {{lender_name}} if you have already paid or need help with your account.'],
            ['name' => 'Long past due (SMS)', 'type' => 'long_past_due', 'channel' => 'sms', 'subject' => null,
                'body' => 'Hi {{borrower_first_name}}, your {{amount_due}} payment for loan {{loan_number}} has been unpaid since {{due_date}}. Please contact {{lender_name}} so we can help with your account. Please disregard this message if you have already paid.'],
            ['name' => 'Partial payment (SMS)', 'type' => 'partial_payment', 'channel' => 'sms', 'subject' => null,
                'body' => 'Hi {{borrower_first_name}}, thank you for your payment. Your remaining payment of {{remaining_balance}} for loan {{loan_number}} is due on {{due_date}}. Thank you. {{lender_name}}'],
            ['name' => 'Payment confirmation (SMS)', 'type' => 'payment_confirmation', 'channel' => 'sms', 'subject' => null,
                'body' => 'Hi {{borrower_first_name}}, we have received your payment for loan {{loan_number}}. Thank you. {{lender_name}}'],
            ['name' => 'Upcoming due (email)', 'type' => 'upcoming', 'channel' => 'email', 'subject' => 'Payment reminder for loan {{loan_number}}',
                'body' => "Hi {{borrower_first_name}},\n\nThis is a friendly reminder about your upcoming loan payment.\n\nLoan Number: {{loan_number}}\nAmount Due: {{amount_due}}\nDue Date: {{due_date}}\n\n{{payment_method}}\n\n{$closing}\n{{lender_name}}"],
            ['name' => 'Due today (email)', 'type' => 'due_today', 'channel' => 'email', 'subject' => 'Payment due today for loan {{loan_number}}',
                'body' => "Hi {{borrower_first_name}},\n\nYour loan payment is due today.\n\nLoan Number: {{loan_number}}\nAmount Due: {{amount_due}}\nDue Date: {{due_date}}\n\n{{payment_method}}\n\n{$closing}\n{{lender_name}}"],
            ['name' => 'Past due (email)', 'type' => 'past_due', 'channel' => 'email', 'subject' => 'Payment past due for loan {{loan_number}}',
                'body' => "Hi {{borrower_first_name}},\n\nOur records show that your loan payment was due on {{due_date}}.\n\nLoan Number: {{loan_number}}\nAmount Due: {{amount_due}}\nDue Date: {{due_date}}\n\n{{payment_method}}\n\n{$closing}\n{{lender_name}}"],
            ['name' => 'Long past due (email)', 'type' => 'long_past_due', 'channel' => 'email', 'subject' => 'Your account for loan {{loan_number}}',
                'body' => "Hi {{borrower_first_name}},\n\nYour loan payment has been unpaid since {{due_date}}. Please contact us so we can help with your account.\n\nLoan Number: {{loan_number}}\nAmount Due: {{amount_due}}\nDue Date: {{due_date}}\n\n{{payment_method}}\n\n{$closing}\n{{lender_name}}"],
            ['name' => 'Partial payment (email)', 'type' => 'partial_payment', 'channel' => 'email', 'subject' => 'Remaining payment for loan {{loan_number}}',
                'body' => "Hi {{borrower_first_name}},\n\nThank you for your payment. The remaining amount for this installment is still due.\n\nLoan Number: {{loan_number}}\nRemaining Due: {{remaining_balance}}\nDue Date: {{due_date}}\n\n{{payment_method}}\n\n{$closing}\n{{lender_name}}"],
            ['name' => 'Payment confirmation (email)', 'type' => 'payment_confirmation', 'channel' => 'email', 'subject' => 'Payment received for loan {{loan_number}}',
                'body' => "Hi {{borrower_first_name}},\n\nWe have received your payment for loan {{loan_number}}.\n\nFor questions about your account, please contact {{lender_name}}.\n\nThank you.\n{{lender_name}}"],
        ];
    }
};
