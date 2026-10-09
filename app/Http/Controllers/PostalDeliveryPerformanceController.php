<?php

namespace App\Http\Controllers;

use App\Services\Postal\PostalDeliveryPerformanceReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class PostalDeliveryPerformanceController extends Controller
{
    public function index(Request $request, PostalDeliveryPerformanceReport $report)
    {
        $filters = $this->filters($request);
        try {
            $catalog = ['offices' => $report->offices(), 'services' => $report->services()];
            $result = $report->build($filters, 1000);
            $error = null;
        } catch (Throwable $exception) {
            Log::warning('IPS delivery performance report unavailable', ['exception' => get_class($exception)]);
            $catalog = ['offices' => collect(), 'services' => collect()];
            $result = null;
            $error = 'No se pudo consultar la actividad de entregas en IPS. Intenta nuevamente o contacta al administrador.';
        }

        return response()->view('postal.delivery-performance', [
            'filters' => $filters,
            'catalog' => $catalog,
            'result' => $result,
            'error' => $error,
            'query' => $request->query(),
            'pdfUrl' => route('postal.deliveries.performance.pdf', $request->query()),
            'csvUrl' => route('postal.deliveries.performance.csv', $request->query()),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function pdf(Request $request, PostalDeliveryPerformanceReport $report)
    {
        $filters = $this->filters($request);
        try {
            $catalog = ['offices' => $report->offices(), 'services' => $report->services()];
            $result = $report->build($filters, 2000);
        } catch (Throwable $exception) {
            Log::warning('IPS delivery performance PDF unavailable', ['exception' => get_class($exception)]);
            abort(503, 'No se pudo preparar el PDF de entregas. Intenta nuevamente.');
        }

        $filename = sprintf('rendimiento-entregas-ips-%s-%s.pdf', $filters['from']->format('Ymd'), $filters['to']->format('Ymd'));

        $pdf = Pdf::loadView('postal.delivery-performance-pdf', [
            'filters' => $filters,
            'catalog' => $catalog,
            'result' => $result,
            'preparedBy' => $request->user()->name,
        ])->setPaper('a4', 'landscape');
        $response = $pdf->download($filename);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function csv(Request $request, PostalDeliveryPerformanceReport $report)
    {
        $filters = $this->filters($request);
        $filename = sprintf('rendimiento-entregas-ips-%s-%s.csv', $filters['from']->format('Ymd'), $filters['to']->format('Ymd'));

        return response()->streamDownload(function () use ($report, $filters) {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Código postal', 'ID local IPS', 'Fecha y hora local', 'Evento IPS', 'Código de evento', 'Oficina', 'Registrado por', 'Usuario IPS', 'Servicio']);
            foreach ($report->exportRows($filters)->cursor() as $row) {
                $localTime = CarbonImmutable::parse($row->EVENT_GMT_DT, 'UTC')->setTimezone(config('postal.timezone', 'America/La_Paz'));
                $cells = [
                    $row->MAILITM_FID,
                    $row->MAILITM_LOCAL_ID,
                    $localTime->format('d/m/Y H:i'),
                    $row->EVENT_NAME ?: 'Evento de entrega IPS',
                    $row->EVENT_TYPE_CD,
                    trim(($row->OFFICE_FCD ?? '').' '.($row->OFFICE_NM ?? '')) ?: 'Oficina no informada',
                    $row->USER_NM ?: $row->USER_FID ?: 'Usuario no informado por IPS',
                    $row->USER_FID,
                    $row->MAIL_CLASS_NM ?: $row->MAIL_CLASS_CD,
                ];
                fputcsv($stream, array_map([$this, 'safeCsvCell'], $cells));
            }
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function filters(Request $request): array
    {
        $input = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
            'oficina' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,10}$/'],
            'servicio' => ['nullable', 'string', 'max:3'],
            'usuario' => ['nullable', 'regex:/^[A-Za-z0-9_-]{1,40}$/'],
        ]);

        $timezone = config('postal.timezone', 'America/La_Paz');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $input['desde'] ?? $today->startOfMonth()->toDateString(), $timezone);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $input['hasta'] ?? $today->toDateString(), $timezone);
        $maxDays = max(1, (int) config('postal.delivery_report_max_days', 366));

        if ($to->lessThan($from)) {
            throw ValidationException::withMessages(['hasta' => 'La fecha final debe ser igual o posterior a la fecha inicial.']);
        }
        if ($from->diffInDays($to) >= $maxDays) {
            throw ValidationException::withMessages(['hasta' => "El periodo máximo es de {$maxDays} días. Ajusta las fechas para consultar el reporte completo."]);
        }

        return [
            'from' => $from,
            'to' => $to,
            'from_utc' => $from->utc(),
            'until_utc' => $to->addDay()->utc(),
            'office' => trim((string) ($input['oficina'] ?? '')),
            'service' => trim((string) ($input['servicio'] ?? '')),
            'user' => trim((string) ($input['usuario'] ?? '')),
            'timezone' => $timezone,
        ];
    }

    private function safeCsvCell(mixed $value): string
    {
        $value = (string) ($value ?? '');
        return preg_match('/^[\s]*[=+@\-\t\r]/u', $value) ? "'".$value : $value;
    }
}
