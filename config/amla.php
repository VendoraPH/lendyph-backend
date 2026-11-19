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
];
