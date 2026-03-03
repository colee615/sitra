<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SqlServerSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class SqlServerSearchController extends Controller
{
    public function __invoke(Request $request, SqlServerSearchService $searchService): JsonResponse
    {
        if (!$request->user() || !$request->user()->hasRole('admin')) {
            return response()->json([
                'message' => 'No autorizado para consultar este recurso.',
            ], 403);
        }

        if (!$request->user()->tokenCan('sqlserver.read')) {
            return response()->json([
                'message' => 'El token no tiene permiso para leer este recurso.',
            ], 403);
        }

        $validated = $request->validate([
            'codigo' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/'],
        ]);

        $codigo = $validated['codigo'];

        try {
            $result = $searchService->search($codigo);

            return response()->json([
                'codigo' => $result['codigo'],
                'packageRows' => $result['packageRows'],
                'trackingRows' => $result['trackingRows'],
                'customerRows' => $result['customerRows'],
                'deliveryRows' => $result['deliveryRows'],
                'logisticRows' => $result['logisticRows'],
                'manifestRows' => $result['manifestRows'],
                'ediRows' => $result['ediRows'],
                'tableMap' => $result['tableMap'],
                'error' => null,
            ]);
        } catch (Throwable $e) {
            Log::error('Error consultando SQL Server API', [
                'codigo' => strtoupper(trim($codigo)),
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'codigo' => strtoupper(trim($codigo)),
                'packageRows' => [],
                'trackingRows' => [],
                'customerRows' => [],
                'deliveryRows' => [],
                'logisticRows' => [],
                'manifestRows' => [],
                'ediRows' => [],
                'tableMap' => [],
                'error' => 'Error interno al consultar datos.',
            ], 500);
        }
    }
}
