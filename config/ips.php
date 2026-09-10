<?php

return [
    'connection' => 'sqlsrv',
    'expected_database' => 'IPS5Db',
    // Validate stored procedures in an IPS test database before enabling production writes.
    'writes_enabled' => (bool) env('IPS_WRITES_ENABLED', false),
    'user_pid' => env('IPS_USER_PID'),
    'workstation_pid' => env('IPS_WORKSTATION_PID'),
    'destination_country' => 'BO',
    // IDs verified in this installation's C_EVENT_TYPES on 2026-09-10.
    'events' => [
        'EMA' => ['id' => 1, 'name' => 'Admisión del envío', 'create' => true, 'direction' => 'O'],
        'EMD' => ['id' => 30, 'name' => 'Recepción en oficina de intercambio', 'create' => true, 'direction' => 'I'],
        'EMG' => ['id' => 32, 'name' => 'Recepción en oficina de entrega', 'create' => false, 'direction' => 'I'],
        'EDG' => ['id' => 74, 'name' => 'Salida a reparto', 'create' => false, 'direction' => 'I'],
        'EDH' => ['id' => 75, 'name' => 'Disponible para retiro', 'create' => false, 'direction' => 'I'],
        'EMH' => ['id' => 36, 'name' => 'Intento de entrega fallido', 'create' => false, 'direction' => 'I'],
        'EMI' => ['id' => 37, 'name' => 'Entregado al destinatario', 'create' => false, 'direction' => 'I'],
    ],
    // Local operational policy, not a universal UPU transition requirement.
    'delivery_candidate_events' => [32, 39, 74, 75, 36],
];
