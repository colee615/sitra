<?php

namespace App\Http\Controllers;

use App\Services\SqlServerSearchService;
use Illuminate\Http\Request;
use Throwable;

class SqlServerDataController extends Controller
{
    public function index(Request $request, SqlServerSearchService $searchService)
    {
        if (!$request->user() || !$request->user()->hasRole('admin')) {
            abort(403, 'Solo los administradores pueden ver esta pagina.');
        }

        $codigo = trim((string) $request->query('codigo', ''));

        try {
            $result = $searchService->search($codigo);
            $result['error'] = null;

            return view('sqlserver.index', $result);
        } catch (Throwable $e) {
            return view('sqlserver.index', [
                'codigo' => strtoupper($codigo),
                'packageRows' => collect(),
                'trackingRows' => collect(),
                'customerRows' => collect(),
                'deliveryRows' => collect(),
                'logisticRows' => collect(),
                'manifestRows' => collect(),
                'ediRows' => collect(),
                'tableMap' => [],
                'error' => $e->getMessage(),
            ]);
        }
    }
}
