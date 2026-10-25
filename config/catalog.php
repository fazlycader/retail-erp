<?php

return [
    'import' => [
        // Files up to this size are checked and imported straight away; bigger ones go to the
        // queue in chunks (cron runs queue:work --stop-when-empty on shared hosting).
        'inline_max_bytes' => (int) env('CATALOG_IMPORT_INLINE_BYTES', 200 * 1024),
        'chunk_rows' => 250,
        'max_rows' => 20000,
        'max_kilobytes' => 10240,
    ],
];
