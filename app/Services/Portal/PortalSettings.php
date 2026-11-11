<?php

namespace App\Services\Portal;

use App\Models\PortalSetting;
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

    public function __construct(
        private readonly DeploymentMode $deployment,
        private readonly DriverResolver $drivers,
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
     * Every feature key with its switch; a key the stored row lacks is on.
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

    public function featureOn(string $key): bool
    {
        return $this->features()[$key] ?? false;
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
