<?php

return [
    'cds_enabled' => (bool) env('CDS_ENABLED', false),
    'cds_connection' => 'cds',
    'ips_read_connection' => env('POSTAL_IPS_READ_CONNECTION', 'sqlsrv'),
    'timezone' => 'America/La_Paz',
    'operator_code' => 'AGBC',
    'record_limit' => 100,
    'report_max_days' => 31,
    'stale_after_days' => (int) env('POSTAL_STALE_AFTER_DAYS', 7),
];
