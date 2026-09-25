<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Tracking API cache
    |--------------------------------------------------------------------------
    |
    | fresh_ttl_seconds:
    |   tiempo durante el cual una respuesta se considera fresca.
    | stale_ttl_seconds:
    |   ventana adicional para poder reutilizar el ultimo resultado conocido
    |   si SQL Server esta lento o caido.
    | lock_seconds:
    |   evita que multiples requests del mismo codigo disparen la misma
    |   consulta pesada en paralelo.
    */
    'cache' => [
        'fresh_ttl_seconds' => (int) env('TRACKING_CACHE_TTL_SECONDS', 60),
        'stale_ttl_seconds' => (int) env('TRACKING_CACHE_STALE_TTL_SECONDS', 300),
        'lock_seconds' => (int) env('TRACKING_CACHE_LOCK_SECONDS', 15),
        'wait_milliseconds' => (int) env('TRACKING_CACHE_WAIT_MILLISECONDS', 1500),
        'wait_interval_milliseconds' => (int) env('TRACKING_CACHE_WAIT_INTERVAL_MILLISECONDS', 150),
    ],
    // Tracking is read-only and should use the same IPS source configured for
    // postal lookups. This avoids silently querying a local SQL Server while
    // CDS/IPS reads are configured against the shared catalog connection.
    'sqlserver' => [
        'connection' => env('TRACKING_SQLSERVER_CONNECTION', env('POSTAL_IPS_READ_CONNECTION', 'sqlsrv')),
    ],

    // MAILITM_LOCAL_ID is not formally a city field, but these exact values
    // are established destination-office aliases in the IPS data. Keep the
    // allowlist strict; free-form IDs must never be interpreted as a city.
    'local_id_destination_cities' => [
        'CBB' => 'Cochabamba',
        'CBBA' => 'Cochabamba',
        'SRZ' => 'Santa Cruz',
        'LPB' => 'La Paz',
        'SRE' => 'Sucre',
        'ORU' => 'Oruro',
        'TJA' => 'Tarija',
        'POI' => 'Potosi',
        'TDD' => 'Trinidad',
        'CIJ' => 'Cobija',
    ],

];
