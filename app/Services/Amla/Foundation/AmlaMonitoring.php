<?php

namespace App\Services\Amla\Foundation;

/**
 * The server's AMLA monitoring switch (`AMLA_MONITORING_ENABLED`, read in
 * config/amla.php), off by default. The harvester and the rules (AMLA-B2,
 * B3) run only while it is on; it goes on for both staging APIs once B3
 * merges, and production stays off. Settings, the calendar and the log work
 * either way, behind their permissions.
 */
final class AmlaMonitoring
{
    public function enabled(): bool
    {
        return config('amla.monitoring_enabled') === true;
    }
}
