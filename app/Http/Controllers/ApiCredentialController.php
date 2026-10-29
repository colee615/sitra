<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ApiCredentialController extends Controller
{
    private const ABILITIES = [
        'sqlserver.read' => 'Consultar tracking SQL Server',
        'cds.read' => 'Consultar declaraciones CDS',
        'ips.read' => 'Consultar paquetes IPS',
        'ips.create' => 'Crear paquetes IPS',
        'ips.write' => 'Crear usuarios IPS',
        'ips.events' => 'Registrar eventos IPS',
        'ips.deliver' => 'Registrar entregas IPS',
        'ips.operations' => 'Consultar operaciones IPS',
        'customs.read' => 'Consultar expedientes aduaneros IPS/CDS',
        'customs.prealerts.read' => 'Consultar prealertas PREDES entrantes',
    ];

    public function index(Request $request)
    {
        $tokens = User::role('admin')
            ->with(['tokens' => fn ($query) => $query->latest()])
            ->get(['id', 'name'])
            ->flatMap(function (User $owner) {
                return $owner->tokens->map(function ($token) use ($owner) {
                    $token->owner_name = $owner->name;
                    $token->plain_text_token = $this->decryptTokenSecret($token->secret_encrypted);

                    return $token;
                });
            })
            ->sortByDesc('created_at')
            ->values();

        return view('api-credentials.index', [
            'apis' => $this->apiCatalog(),
            'tokens' => $tokens,
            'abilities' => self::ABILITIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'ability' => ['required', 'string', Rule::in(array_keys(self::ABILITIES))],
            'expires_in' => ['required', Rule::in(['30', '90', '365', 'never'])],
        ]);

        $expiresAt = $validated['expires_in'] === 'never'
            ? null
            : now()->addDays((int) $validated['expires_in']);
        $token = DB::transaction(function () use ($request, $validated, $expiresAt) {
            $token = $request->user()->createToken(
                trim($validated['name']),
                [$validated['ability']],
                $expiresAt,
            );
            $token->accessToken->forceFill([
                'secret_encrypted' => Crypt::encryptString($token->plainTextToken),
            ])->save();

            return $token;
        });

        return redirect()->route('api-credentials.index')
            ->with('issued_token_id', $token->accessToken->getKey())
            ->with('success', 'Credencial creada. Su token queda cifrado y visible solo para administradores.');
    }

    public function revoke(Request $request, int $token): RedirectResponse
    {
        $this->findAdminToken($token)->delete();

        return redirect()->route('api-credentials.index')
            ->with('success', 'La credencial fue revocada.');
    }

    public function grantCdsRead(Request $request, int $token): RedirectResponse
    {
        $credential = $this->findAdminToken($token);
        $abilities = $credential->abilities ?? [];

        if (! in_array('cds.read', $abilities, true)) {
            $abilities[] = 'cds.read';
            $credential->forceFill(['abilities' => array_values(array_unique($abilities))])->save();
        }

        return redirect()->route('api-credentials.index')
            ->with('success', 'Se autorizó esta credencial para consultar declaraciones CDS.');
    }

    public function rotate(Request $request, int $token): RedirectResponse
    {
        $oldToken = $this->findAdminToken($token);
        $owner = $oldToken->tokenable;
        $expiresAt = $oldToken->expires_at;

        if ($expiresAt !== null && $expiresAt->isPast()) {
            $expiresAt = now()->addDays(90);
        }

        $newToken = DB::transaction(function () use ($owner, $oldToken, $expiresAt) {
            $token = $owner->createToken(
                $oldToken->name,
                $oldToken->abilities ?? [],
                $expiresAt,
            );
            $token->accessToken->forceFill([
                'secret_encrypted' => Crypt::encryptString($token->plainTextToken),
            ])->save();
            $oldToken->delete();

            return $token;
        });

        return redirect()->route('api-credentials.index')
            ->with('issued_token_id', $newToken->accessToken->getKey())
            ->with('success', 'Token rotado. El anterior fue revocado y el nuevo queda cifrado para consulta administrativa.');
    }

    public function manual(): BinaryFileResponse
    {
        return response()->download(
            base_path('docs/IPS_OPERACIONES.md'),
            'manual-apis-sitra.md',
            ['Content-Type' => 'text/markdown; charset=UTF-8'],
        );
    }

    public function endpointConsumerDemo(Request $request)
    {
        $method = strtoupper((string) $request->query('method', ''));
        $path = (string) $request->query('path', '');
        $api = collect($this->apiCatalog())->first(fn (array $entry) =>
            $entry['method'] === $method && $entry['path'] === $path
        );

        abort_unless($api, 404);

        $api['consumer'] = $this->consumerContract($method, $path);
        abort_unless($api['consumer'] !== null, 404);
        $api['consumer'] += [
            'write' => false,
            'identifier' => null,
            'query' => [],
            'body' => [],
        ];
        $api['consumer_view'] = $api['consumer']['domain'];

        return view('api-credentials.endpoint-consumer-demo', [
            'api' => $api,
            'endpointUrl' => url($api['path']),
        ]);
    }

    private function apiCatalog(): array
    {
        return [
            [
                'group' => 'Tracking y CDS',
                'method' => 'GET',
                'path' => '/api/tracking/eventos',
                'description' => 'Consulta eventos de tracking por código.',
                'ability' => 'sqlserver.read',
            ],
            [
                'group' => 'Tracking y CDS',
                'method' => 'POST',
                'path' => '/api/tracking/eventos/batch',
                'description' => 'Consulta eventos de varios envíos.',
                'ability' => 'sqlserver.read',
            ],
            [
                'group' => 'Tracking y CDS',
                'method' => 'POST',
                'path' => '/api/tracking/destinos/batch',
                'description' => 'Consulta destinos para varios envíos.',
                'ability' => 'sqlserver.read',
            ],
            [
                'group' => 'Tracking y CDS',
                'method' => 'GET',
                'path' => '/api/tracking/eventos-todos',
                'description' => 'Consulta el historial de eventos.',
                'ability' => 'sqlserver.read',
            ],
            [
                'group' => 'Tracking y CDS',
                'method' => 'GET',
                'path' => '/api/tracking/paquetes',
                'description' => 'Lista paquetes; para incluir declaraciones CDS también requiere cds.read.',
                'ability' => 'sqlserver.read',
            ],
            [
                'group' => 'Tracking y CDS',
                'method' => 'GET',
                'path' => '/api/tracking/cds/declaraciones',
                'description' => 'Lista todos los paquetes CDS (50 por página; max 100); codes[]=CODIGO filtra. También acepta POST para lotes.',
                'ability' => 'cds.read',
            ],
            [
                'group' => 'Tracking y CDS',
                'method' => 'POST',
                'path' => '/api/tracking/cds/declaraciones',
                'description' => 'Consulta declaraciones CDS para una lista de códigos enviada en el cuerpo JSON codes[].',
                'ability' => 'cds.read',
            ],
            [
                'group' => 'Aduana',
                'method' => 'GET',
                'path' => '/api/v1/postal/prealertas',
                'description' => 'Lista manifiestos PREDES S8 dirigidos a Bolivia con sacas aún sin recepción IPS registrada. Devuelve origen, destino y resumen de sacas, paquetes, peso y recepción; excluye los despachos con todas sus sacas recibidas.',
                'ability' => 'customs.prealerts.read',
            ],
            [
                'group' => 'Aduana',
                'method' => 'GET',
                'path' => '/api/v1/postal/prealertas/{codigo}',
                'description' => 'Devuelve el manifiesto PREDES en estructura S8 → S9 → S10 y los datos CDS que se puedan enlazar. Incluye transporte y recepción física IPS por saca si existe; indica si la consulta CDS quedó incompleta.',
                'ability' => 'customs.prealerts.read',
            ],
            [
                'group' => 'Aduana',
                'method' => 'GET',
                'path' => '/api/v1/postal/despachos/{codigo}',
                'description' => 'Muestra el despacho S8 registrado en IPS: origen, destino, salida, peso y cantidades de sacas declaradas y vinculadas. No es el preaviso PREDES ni confirma recepción física.',
                'ability' => 'customs.read',
            ],
            [
                'group' => 'Aduana',
                'method' => 'GET',
                'path' => '/api/v1/postal/sacas/{codigo}',
                'description' => 'Busca una saca S9 o un número de registro/seguro único. Devuelve peso, conteos, despacho, códigos S10, cantidad de movimientos y resumen CDS; no incluye las fichas completas de los paquetes.',
                'ability' => 'customs.read',
            ],
            [
                'group' => 'Aduana',
                'method' => 'GET',
                'path' => '/api/v1/postal/paquetes/{codigo}',
                'description' => 'Busca un S10 o código local y muestra su ficha postal, último movimiento IPS, conteos y códigos relacionados de sacas, despachos y declaraciones CDS. No incluye el historial completo ni el contenido de las declaraciones.',
                'ability' => 'customs.read',
            ],
            [
                'group' => 'Aduana',
                'method' => 'GET',
                'path' => '/api/v1/postal/declaraciones/{codigo}',
                'description' => 'Busca por código postal S10 o código local y devuelve los datos del paquete, sus declaraciones y respuestas CDS disponibles, incluidos campos, artículos, documentos y eventos asociados.',
                'ability' => 'customs.read',
            ],
            [
                'group' => 'Aduana',
                'method' => 'GET',
                'path' => '/api/v1/postal/consulta/{codigo}',
                'description' => 'Arma una ficha según el código: S8 busca el despacho operativo IPS, no el PREDES; S9 muestra la saca, paquetes y CDS disponibles; S10 o código local muestra el paquete y sus datos CDS. Para carga entrante, consulta prealertas PREDES.',
                'ability' => 'customs.read',
            ],
            [
                'group' => 'IPS',
                'method' => 'GET',
                'path' => '/api/v1/ips/catalogos',
                'description' => 'Consulta catálogos IPS.',
                'ability' => 'ips.read',
            ],
            [
                'group' => 'IPS',
                'method' => 'GET',
                'path' => '/api/v1/ips/catalogos/entrega',
                'description' => 'Consulta catálogos disponibles para entrega.',
                'ability' => 'ips.read',
            ],
            [
                'group' => 'IPS',
                'method' => 'GET',
                'path' => '/api/v1/ips/usuarios',
                'description' => 'Lista usuarios IPS.',
                'ability' => 'ips.read',
            ],
            [
                'group' => 'IPS',
                'method' => 'GET',
                'path' => '/api/v1/ips/usuarios/{pid}',
                'description' => 'Consulta un usuario IPS.',
                'ability' => 'ips.read',
            ],
            [
                'group' => 'IPS',
                'method' => 'GET',
                'path' => '/api/v1/ips/paquetes',
                'description' => 'Lista paquetes IPS.',
                'ability' => 'ips.read',
            ],
            [
                'group' => 'IPS',
                'method' => 'GET',
                'path' => '/api/v1/ips/paquetes/pendientes-entrega',
                'description' => 'Lista paquetes pendientes de entrega.',
                'ability' => 'ips.read',
            ],
            [
                'group' => 'IPS',
                'method' => 'GET',
                'path' => '/api/v1/ips/envases-pendientes-apertura',
                'description' => 'Lista sacas recibidas y cerradas, pendientes de apertura en la oficina indicada por office_cd. Acepta page y per_page.',
                'ability' => 'ips.read',
            ],
            [
                'group' => 'IPS',
                'method' => 'GET',
                'path' => '/api/v1/ips/envases-pendientes-recepcion',
                'description' => 'Lista sacas PREDES dirigidas a la oficina que todavía no tienen recepción física registrada. Requiere office_cd.',
                'ability' => 'ips.read',
            ],
            [
                'group' => 'IPS',
                'method' => 'GET',
                'path' => '/api/v1/ips/envases-abiertos-registro-paquetes',
                'description' => 'Lista sacas abiertas en una oficina con conteos declarados y paquetes ya registrados. Requiere office_cd.',
                'ability' => 'ips.read',
            ],
            [
                'group' => 'IPS',
                'method' => 'GET',
                'path' => '/api/v1/ips/envases/{codigo}',
                'description' => 'Consulta el detalle de una saca en la oficina indicada. Requiere office_cd.',
                'ability' => 'ips.read',
            ],
            [
                'group' => 'IPS',
                'method' => 'GET',
                'path' => '/api/v1/ips/paquetes/{codigo}',
                'description' => 'Consulta un paquete y sus eventos.',
                'ability' => 'ips.read',
            ],
            [
                'group' => 'IPS',
                'method' => 'GET',
                'path' => '/api/v1/ips/operaciones/{id}',
                'description' => 'Consulta el resultado de una operación.',
                'ability' => 'ips.operations',
            ],
            [
                'group' => 'IPS',
                'method' => 'POST',
                'path' => '/api/v1/ips/paquetes',
                'description' => 'Registra un paquete IPS.',
                'ability' => 'ips.create',
            ],
            [
                'group' => 'IPS',
                'method' => 'POST',
                'path' => '/api/v1/ips/usuarios',
                'description' => 'Registra un usuario IPS.',
                'ability' => 'ips.write',
            ],
            [
                'group' => 'IPS',
                'method' => 'POST',
                'path' => '/api/v1/ips/paquetes/{codigo}/eventos',
                'description' => 'Registra un evento; EMI requiere además ips.deliver.',
                'ability' => 'ips.events',
            ],
            [
                'group' => 'IPS',
                'method' => 'POST',
                'path' => '/api/v1/ips/envases/{codigo}/eventos',
                'description' => 'Registra un evento operativo de una saca IPS.',
                'ability' => 'ips.events',
            ],
            [
                'group' => 'IPS',
                'method' => 'POST',
                'path' => '/api/v1/ips/paquetes/{codigo}/entrega',
                'description' => 'Registra la entrega de un paquete.',
                'ability' => 'ips.deliver',
            ],
        ];
    }

    /**
     * Each catalog route declares its consumer contract: page identity, response
     * renderer, and safe request values used to prepare the live call.
     */
    private function consumerContract(string $method, string $path): ?array
    {
        $key = $method.' '.$path;
        // Reusable records verified in the current IPS/CDS data. The customs
        // dispatch, receptacle and packages below belong to the same shipment.
        $demoPackageCodes = ['EE000738405BO', 'EE000738428BO', 'EE000738476BO', 'EE000748014BO'];
        $demoDispatchCode = 'BOLPBBCAYTOAAEN60031';
        $demoReceptacleCode = 'BOLPBBCAYTOAAEN60031001100007';
        $demoInboundPrealertCode = 'DEFRAABOLPBAAUX60098';
        $contracts = [
            'GET /api/tracking/eventos' => ['domain' => 'tracking', 'renderer' => 'tracking.single', 'title' => 'Seguimiento de un envío', 'intro' => 'El consumidor identifica el envío y lee su ruta, estado y movimientos cronológicos.', 'query' => ['codigo' => $demoPackageCodes[0]]],
            'POST /api/tracking/eventos/batch' => ['domain' => 'tracking', 'renderer' => 'tracking.batch', 'title' => 'Seguimiento por lote', 'intro' => 'El consumidor consulta varios códigos y compara el recorrido devuelto para cada envío.', 'body' => ['codigos' => $demoPackageCodes]],
            'POST /api/tracking/destinos/batch' => ['domain' => 'tracking', 'renderer' => 'tracking.destinations', 'title' => 'Destinos por lote', 'intro' => 'El consumidor cruza cada código con su ciudad y país de destino.', 'body' => ['codigos' => $demoPackageCodes]],
            'GET /api/tracking/eventos-todos' => ['domain' => 'tracking', 'renderer' => 'tracking.history', 'title' => 'Historial de un envío', 'intro' => 'El consumidor revisa los eventos disponibles para el código solicitado.', 'query' => ['codigo' => $demoPackageCodes[0]]],
            'GET /api/tracking/paquetes' => ['domain' => 'tracking', 'renderer' => 'tracking.packages', 'title' => 'Bandeja de paquetes', 'intro' => 'El consumidor navega los paquetes postales por página y consulta sus estados.', 'query' => ['page' => '1', 'per_page' => '10']],
            'GET /api/tracking/cds/declaraciones' => ['domain' => 'tracking', 'renderer' => 'tracking.cds-list', 'title' => 'Bandeja de declaraciones CDS', 'intro' => 'El consumidor revisa paquetes CDS, declaraciones, respuestas y paginación.', 'query' => ['page' => '1', 'per_page' => '10']],
            'POST /api/tracking/cds/declaraciones' => ['domain' => 'tracking', 'renderer' => 'tracking.cds-batch', 'title' => 'Consulta CDS por lote', 'intro' => 'El consumidor envía códigos y recibe el estado CDS de cada paquete consultado.', 'body' => ['codes' => $demoPackageCodes]],
            'GET /api/v1/postal/prealertas' => ['domain' => 'customs', 'renderer' => 'customs.prealerts', 'title' => 'Bandeja de prealertas PREDES', 'intro' => 'Muestra manifiestos S8 dirigidos a Bolivia que aún tienen al menos una saca sin recepción registrada en IPS. Los parcialmente recibidos permanecen en la lista.', 'query' => ['desde' => '__DATE_7_DAYS_AGO__', 'hasta' => '__TODAY__', 'limite' => '10']],
            'GET /api/v1/postal/prealertas/{codigo}' => ['domain' => 'customs', 'renderer' => 'customs.prealert', 'title' => 'Manifiesto PREDES del despacho', 'intro' => 'Expediente del S8 anunciado: datos del despacho, sacas S9, paquetes S10, declaraciones CDS enlazadas y recepción IPS registrada si existe.', 'identifier' => $demoInboundPrealertCode],
            'GET /api/v1/postal/despachos/{codigo}' => ['domain' => 'customs', 'renderer' => 'customs.dispatch', 'title' => 'Detalle de despacho IPS', 'intro' => 'Consulta el registro operativo del despacho en IPS, su ruta y las sacas vinculadas. No es el manifiesto PREDES ni confirma recepción física.', 'identifier' => $demoDispatchCode],
            'GET /api/v1/postal/sacas/{codigo}' => ['domain' => 'customs', 'renderer' => 'customs.receptacle', 'title' => 'Detalle de saca S9', 'intro' => 'Muestra identificación, despacho relacionado, conteo, códigos de paquetes S10 y resumen de actividad/CDS; no incluye fichas completas de los paquetes.', 'identifier' => $demoReceptacleCode],
            'GET /api/v1/postal/paquetes/{codigo}' => ['domain' => 'customs', 'renderer' => 'customs.package', 'title' => 'Detalle de paquete S10', 'intro' => 'Muestra los datos postales del paquete, el último movimiento IPS y referencias relacionadas. El historial completo y el detalle CDS tienen consultas propias.', 'identifier' => $demoPackageCodes[0]],
            'GET /api/v1/postal/declaraciones/{codigo}' => ['domain' => 'customs', 'renderer' => 'customs.declarations', 'title' => 'Expediente CDS del paquete', 'intro' => 'Consulta declaraciones y respuestas CDS del paquete con sus campos, artículos, documentos y eventos disponibles.', 'identifier' => $demoPackageCodes[0]],
            'GET /api/v1/postal/consulta/{codigo}' => ['domain' => 'customs', 'renderer' => 'customs.lookup', 'title' => 'Consulta postal por código', 'intro' => 'Identifica si el código corresponde a despacho, saca o paquete y reúne sus datos relacionados. Para S8 devuelve el despacho operativo IPS, no el manifiesto PREDES.', 'identifier' => $demoPackageCodes[0]],
            'GET /api/v1/ips/catalogos' => ['domain' => 'ips', 'renderer' => 'ips.catalog', 'title' => 'Catálogos operativos IPS', 'intro' => 'El consumidor carga referencias de eventos, oficinas, países y reglas operativas.', 'query' => ['office_cd' => '1']],
            'GET /api/v1/ips/catalogos/entrega' => ['domain' => 'ips', 'renderer' => 'ips.delivery-catalog', 'title' => 'Catálogos para entrega', 'intro' => 'El consumidor carga carteros regionales, oficinas y motivos de no entrega.', 'query' => ['office_cd' => '1']],
            'GET /api/v1/ips/usuarios' => ['domain' => 'ips', 'renderer' => 'ips.users', 'title' => 'Directorio de usuarios IPS', 'intro' => 'El consumidor consulta usuarios activos, oficina, dominio y paginación.', 'query' => ['page' => '1', 'per_page' => '10']],
            'GET /api/v1/ips/usuarios/{pid}' => ['domain' => 'ips', 'renderer' => 'ips.user', 'title' => 'Ficha de usuario IPS', 'intro' => 'El consumidor recupera una ficha de usuario identificada por su PID.', 'identifier' => '1001'],
            'GET /api/v1/ips/paquetes' => ['domain' => 'ips', 'renderer' => 'ips.packages', 'title' => 'Bandeja postal IPS', 'intro' => 'El consumidor revisa paquetes, estado postal, oficina, peso y acciones disponibles.', 'query' => ['page' => '1', 'per_page' => '10']],
            'GET /api/v1/ips/paquetes/pendientes-entrega' => ['domain' => 'ips', 'renderer' => 'ips.pending-delivery', 'title' => 'Bandeja de entregas pendientes', 'intro' => 'El consumidor prepara el trabajo de entrega usando solo paquetes pendientes.', 'query' => ['page' => '1', 'per_page' => '10']],
            'GET /api/v1/ips/envases-pendientes-apertura' => ['domain' => 'ips', 'renderer' => 'ips.queue-opening', 'title' => 'Cola de sacas por abrir', 'intro' => 'El consumidor recibe sacas en la oficina con la acción permitida para apertura.', 'query' => ['office_cd' => '1', 'page' => '1', 'per_page' => '10']],
            'GET /api/v1/ips/envases-pendientes-recepcion' => ['domain' => 'ips', 'renderer' => 'ips.queue-reception', 'title' => 'Cola de recepción de sacas', 'intro' => 'El consumidor contrasta PREDES con la recepción física pendiente en IPS.', 'query' => ['office_cd' => '1', 'page' => '1', 'per_page' => '10']],
            'GET /api/v1/ips/envases-abiertos-registro-paquetes' => ['domain' => 'ips', 'renderer' => 'ips.queue-registration', 'title' => 'Control de registro de paquetes', 'intro' => 'El consumidor compara cantidad declarada, paquetes registrados y saldo por saca abierta.', 'query' => ['office_cd' => '1', 'page' => '1', 'per_page' => '10']],
            'GET /api/v1/ips/envases/{codigo}' => ['domain' => 'ips', 'renderer' => 'ips.receptacle', 'title' => 'Ficha de saca IPS', 'intro' => 'El consumidor consulta la saca, sus paquetes asociados y el estado operativo en una oficina.', 'identifier' => $demoReceptacleCode, 'query' => ['office_cd' => '1']],
            'GET /api/v1/ips/paquetes/{codigo}' => ['domain' => 'ips', 'renderer' => 'ips.package', 'title' => 'Ficha de paquete IPS', 'intro' => 'El consumidor consulta el envío, sus movimientos y acciones permitidas en oficina.', 'identifier' => $demoPackageCodes[0], 'query' => ['office_cd' => '1']],
            'GET /api/v1/ips/operaciones/{id}' => ['domain' => 'ips', 'renderer' => 'ips.operation', 'title' => 'Estado de operación IPS', 'intro' => 'El consumidor verifica el resultado idempotente de una operación previamente enviada.', 'identifier' => '00000000-0000-4000-8000-000000000001'],
            'POST /api/v1/ips/paquetes' => ['domain' => 'ips', 'renderer' => 'ips.write', 'title' => 'Registro de paquete IPS', 'intro' => 'El consumidor envía un alta postal y recibe el estado e identificador de la operación.', 'write' => true, 'body' => ['codigo' => 'DEMO-API-001', 'idempotency_key' => '__IDEMPOTENCY_KEY__', 'event' => 'EMA', 'occurred_at' => '__NOW__', 'office_cd' => 1, 'mail_class' => 'E', 'origin_country' => 'BO', 'destination_country' => 'BO', 'weight_kg' => 0.5, 'sender' => ['name' => 'Remitente de demostración', 'address' => 'Av. Ejemplo 123', 'city' => 'La Paz', 'country' => 'BO'], 'recipient' => ['name' => 'Destinatario de demostración', 'address' => 'Calle Ejemplo 456', 'city' => 'La Paz', 'country' => 'BO']]],
            'POST /api/v1/ips/usuarios' => ['domain' => 'ips', 'renderer' => 'ips.write', 'title' => 'Alta de usuario IPS', 'intro' => 'El consumidor envía una solicitud de alta y revisa la ficha creada por IPS.', 'write' => true, 'body' => ['user_fid' => 'DEMO.API', 'user_name' => 'Usuario de demostración', 'user_domain' => 'SITRA', 'office_cd' => 1, 'email' => 'demo@example.com']],
            'POST /api/v1/ips/paquetes/{codigo}/eventos' => ['domain' => 'ips', 'renderer' => 'ips.write', 'title' => 'Registro de evento postal', 'intro' => 'El consumidor registra un evento para el paquete y confirma la operación devuelta por IPS.', 'write' => true, 'identifier' => 'EE123456789BO', 'body' => ['idempotency_key' => '__IDEMPOTENCY_KEY__', 'event' => 'EDH', 'occurred_at' => '__NOW__', 'office_cd' => 1, 'expected_event_cd' => 1, 'expected_event_at' => '__NOW__']],
            'POST /api/v1/ips/envases/{codigo}/eventos' => ['domain' => 'ips', 'renderer' => 'ips.write', 'title' => 'Registro de evento de saca', 'intro' => 'El consumidor registra apertura u otro evento permitido para la saca en IPS.', 'write' => true, 'identifier' => 'CS001230001BO', 'body' => ['idempotency_key' => '__IDEMPOTENCY_KEY__', 'event' => 'RECEPTACLE_OPEN', 'occurred_at' => '__NOW__', 'office_cd' => 1, 'expected_event_cd' => 130, 'expected_event_at' => '__NOW__']],
            'POST /api/v1/ips/paquetes/{codigo}/entrega' => ['domain' => 'ips', 'renderer' => 'ips.write', 'title' => 'Confirmación de entrega', 'intro' => 'El consumidor registra una entrega postal y revisa el acuse que responde IPS.', 'write' => true, 'identifier' => 'EE123456789BO', 'body' => ['idempotency_key' => '__IDEMPOTENCY_KEY__', 'occurred_at' => '__NOW__', 'delivery_mode' => 'NO_DOMICILIARIA']],
        ];

        return $contracts[$key] ?? null;
    }

    private function decryptTokenSecret(?string $encryptedSecret): ?string
    {
        if (! $encryptedSecret) {
            return null;
        }

        try {
            return Crypt::decryptString($encryptedSecret);
        } catch (DecryptException) {
            return null;
        }
    }

    private function findAdminToken(int $token): PersonalAccessToken
    {
        $adminIds = User::role('admin')->select((new User())->getQualifiedKeyName());

        return PersonalAccessToken::query()
            ->where('tokenable_type', (new User())->getMorphClass())
            ->whereIn('tokenable_id', $adminIds)
            ->whereKey($token)
            ->firstOrFail();
    }
}
