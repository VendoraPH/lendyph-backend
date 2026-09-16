<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Restricted Diagnostics
    |--------------------------------------------------------------------------
    |
    | Whether UNREDACTED exception detail may be written to the `csv-import`
    | channel below. Off, and it must stay off on any deployment holding real
    | member data.
    |
    | A Laravel QueryException's message is the failing SQL with the bindings
    | substituted in, so one row-level database error carries the member's whole
    | record — name, birthdate, address, contact number, income. A systemic
    | fault (lock wait, deadlock, a poisoned code sequence) produces one such
    | line per member. See App\Services\Diagnostics\ErrorDigest.
    |
    | NOT ONLY THE IMPORTER any more. The borrower bulk endpoints write here
    | too, because `Auditable` copies a borrower's full attributes into
    | `audit_logs.old_values` and a failure of THAT insert is the same
    | disclosure by a different route. So does `registrations:prune`, for a
    | different reason worth stating precisely: it suppresses the Auditable
    | hooks and is still not safe, because it makes a DIRECT
    | AuditLogService::log() call that no `audit: false` touches, and because
    | its own delete path quotes the member's external account number.
    |
    | ONE CHANNEL, ONE FLAG PER WRITER — and both halves of that are
    | deliberate, because the two names fail in opposite directions:
    |
    |  - The CHANNEL keeps its importer spelling. Renaming it is a
    |    deployment-coordinated change rather than a refactor: it is configured
    |    on ten separate boxes, and a rename that silently switches off a sink
    |    somebody enabled for a live incident is the one failure mode a
    |    diagnostics switch must not have. It is a misnomer on purpose.
    |  - The FLAG is per writer, because REUSING one fails the same way round
    |    the other. A box where an operator set the import flag to chase an
    |    import incident must not thereby arm a second, unrelated writer — an
    |    admin-triggered borrower endpoint capturing whole member records
    |    nobody asked about. "Only to diagnose a specific incident", below,
    |    means nothing unless the switch is as specific as the incident.
    |
    | Callers may pass their own channel and their own flag; see
    | ErrorDigest::recordDiagnostics().
    |
    | Turn either on only to diagnose a specific incident, on a box with test
    | data or with the operator's informed consent, and turn it off afterwards.
    | Ordinary logging never needs them: it records the exception class and the
    | driver's numeric error code, which is enough to tell a duplicate key from
    | a lock-wait timeout from a restricted delete.
    |
    */

    // The CSV importer. See App\Services\CsvImport\ImportErrorDigest.
    'csv_import_diagnostics' => env('LOG_CSV_IMPORT_DIAGNOSTICS', false),

    /*
     * Everything that writes a BORROWER'S OWN ROW: the bulk deactivate/delete
     * endpoints and registrations:prune.
     *
     * New, and therefore set nowhere. That is the point of it being new: it
     * starts off on every deployment, including any that is already running
     * with the import flag turned on.
     */
    'borrower_diagnostics' => env('LOG_BORROWER_DIAGNOSTICS', false),

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', env('APP_NAME', 'Laravel')),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        /*
         * The restricted diagnostic channel — see the flags above, one per
         * writer, which gate whether anything is ever written here, and which
         * explain why this channel still carries the importer's name now that
         * the borrower paths share the file. Lines are tagged with a `source`
         * so they stay attributable.
         *
         * Everything about it is the opposite of `single`, deliberately:
         *
         *  - ITS OWN FILE, so member data cannot end up interleaved with the
         *    log every other part of the app writes to and everyone tails.
         *  - 0600 rather than 644. `single` is world-readable, which is
         *    tolerable for ordinary application logging and is not tolerable
         *    for a file that may contain a membership register.
         *  - DAILY WITH RETENTION. `single` is one file that never rotates, so
         *    anything written into it is there until somebody deletes it by
         *    hand. These expire.
         *
         * The mode applies to files this channel CREATES. A file already
         * present keeps its own permissions, so if this is ever enabled on a
         * box where the file exists, check it.
         */
        'csv-import' => [
            'driver' => 'daily',
            'path' => storage_path('logs/csv-import.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_CSV_IMPORT_DAYS', 7),
            'permission' => 0600,
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
