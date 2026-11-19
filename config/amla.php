<?php

/*
|--------------------------------------------------------------------------
| AMLA compliance (Phase 6)
|--------------------------------------------------------------------------
|
| `monitoring_enabled` is the server's monitoring switch: while it is off
| (the default), no AMLA event is harvested and no rule runs. It goes on for
| the staging APIs only, through a switch PR; production stays off. The
| thresholds, deadlines and calendar are not here: they are versioned in the
| database and change only through an approved AMLA change request.
*/

return [
    'monitoring_enabled' => filter_var(env('AMLA_MONITORING_ENABLED', false), FILTER_VALIDATE_BOOL),

    // Failed rows the harvester tries again per source per run, least
    // recently tried first.
    'harvest_retries_per_run' => 200,

    // The most AMLA log entries one audit history report holds; a longer
    // period is refused with "narrow the dates", never cut short.
    'audit_report_max_rows' => 5000,
];
