<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Postal\CdsRepository;
use App\Services\Postal\ShipmentCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class CdsDeclarationLookupController extends Controller
{
    public function __invoke(Request $request, CdsRepository $cds): JsonResponse
    {
        if (! $request->user() || ! $request->user()->hasRole('admin')) {
            return response()->json([
                'message' => 'No autorizado para consultar declaraciones CDS.',
            ], 403);
        }

        if (! $request->user()->tokenCan('cds.read')) {
            return response()->json([
                'message' => 'El token no tiene permiso para consultar declaraciones de CDS.',
            ], 403);
        }

        if (! config('postal.cds_enabled')) {
            return response()->json([
                'message' => 'La conexión de lectura a CDS no está habilitada.',
            ], 503);
        }

        $isGet = $request->isMethod('GET');
        $validated = $request->validate([
            'codes' => [$isGet ? 'sometimes' : 'required', 'array', 'min:1', 'max:100'],
            'codes.*' => ['required', 'string', 'max:35', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/D'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $pagination = null;

        if (isset($validated['codes'])) {
            $codes = array_values(array_unique(array_map(
                static fn (string $code): string => ShipmentCode::normalize($code),
                $validated['codes'],
            )));
        } elseif ($isGet) {
            $page = (int) ($validated['page'] ?? 1);
            $perPage = (int) ($validated['per_page'] ?? 50);
            $packagePage = $cds->declarationPackageCodes($page, $perPage);
            $codes = $packagePage['codes'];
            $pagination = [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $packagePage['total'],
                'last_page' => max(1, (int) ceil($packagePage['total'] / $perPage)),
            ];
        } else {
            $codes = [];
        }

        $index = $cds->declarationIndex($codes, 1000, true);
        $packages = collect($index['packages'] ?? []);
        $declarationsByPackage = collect($index['declarations'] ?? [])
            ->groupBy(static fn (array $declaration): string => trim((string) ($declaration['package_id'] ?? '')));

        $data = collect($codes)->map(function (string $code) use ($packages, $declarationsByPackage, $index): array {
            $matchedPackages = $packages->filter(fn ($package) => $this->packageHasCode($package, $code));
            $primaryCodes = $matchedPackages
                ->map(fn ($package) => ShipmentCode::normalize((string) ($package->MAIL_OBJECT_ID ?? '')))
                ->filter()
                ->unique()
                ->all();
            $matchedPackages = $packages->filter(function ($package) use ($matchedPackages, $primaryCodes): bool {
                return $matchedPackages->contains('MAIL_OBJECT_PID', $package->MAIL_OBJECT_PID ?? null)
                    || in_array(ShipmentCode::normalize((string) ($package->MAIL_OBJECT_ID ?? '')), $primaryCodes, true);
            })->values();

            $objects = $matchedPackages->map(function ($package) use ($declarationsByPackage): array {
                $packageId = trim((string) ($package->MAIL_OBJECT_PID ?? ''));
                $declarations = $declarationsByPackage->get($packageId, collect())
                    ->map(fn (array $declaration): array => $this->transformDeclaration($declaration))
                    ->values();

                return [
                    'id_objeto_cds' => $package->MAIL_OBJECT_PID ?? null,
                    'codigo' => $this->nullableString($package->MAIL_OBJECT_ID ?? null),
                    'identificador_local' => $this->nullableString($package->MAIL_OBJECT_LOCAL_ID ?? null),
                    'identificador_local_2' => $this->nullableString($package->MAIL_OBJECT_LOCAL_ID2 ?? null),
                    'estado_objeto' => $this->nullableString($package->MAIL_STATE_NM ?? null),
                    'fecha_registro' => $this->nullableString($package->POSTING_DATE ?? null),
                    'declaraciones' => $declarations->all(),
                ];
            })->values();

            $declarationCount = $objects->sum(static fn (array $object): int => count($object['declaraciones']));
            $destinations = $objects->flatMap(static function (array $object): Collection {
                return collect($object['declaraciones'])
                    ->filter(static fn (array $declaration): bool => $declaration['destino_declarado'] !== null)
                    ->map(static fn (array $declaration): array => [
                        'codigo_objeto' => $object['codigo'],
                        'numero_declaracion' => $declaration['numero'],
                        ...$declaration['destino_declarado'],
                    ]);
            })->values()->all();
            $truncated = (bool) ($index['truncated'] ?? false);
            $status = match (true) {
                $truncated && ($objects->isEmpty() || $declarationCount === 0) => 'consulta_incompleta',
                $objects->isEmpty() => 'sin_objeto_cds',
                $declarationCount > 0 => 'declarada',
                default => 'objeto_sin_declaracion',
            };

            return [
                'codigo' => $code,
                'declaracion_cds' => [
                    'estado' => $status,
                    'truncado' => $truncated,
                    'objetos' => $objects->all(),
                    'destinos_declarados' => $destinations,
                ],
                'destinos_declarados' => $destinations,
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'count' => $data->count(),
                'cds_truncado' => (bool) ($index['truncated'] ?? false),
                ...($pagination ?? []),
            ],
        ]);
    }

    private function packageHasCode(object $package, string $code): bool
    {
        return collect([
            $package->MAIL_OBJECT_ID ?? null,
            $package->MAIL_OBJECT_LOCAL_ID ?? null,
            $package->MAIL_OBJECT_LOCAL_ID2 ?? null,
        ])->map(fn ($value): string => ShipmentCode::normalize((string) $value))
            ->contains($code);
    }

    private function transformDeclaration(array $declaration): array
    {
        $fields = $declaration['data']['fields'] ?? [];
        $city = $this->nullableString($fields['RCty'] ?? null);
        $countryCode = $this->nullableString($fields['RCtr'] ?? null);
        $department = $this->nullableString($fields['RSta'] ?? null);
        if ($department === null) {
            $department = $this->inferDepartmentFromCity($city, $countryCode);
        }

        $destination = [
            'ciudad' => $city,
            'departamento' => $department,
            'codigo_postal' => $this->nullableString($fields['RZip'] ?? null),
            'pais_codigo' => $countryCode,
        ];

        return [
            'id' => $declaration['id'] ?? null,
            'numero' => $declaration['declaration_number'] ?? null,
            'estado' => $declaration['state'] ?? null,
            'etapa' => $declaration['workflow_stage'] ?? null,
            'estado_datos' => $declaration['data_status'] ?? null,
            'destino_declarado' => collect($destination)->contains(fn ($value) => $value !== null) ? $destination : null,
            'resumen_contenido' => $declaration['content_summary'] ?? null,
            'cantidad_articulos' => $declaration['piece_count'] ?? 0,
            'cantidad_documentos' => $declaration['document_count'] ?? 0,
            'campos' => $fields,
            'articulos' => $declaration['data']['pieces'] ?? [],
            'documentos' => $declaration['data']['documents'] ?? [],
        ];
    }

    private function inferDepartmentFromCity(?string $city, ?string $countryCode): ?string
    {
        if (mb_strtoupper(trim((string) $countryCode), 'UTF-8') !== 'BO') {
            return null;
        }

        $normalizedCity = mb_strtoupper(trim((string) $city), 'UTF-8');
        $normalizedCity = preg_replace('/[\s,]+(?:BOLIVIA|BO)$/u', '', $normalizedCity) ?? $normalizedCity;

        return match ($normalizedCity) {
            'LA PAZ' => 'La Paz',
            'COCHABAMBA' => 'Cochabamba',
            'SANTA CRUZ' => 'Santa Cruz',
            'ORURO' => 'Oruro',
            'POTOSÍ', 'POTOSI' => 'Potosí',
            'TARIJA' => 'Tarija',
            'CHUQUISACA' => 'Chuquisaca',
            'BENI' => 'Beni',
            'PANDO' => 'Pando',
            default => null,
        };
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
