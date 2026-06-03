<?php

namespace App\Http\Controllers;

use App\Services\CdsDbSearchService;
use Illuminate\Http\Request;
use Throwable;

class CdsDbDataController extends Controller
{
    public function index(Request $request, CdsDbSearchService $searchService)
    {
        if (!$request->user() || !$request->user()->hasRole('admin')) {
            abort(403, 'Solo los administradores pueden ver esta pagina.');
        }

        $codigo = trim((string) $request->query('codigo', ''));

        try {
            $result = $searchService->search($codigo);
            $result['error'] = null;

            return view('cds.index', $result);
        } catch (Throwable $e) {
            return view('cds.index', [
                'codigo' => strtoupper($codigo),
                'objectRows' => collect(),
                'stateRows' => collect(),
                'declarationRows' => collect(),
                'declarationEventRows' => collect(),
                'responseRows' => collect(),
                'responseEventRows' => collect(),
                'ediExportRows' => collect(),
                'auditRows' => collect(),
                'anDeclarationRows' => collect(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
