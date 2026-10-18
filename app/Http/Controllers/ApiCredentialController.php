<?php

namespace App\Http\Controllers;

use App\Models\User;
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
                    $token->plain_text_token = $token->secret_encrypted
                        ? Crypt::decryptString($token->secret_encrypted)
                        : null;

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
                'group' => 'Aduana',
                'method' => 'GET',
                'path' => '/api/v1/aduana/prealertas',
                'description' => 'Operación principal de sondeo: lista por fecha los PREDES S8 dirigidos a Bolivia, con conteos y cursor. No confirma llegada física.',
                'ability' => 'customs.prealerts.read',
            ],
            [
                'group' => 'Aduana',
                'method' => 'GET',
                'path' => '/api/v1/aduana/prealertas/{codigo}',
                'description' => 'Operación principal por despacho: PREDES S8 con sus S9, S10, declaraciones/respuestas CDS enlazadas y recepción IPS registrada si existe.',
                'ability' => 'customs.prealerts.read',
            ],
            [
                'group' => 'Aduana',
                'method' => 'GET',
                'path' => '/api/v1/aduana/despachos/{codigo}',
                'description' => 'Consulta puntual del despacho operativo registrado en IPS. No representa el preaviso PREDES ni confirma recepción física.',
                'ability' => 'customs.read',
            ],
            [
                'group' => 'Aduana',
                'method' => 'GET',
                'path' => '/api/v1/aduana/sacas/{codigo}',
                'description' => 'Consulta puntual de una saca S9 y sus referencias a despacho y paquetes; complementa el detalle completo de una prealerta.',
                'ability' => 'customs.read',
            ],
            [
                'group' => 'Aduana',
                'method' => 'GET',
                'path' => '/api/v1/aduana/paquetes/{codigo}',
                'description' => 'Consulta puntual de un S10: datos del paquete, su último movimiento IPS y referencias relacionadas. Complementa el flujo de prealertas.',
                'ability' => 'customs.read',
            ],
            [
                'group' => 'Aduana',
                'method' => 'GET',
                'path' => '/api/v1/aduana/declaraciones/{codigo}',
                'description' => 'Consulta puntual de declaraciones CDS y respuestas por código postal o local del paquete; incluye datos de artículos, documentos y eventos.',
                'ability' => 'customs.read',
            ],
            [
                'group' => 'Aduana',
                'method' => 'GET',
                'path' => '/api/v1/aduana/consulta/{codigo}',
                'description' => 'Consulta auxiliar por código (S8, S9, S10 o local). Para recibir carga entrante, usar primero el índice y detalle de prealertas PREDES.',
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
                'path' => '/api/v1/ips/paquetes/{codigo}/entrega',
                'description' => 'Registra la entrega de un paquete.',
                'ability' => 'ips.deliver',
            ],
        ];
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
