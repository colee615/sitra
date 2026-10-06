<?php

namespace App\Http\Controllers;

use App\Services\Postal\DashboardFilters;
use App\Services\Postal\OperationalDashboard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class OperationalDashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->can('postal.access')) {
            return redirect()->route('dashboard.combined');
        }

        if ($user->can('postal.ips')) {
            return redirect()->route('dashboard.ips');
        }

        if ($user->can('postal.cds')) {
            return redirect()->route('dashboard.cds');
        }

        return view('postal.dashboard-access');
    }

    public function ips(Request $request)
    {
        if ($request->user()->can('postal.access')) {
            return redirect()->route('dashboard.combined');
        }

        return view('postal.volume-dashboard', ['scope' => 'ips']);
    }

    public function combined()
    {
        return view('postal.volume-dashboard', ['scope' => 'all']);
    }

    public function cds(Request $request)
    {
        if ($request->user()->can('postal.access')) {
            return redirect()->route('dashboard.combined');
        }

        return view('postal.volume-dashboard', ['scope' => 'cds']);
    }

    public function data(Request $request, OperationalDashboard $dashboard)
    {
        $scope = $request->validate(['scope' => ['nullable', Rule::in(['all', 'ips', 'cds'])]])['scope'] ?? 'all';
        $filters = DashboardFilters::fromRequest($request);
        $result = ['filters' => $filters, 'catalog' => [], 'ips' => ['status' => 'forbidden'], 'cds' => ['status' => 'forbidden']];
        if (in_array($scope, ['all', 'ips'], true) && $request->user()->can('postal.ips')) {
            try {
                $result['catalog'] = $dashboard->catalog();
            } catch (Throwable $e) {
                $this->logFailure('catalog', $e);
            }
            try {
                $result['ips'] = $dashboard->ips($filters, true);
            } catch (Throwable $e) {
                $this->logFailure('ips', $e);
                $result['ips'] = ['status' => 'unavailable', 'message' => 'No se pudo consultar IPS. Vuelve a intentar o reduce el periodo.'];
            }
        }
        if (in_array($scope, ['all', 'cds'], true) && $request->user()->can('postal.cds')) {
            try {
                $cdsFilters = $filters;
                if ($scope === 'all') {
                    // The IPS country, state, category, product and office dimensions have no verified
                    // one-to-one mapping to CDS. Keep the customs source independently filtered.
                    foreach (['state', 'origin', 'destination', 'type', 'mail_category', 'mail_product'] as $dimension) {
                        $cdsFilters[$dimension] = null;
                    }
                    $cdsFilters['office'] = $filters['cds_office'] ?? null;
                }
                $result['cds'] = $dashboard->cds($cdsFilters, true);
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
