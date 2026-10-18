<?php

return [
    'connection' => 'ips_catalog',
    'expected_database' => 'IPS5Db',
    'totals_cache_seconds' => (int) env('IPS_TOTALS_CACHE_SECONDS', 30),
    'totals_cache_store' => env('IPS_TOTALS_CACHE_STORE'),
    // Validate stored procedures in an IPS test database before enabling production writes.
    'writes_enabled' => (bool) env('IPS_WRITES_ENABLED', false),
    'user_provisioning_enabled' => (bool) env('IPS_USER_PROVISIONING_ENABLED', false),
    'user_pid' => env('IPS_USER_PID'),
    'workstation_pid' => env('IPS_WORKSTATION_PID'),
    'destination_country' => 'BO',
    'exchange_office_fcds' => ['BOSRZA', 'BOLPBA'],
    // IPS delivery-agent codes use regional prefixes; map each Bolivian office explicitly.
    'delivery_agent_region_by_office_fcd' => [
        'BOCBBA' => 'CBB',
        'BOLPBA' => 'LP',
        'BOLPBB' => 'LP',
        'BOLPBC' => 'LP',
        'BOLPBD' => 'LP',
        'BOLPBE' => 'LP',
        'BOSRZA' => 'SC',
        'BOSRZB' => 'SC',
        'BOSRZC' => 'SC',
        'BOSRZD' => 'SC',
        'BOTDDB' => 'BN',
        'BOCIJB' => 'PND',
        'BOORUB' => 'OR',
        'BOPOIB' => 'PT',
        'BOSREB' => 'CHU',
        'BOTJAB' => 'TJ',
    ],
    'operation_timezone' => env('IPS_OPERATION_TIMEZONE', 'America/La_Paz'),
    // IDs verified in this installation's C_EVENT_TYPES on 2026-09-10.
    'events' => [
        'EMA' => ['id' => 1, 'name' => 'Admisión del envío', 'create' => true, 'direction' => 'O'],
        'EMD' => ['id' => 30, 'name' => 'Recepción en oficina de intercambio', 'create' => true, 'direction' => 'I'],
        'EMR' => ['id' => 30, 'name' => 'Recibir en oficina de intercambio', 'create' => false, 'direction' => 'I'],
        'CUSTOMS_PRESENT' => ['id' => 31, 'name' => 'Enviar envío a Aduana', 'create' => false, 'direction' => 'I'],
        'CUSTOMS_INFO' => ['id' => 34, 'name' => 'Registrar información aduanera', 'create' => false, 'direction' => 'I'],
        'EDN' => ['id' => 35, 'name' => 'Despacho a una ubicación nacional', 'create' => false, 'direction' => 'I'],
        'EIB' => ['id' => 13, 'name' => 'Incorporación a saca nacional', 'create' => false, 'direction' => 'U'],
        'EMG' => ['id' => 32, 'name' => 'Recepción en oficina de entrega', 'create' => false, 'direction' => 'I'],
        'CUSTOMS_RETURN' => ['id' => 38, 'name' => 'Devolver envío desde Aduana', 'create' => false, 'direction' => 'I'],
        'HANDOVER_AGENT' => ['id' => 39, 'name' => 'Transferir envío al agente de entrega', 'create' => false, 'direction' => 'I'],
        'EDG' => ['id' => 74, 'name' => 'Salida a reparto', 'create' => false, 'direction' => 'I'],
        'EDH' => ['id' => 75, 'name' => 'Disponible para retiro', 'create' => false, 'direction' => 'I'],
        'EMH' => ['id' => 36, 'name' => 'Intento de entrega fallido', 'create' => false, 'direction' => 'I'],
        'EMI' => ['id' => 37, 'name' => 'Entregado al destinatario', 'create' => false, 'direction' => 'I'],
    ],
    'receptacle_events' => [
        'RECEPTACLE_RECEIVE' => ['id' => 130, 'name' => 'Recibir envase del extranjero', 'result_state' => 22, 'direction' => 'I'],
        'RECEPTACLE_OPEN' => ['id' => 131, 'name' => 'Abrir envase recibido', 'result_state' => 20, 'direction' => 'I'],
    ],
    // Local operational policy, not a universal UPU transition requirement.
    'delivery_candidate_events' => [13, 32, 39, 74, 75, 36, 38],
    // Distribution work queues, narrowed to the action IPS currently allows at each event/state.
    'delivery_action_candidates' => [
        'EDH' => ['events' => [32, 38], 'states' => [0]],
        'HANDOVER_AGENT' => ['events' => [32, 36, 38], 'states' => [0]],
        'EDG' => ['events' => [39], 'states' => [0]],
        'EMH' => ['events' => [74], 'states' => [0, 8]],
        'EMI' => ['events' => [32, 38, 74, 75], 'states' => [0, 8]],
    ],
    'exchange_receipt_candidate_events' => [1, 2, 3, 8, 14, 15],
    // Confirmación compuesta desde Almacén: devolución física de Aduana seguida de entrega.
    'customs_return_event' => ['id' => 38, 'direction' => 'I', 'result_state' => 0],
    // Receipt requires physical confirmation and office routing; 35 is not mandatory.
    'reception_candidate_events' => [13, 30, 33, 35, 38, 42, 43, 44, 71, 72],
];
