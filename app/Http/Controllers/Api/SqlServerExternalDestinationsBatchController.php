<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TrackingDestinationCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class SqlServerExternalDestinationsBatchController extends Controller
{
    public function __invoke(Request $request, TrackingDestinationCacheService $destinationService): JsonResponse
    {
        if (! $request->user() || ! $request->user()->hasRole('admin')) {
            return response()->json(['message' => 'No autorizado para consultar este recurso.'], 403);
        }

        if (! $request->user()->tokenCan('sqlserver.read')) {
            return response()->json(['message' => 'El token no tiene permiso para leer este recurso.'], 403);
        }

        $validated = $request->validate([
            'codigos' => ['required', 'array', 'min:1', 'max:1000'],
            'codigos.*' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/'],
        ]);

        $codes = collect($validated['codigos'])
            ->map(fn ($codigo) => strtoupper(trim((string) $codigo)))
            ->filter()
            ->unique()
            ->values();

        try {
            $destinations = $destinationService->destinations($codes->all());

            return response()->json([
                'tipo' => 'tracking_destinos_batch',
                'total_codigos' => $codes->count(),
                'resultado' => $codes
                    ->map(fn (string $code) => $destinations[$code] ?? [
                        'codigo' => $code,
                        'ciudad' => null,
                        'pais' => null,
                        'pais_codigo' => null,
                        'destino' => null,
                    ])
                    ->values(),
            ])
                ->header('X-Tracking-Backend', 'sqlsrv')
                ->header('X-Tracking-Batch', 'true');
        } catch (Throwable $exception) {
            Log::error('Error consultando destinos SQL Server en lote', [
                'total_codigos' => $codes->count(),
                'muestra_codigos' => $codes->take(10)->all(),
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'tipo' => 'tracking_destinos_batch',
                'total_codigos' => $codes->count(),
                'resultado' => [],
            ], 500);
        }
    }
}
