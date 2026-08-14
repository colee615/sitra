<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SqlServerSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class SqlServerPackageCountController extends Controller
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
            'q' => ['nullable', 'string', 'max:40'],
        ]);

        $search = isset($validated['q']) ? trim((string) $validated['q']) : null;

        try {
            return response()->json([
                'total' => $searchService->countPackages($search),
                'q' => $search,
            ])->header('X-Tracking-Backend', 'sqlsrv');
        } catch (Throwable $e) {
            Log::error('Error contando paquetes SQL Server', [
                'q' => $search,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudo obtener el conteo de paquetes.',
            ], 500);
        }
    }
}
