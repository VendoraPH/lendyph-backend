<?php

namespace App\Services\Portal;

use App\Models\PortalSetting;
use App\Services\MenuVisibility\MenuVisibility;
use App\Services\Reminders\Api\DeploymentMode;
use App\Services\Reminders\Drivers\DriverResolver;

/**
 * The portal's company-wide settings (portal design §1, §2), read through one
 * place so every limit has a default.
 */
final class PortalSettings
{
    /** The longest a password may be; the shortest is `password_min_length`. */
    public const PASSWORD_MAX_LENGTH = 128;

    public const OTP_BLOCKED = 'One-time passwords can\'t be turned on while text messages are only logged or reach nobody. Members would be unable to finish signing in.';

    /**
     * The portal's parts that belong to a side-menu module (finish run round
     * 6): while the module is hidden in Menu Visibility, members get none of
     * them. The stored switches never change, so showing the module again
     * brings back exactly what Settings → Member Portal had on.
     *
     * @var array<string, list<string>>
     */
    public const MODULE_FEATURES = [
        'savings' => ['savings', 'time_deposits', 'account_requests', 'withdrawal_requests'],
    ];

    public function __construct(
        private readonly DeploymentMode $deployment,
        private readonly DriverResolver $drivers,
        private readonly MenuVisibility $visibility,
    ) {}

    public function current(): PortalSetting
    {
        return PortalSetting::query()->orderBy('id')->first()
            ?? PortalSetting::query()->create([
                'enabled' => false,
                'features' => array_fill_keys(PortalSetting::FEATURES, true),
                'dashboard_cards' => PortalSetting::DASHBOARD_CARDS,
                'withdrawal_methods' => PortalSetting::WITHDRAWAL_METHODS,
            ])->refresh();
    }

    public function enabled(): bool
    {
        return $this->current()->enabled;
    }

    /**
     * Every feature key with its switch as stored; a key the stored row lacks
     * is on. What Settings → Member Portal shows and saves back; what members
     * get is memberFeatures().
     *
     * @return array<string, bool>
     */
    public function features(?PortalSetting $settings = null): array
    {
        $stored = ($settings ?? $this->current())->features ?? [];
        $features = [];

        foreach (PortalSetting::FEATURES as $key) {
            $features[$key] = (bool) ($stored[$key] ?? true);
        }

        return $features;
    }

    /**
     * The switches members get: the stored ones, with the parts of a module
     * hidden in Menu Visibility off (self::MODULE_FEATURES).
     *
     * @return array<string, bool>
     */
    public function memberFeatures(?PortalSetting $settings = null): array
    {
        $features = $this->features($settings);

        foreach (self::MODULE_FEATURES as $module => $parts) {
            if ($this->moduleHidden($module)) {
                foreach ($parts as $part) {
                    $features[$part] = false;
                }
            }
        }

        return $features;
    }

    /** Whether members get this part of the portal now (memberFeatures()). */
    public function featureOn(string $key): bool
    {
        return $this->memberFeatures()[$key] ?? false;
    }

    /** Whether a side-menu module is hidden in Menu Visibility, its submenus with their menu. */
    public function moduleHidden(string $module): bool
    {
        return in_array($module, $this->visibility->hidden(), true);
    }

    /**
     * Why OTP can't be switched on now, or null when it can: never while the
     * reminder drivers are in test mode or the SMS driver reaches nobody, so a
     * log-only driver never locks members out (portal design §1).
     */
    public function otpBlockedReason(): ?string
    {
        if ($this->deployment->testMode() || $this->drivers->for('sms')->reachesNobody()) {
            return self::OTP_BLOCKED;
        }

        return null;
    }
}
