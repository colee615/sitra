<?php

namespace App\Http\Controllers;

use App\Services\Postal\DashboardFilters;
use App\Services\Postal\OperationalDashboard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class OperationalDashboardController extends Controller
{
    public function index()
    {
        return view('dashboard');
    }

    public function data(Request $request, OperationalDashboard $dashboard)
    {
        $filters = DashboardFilters::fromRequest($request);
        $result = ['filters' => $filters, 'catalog' => [], 'ips' => ['status' => 'forbidden'], 'cds' => ['status' => 'forbidden']];
        if ($request->user()->can('postal.ips')) {
            try {
                $result['catalog'] = $dashboard->catalog();
            } catch (Throwable $e) {
                $this->logFailure('catalog', $e);
            }
            try {
                $result['ips'] = $dashboard->ips($filters);
            } catch (Throwable $e) {
                $this->logFailure('ips', $e);
                $result['ips'] = ['status' => 'unavailable', 'message' => 'No se pudo consultar IPS. Vuelve a intentar o reduce el periodo.'];
            }
        }
        if ($request->user()->can('postal.cds')) {
            try {
                $result['cds'] = $dashboard->cds($filters);
            } catch (Throwable $e) {
                $this->logFailure('cds', $e);
                $result['cds'] = ['status' => 'unavailable', 'message' => 'No se pudo consultar CDS. Vuelve a intentar.'];
            }
        }

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    private function logFailure(string $source, Throwable $e): void
    {
        Log::warning('Dashboard source unavailable', ['source' => $source, 'exception' => get_class($e), 'code' => $e->getCode()]);
    }
}
