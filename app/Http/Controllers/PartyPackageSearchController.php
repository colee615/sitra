<?php

namespace App\Http\Controllers;

use App\Services\SqlServerSearchService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Throwable;

class PartyPackageSearchController extends Controller
{
    public function __invoke(Request $request, SqlServerSearchService $searchService)
    {
        $filters = $request->validate([
            'remitente' => ['nullable', 'string', 'min:2', 'max:80'],
            'destinatario' => ['nullable', 'string', 'min:2', 'max:80'],
            'por_pagina' => ['nullable', 'integer', 'in:25,50,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $senderName = trim((string) ($filters['remitente'] ?? ''));
        $recipientName = trim((string) ($filters['destinatario'] ?? ''));
        $searched = $senderName !== '' || $recipientName !== '';
        $perPage = (int) ($filters['por_pagina'] ?? 25);
        $page = (int) ($filters['page'] ?? 1);
        $results = null;
        $error = null;

        if ($searched) {
            try {
                $search = $searchService
                    ->onConnection(config('postal.ips_read_connection', config('tracking.sqlserver.connection', 'sqlsrv')))
                    ->searchPackagesByParties($senderName, $recipientName, $page, $perPage);

                $results = new LengthAwarePaginator(
                    $search['rows'],
                    $search['total'],
                    $perPage,
                    $page,
                    ['path' => $request->url(), 'query' => $request->query()]
                );
            } catch (Throwable $exception) {
                Log::warning('IPS party package search unavailable', ['exception' => get_class($exception)]);
                $error = 'No se pudo consultar IPS en este momento. Vuelve a intentarlo.';
            }
        }

        return response()->view('postal.party-packages', [
            'filters' => [
                'remitente' => $senderName,
                'destinatario' => $recipientName,
                'por_pagina' => $perPage,
            ],
            'searched' => $searched,
            'results' => $results,
            'error' => $error,
        ])->header('Cache-Control', 'private, no-store');
    }
}
