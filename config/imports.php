<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CSV migration import — chunked upload
    |--------------------------------------------------------------------------
    |
    | Settings for the resumable upload that carries a cooperative's legacy
    | export into this system. Only the upload half lives here; the staging and
    | processing halves keep their own keys below when they need them.
    |
    */

    /**
     * Bytes per chunk, as advertised to the browser by POST /api/imports.
     *
     * The client reads whatever this endpoint returns, so this number — not a
     * literal in the frontend — is what decides the size of every part on the
     * wire. That is why it is configuration: the binding constraint is a chain
     * of proxy limits that can be raised or lowered on a box without a deploy.
     *
     * 512 KiB, and the reason is nginx rather than PHP. A browser posts to the
     * FRONTEND vhost, which proxies through Next to the API vhost. That
     * frontend vhost had no `client_max_body_size` and so inherited nginx's
     * 1 MiB default; the multipart framing around a chunk measures 410 bytes,
     * which put a 1 MiB chunk 410 bytes over the limit and 413'd it forever —
     * on every retry, since a retry is the same size. The vhosts now allow
     * 25M, but 512 KiB keeps a comfortable margin against the smallest limit in
     * the chain and keeps a single retry cheap on a Philippine mobile
     * connection.
     *
     * CsvImportUploadService clamps this between CHUNK_SIZE_FLOOR and
     * CHUNK_SIZE_CEILING; the ceiling is PHP's 12 MiB `upload_max_filesize`,
     * which multipart parts are measured against individually.
     *
     * A run freezes the value it was created with onto every one of its files,
     * so changing this never breaks an upload already in flight.
     */
    'chunk_size' => (int) env('CSV_IMPORT_CHUNK_SIZE', 512 * 1024),

    /**
     * Largest single CSV accepted, in bytes, checked at POST /api/imports
     * against the size the client declares — before a single chunk is stored,
     * rather than after 100 MiB of someone's members have already landed on
     * the private volume.
     *
     * 100 MiB is far above any real coop extract (the largest deployment's
     * whole loan book is a few MB) and low enough that a run cannot fill the
     * disk. Both files of a run may reach it, and assembly briefly holds a
     * second copy, so the worst case a run costs is roughly 4x this.
     */
    'max_file_bytes' => (int) env('CSV_IMPORT_MAX_FILE_BYTES', 100 * 1024 * 1024),

    /**
     * How long an `uploading` run may sit with no chunk arriving before the
     * next person trying to start an import may take its slot.
     *
     * The concurrency guard refuses a second run while one is open, which is
     * correct — two imports writing the same books with no coordination is
     * worse than waiting. But a browser that dies mid-upload leaves a run in
     * `uploading` with nothing left to advance it, and without this the
     * cooperative could never start an import again. That is a self-inflicted
     * outage triggered by precisely the flaky connections chunked upload exists
     * to survive.
     *
     * Six hours. Activity is measured from the newest chunk to arrive, not from
     * when the run was opened, so an upload that is slowly making progress over
     * a bad mobile link is never reclaimed however long it takes — only one
     * that has genuinely stopped. Nothing reclaims runs in the background: a
     * stale run is cleared at the moment somebody actually needs the slot, so
     * there is no scheduled job quietly cancelling an operator's work while
     * they are at lunch.
     *
     * Only `uploading` is ever reclaimed this way. A run wedged in `staging` or
     * `importing_*` has a process mid-write behind it and is not this
     * mechanism's business.
     */
    'stale_upload_after_minutes' => (int) env('CSV_IMPORT_STALE_UPLOAD_MINUTES', 360),

    /**
     * Root directory for chunks and assembled files, on the private disk.
     *
     * The disk itself is deliberately NOT configurable — see
     * CsvImportUploadService::DISK. These files are a whole cooperative's
     * membership register in plain text.
     */
    'path_prefix' => env('CSV_IMPORT_PATH_PREFIX', 'csv-imports'),

];
