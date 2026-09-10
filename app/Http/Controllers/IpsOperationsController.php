<?php

namespace App\Http\Controllers;

use App\Exceptions\IpsOperationException;
use App\Http\Controllers\Api\IpsController;
use App\Http\Requests\IpsWriteRequest;
use App\Services\IpsOperationService;
use App\Services\IpsRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class IpsOperationsController extends Controller
{
    public function index(Request $request, IpsRepository $ips)
    {
        $filters = $request->validate(IpsController::filterRules());
        $filters['status'] ??= 'pending';
        $result = ['data' => [], 'meta' => ['page' => 1, 'has_more' => false]];
        $catalog = ['offices' => [], 'mail_classes' => [], 'countries' => [], 'non_delivery_reasons' => [], 'non_delivery_measures' => []];
        $detail = null;
        $error = null;
        $operations = collect();
        try {
            $catalog = $ips->catalog();
            $result = $ips->packages($filters);
            if (! empty($filters['q'])) {
                $detail = $ips->detail(strtoupper(trim($filters['q'])));
            }
            $operations = DB::table('ips_operations')->where('user_id', $request->user()->id)->orderByDesc('created_at')->limit(20)->get();
        } catch (IpsOperationException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            Log::error('IPS operations screen unavailable', ['exception_type' => $e::class]);
            $error = 'No se pudo cargar toda la información. Compruebe las conexiones y la migración de operaciones.';
        }

        return view('operaciones.index', compact('result', 'catalog', 'detail', 'error', 'filters', 'operations'));
    }

    public function write(IpsWriteRequest $request, IpsOperationService $operations)
    {
        try {
            $result = $operations->submit($request->user()->id, $request->route()->defaults['ips_action'] ?? 'event', $request->validated());

            return redirect()->route('operaciones.index', ['q' => $request->validated('codigo'), 'status' => 'all'])
                ->with('operation_result', $result['body']);
        } catch (IpsOperationException $e) {
            return back()->withInput()->withErrors(['operation' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('IPS web operation unavailable', ['exception_type' => $e::class]);

            return back()->withInput()->withErrors(['operation' => 'Servicio no disponible. Verifique la bitácora antes de reenviar.']);
        }
    }
}
