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
];
