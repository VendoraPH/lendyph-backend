<?php

namespace App\Services\Reminders\Api;

use App\Models\Branch;
use App\Models\ReminderBranchSetting;
use App\Models\ReminderSetting;
use App\Models\User;
use App\Services\BranchAccess;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The reminders settings as the frontend's `ReminderSettings` reads them, and
 * the save behind `PUT /reminders/settings`.
 *
 * Credentials are write-only: the answer says only whether they are set
 * (`has_credentials`), and a blank or absent key keeps the stored one. They
 * are encrypted by the model's casts, hidden from its JSON form and left out
 * of its audit rows. `test_mode` is the deployment's (DeploymentMode), read
 * only: a saved provider never changes a driver, so sending stays log-only.
 * `branches` lists the caller's own branches.
 */
final class ReminderSettingsService
{
    private const BOOLEAN_COLUMNS = ['enabled', 'sms_enabled', 'email_enabled'];

    private const NUMERIC_COLUMNS = ['max_attempts', 'retry_delay_minutes', 'sms_cost_per_part'];

    private const CREDENTIAL_COLUMNS = ['sms_api_key', 'sms_api_secret', 'email_api_key'];

    public function __construct(
        private readonly DeploymentMode $mode,
        private readonly BranchAccess $branches,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(User $user): array
    {
        $settings = ReminderSetting::current();

        return [
            'enabled' => (bool) $settings->enabled,
            'test_mode' => $this->mode->testMode(),
            'timezone' => ReminderSetting::TIMEZONE,
            'default_send_time' => ReminderFormat::clock($settings->default_send_time),
            'channels' => ['sms' => (bool) $settings->sms_enabled, 'email' => (bool) $settings->email_enabled],
            'contact_hours' => ['start' => ReminderFormat::clock($settings->contact_hours_start), 'end' => ReminderFormat::clock($settings->contact_hours_end)],
            'retry' => ['max_attempts' => $settings->maxAttempts(), 'delay_minutes' => $settings->retryDelayMinutes()],
            'sms_provider' => [
                'provider' => $settings->sms_provider,
                'sender_id' => (string) $settings->sms_sender_id,
                'has_credentials' => filled($settings->sms_api_key) || filled($settings->sms_api_secret),
                'cost_per_segment' => ReminderFormat::amount($settings->sms_cost_per_part),
            ],
            'email_provider' => [
                'provider' => $settings->email_provider,
                'sender_name' => (string) $settings->email_sender_name,
                'sender_email' => (string) $settings->email_sender_email,
                'reply_to' => $settings->email_reply_to,
                'has_credentials' => filled($settings->email_api_key),
            ],
            'payment_instructions' => (string) $settings->payment_instructions,
            'branches' => $this->branchRows($user),
        ];
    }

    /**
     * Saves the settings. Without `$mayChangeCompany` (the caller lacks
     * `branches:view_all`) no company column is ever written: only
     * `branches` is saved, and the save is refused (403) when any company
     * column it sends differs from the stored one or it carries any API key
     * or secret. The check runs under the settings row's lock, so a stale
     * whole-form save can't undo a change saved in the meantime.
     *
     * @param  array<string, mixed>  $data  validated UpdateReminderSettingsRequest input
     */
    public function update(User $user, array $data, bool $mayChangeCompany): void
    {
        DB::transaction(function () use ($user, $data, $mayChangeCompany): void {
            $settings = ReminderSetting::query()->orderBy('id')->lockForUpdate()->first() ?? ReminderSetting::current();

            if ($mayChangeCompany) {
                $settings->fill(array_merge($this->columns($data), ['updated_by' => $user->id]));
                $settings->save();
            } elseif ($this->companyChanges($data, $settings) !== []) {
                throw new HttpException(403, 'Only staff who see every branch can change the company-wide reminder settings.');
            }

            foreach ($data['branches'] ?? [] as $branch) {
                $row = ReminderBranchSetting::query()->firstOrNew(['branch_id' => (int) filter_var($branch['branch_id'], FILTER_VALIDATE_INT)]);
                $row->fill(array_merge(
                    Arr::only($branch, ['use_company_default', 'sender_name', 'contact_phone', 'contact_email', 'payment_instructions']),
                    ['updated_by' => $user->id],
                ));
                $row->save();
            }
        });
    }

    /**
     * The company columns this save would change, each compared by its own
     * type with the stored row: switches as booleans, counts and the SMS cost
     * as numbers, times and text exactly (an empty value and none are the
     * same). Any filled API key or secret counts as a change, whatever is
     * stored, so a save never tells whether a guess matched.
     *
     * @param  array<string, mixed>  $data  validated UpdateReminderSettingsRequest input
     * @return list<string>
     */
    private function companyChanges(array $data, ReminderSetting $settings): array
    {
        $changed = [];

        foreach ($this->columns($data) as $column => $value) {
            if (in_array($column, self::CREDENTIAL_COLUMNS, true) || ! self::same($column, $settings->getAttribute($column), $value)) {
                $changed[] = $column;
            }
        }

        return $changed;
    }

    private static function same(string $column, mixed $stored, mixed $sent): bool
    {
        if (in_array($column, self::BOOLEAN_COLUMNS, true)) {
            return filter_var($stored, FILTER_VALIDATE_BOOL) === filter_var($sent, FILTER_VALIDATE_BOOL);
        }

        if (in_array($column, self::NUMERIC_COLUMNS, true)) {
            return ($stored === null && $sent === null)
                || ($stored !== null && $sent !== null && is_numeric($stored) && is_numeric($sent) && round((float) $stored, 4) === round((float) $sent, 4));
        }

        return (string) $stored === (string) $sent;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        $map = [
            'enabled' => 'enabled',
            'default_send_time' => 'default_send_time',
            'channels.sms' => 'sms_enabled',
            'channels.email' => 'email_enabled',
            'contact_hours.start' => 'contact_hours_start',
            'contact_hours.end' => 'contact_hours_end',
            'retry.max_attempts' => 'max_attempts',
            'retry.delay_minutes' => 'retry_delay_minutes',
            'payment_instructions' => 'payment_instructions',
            'sms_provider.provider' => 'sms_provider',
            'sms_provider.sender_id' => 'sms_sender_id',
            'sms_provider.cost_per_segment' => 'sms_cost_per_part',
            'email_provider.provider' => 'email_provider',
            'email_provider.sender_name' => 'email_sender_name',
            'email_provider.sender_email' => 'email_sender_email',
            'email_provider.reply_to' => 'email_reply_to',
        ];

        $columns = [];

        foreach ($map as $key => $column) {
            if (Arr::has($data, $key)) {
                $value = Arr::get($data, $key);
                $columns[$column] = in_array($column, ['default_send_time', 'contact_hours_start', 'contact_hours_end'], true) && $value !== null
                    ? $value.':00'
                    : $value;
            }
        }

        // Write-only: only a filled key replaces the stored one.
        foreach (['sms_provider.api_key' => 'sms_api_key', 'sms_provider.api_secret' => 'sms_api_secret', 'email_provider.api_key' => 'email_api_key'] as $key => $column) {
            if (filled(Arr::get($data, $key))) {
                $columns[$column] = Arr::get($data, $key);
            }
        }

        return $columns;
    }

    /**
     * @return list<array{branch_id: int, branch_name: string, use_company_default: bool, sender_name: ?string, contact_phone: ?string, contact_email: ?string}>
     */
    private function branchRows(User $user): array
    {
        $visible = $this->branches->for($user);
        $overrides = ReminderBranchSetting::query()->get()->keyBy('branch_id');

        return Branch::query()
            ->when($visible !== null, fn ($query) => $query->whereIn('id', $visible))
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $overrides->keys()))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function (Branch $branch) use ($overrides): array {
                $row = $overrides->get($branch->id);

                return [
                    'branch_id' => (int) $branch->id,
                    'branch_name' => (string) $branch->name,
                    'use_company_default' => $row === null ? true : (bool) $row->use_company_default,
                    'sender_name' => $row?->sender_name,
                    'contact_phone' => $row?->contact_phone,
                    'contact_email' => $row?->contact_email,
                ];
            })
            ->values()
            ->all();
    }
}
