<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\IpsOperationException;
use App\Http\Controllers\Controller;
use App\Http\Support\CustomsApiError;
use App\Services\Postal\CustomsApiPresenter;
use App\Services\Postal\CustomsOperationsService;
use App\Services\Postal\CustomsPrealertService;
use App\Services\Postal\PostalWorkspace;
use App\Services\Postal\ShipmentCode;
use App\Services\Postal\UpuDispatchIdentifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Database\ConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

final class CustomsOperationsController extends Controller
{
    public function lookup(string $codigo, CustomsOperationsService $customs, PostalWorkspace $workspace, CustomsApiPresenter $presenter): JsonResponse
    {
        return $this->respond(function () use ($codigo, $customs, $workspace, $presenter) {
            $codigo = ShipmentCode::normalize($codigo);
            $analysis = UpuDispatchIdentifier::parse($codigo);

            if (($analysis['type'] ?? null) === 's8') {
                $result = $customs->inspectDispatch($codigo);
                if (empty($result['dispatches'])) {
                    throw new IpsOperationException('No se encontró el despacho S8 en IPS.', 404);
                }

                return ['data' => $presenter->general('S8', $result)];
            }

            if (($analysis['type'] ?? null) === 's9') {
                $result = $customs->inspectReceptacle($codigo);

                return ['data' => $presenter->general('S9', $result)];
            }

            $result = $workspace->search($codigo, true, true);
            $sources = collect($result['sources'] ?? []);
            if (!$sources->contains('ok')) {
                if ($sources->contains('unavailable')) {
                    throw new IpsOperationException('IPS o CDS no está disponible; la consulta general quedó incompleta.', 503);
                }
                throw new IpsOperationException('No se encontró el paquete o identificador en IPS/CDS.', 404);
            }

            $type = ShipmentCode::describe($codigo)['type'] === 'S10' ? 'S10' : 'Código local';

            return ['data' => $presenter->general($type, $result)];
        });
    }

    public function package(string $codigo, PostalWorkspace $workspace, CustomsApiPresenter $presenter): JsonResponse
    {
        return $this->respond(function () use ($codigo, $workspace, $presenter) {
            $result = $workspace->search($codigo, true, true);
            $this->assertWorkspaceFound($result);

            return ['data' => $presenter->package($result)];
        });
    }

    public function declarations(string $codigo, PostalWorkspace $workspace, CustomsApiPresenter $presenter): JsonResponse
    {
        return $this->respond(function () use ($codigo, $workspace, $presenter) {
            $result = $workspace->search($codigo, true, true);
            $this->assertWorkspaceFound($result);

            return ['data' => $presenter->declarations($result)];
        });
    }

    public function dispatch(string $codigo, CustomsOperationsService $customs, CustomsApiPresenter $presenter): JsonResponse
    {
        return $this->respond(function () use ($codigo, $customs, $presenter) {
            $result = $customs->inspectDispatch($codigo);
            if (empty($result['dispatches'])) {
                throw new IpsOperationException('No se encontró el despacho S8 en IPS.', 404);
            }

            return ['data' => $presenter->dispatch($result)];
        });
    }

    public function receptacle(string $codigo, CustomsOperationsService $customs, CustomsApiPresenter $presenter): JsonResponse
    {
        return $this->respond(fn () => ['data'=>$presenter->receptacle(
            $customs->inspectReceptacle($codigo)
        )]);
    }

    public function prealerts(Request $request, CustomsPrealertService $prealerts): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
            'limite' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:1024', 'regex:/^[A-Za-z0-9_-]+$/'],
        ], [
            'desde.date_format' => 'El parámetro desde debe usar el formato YYYY-MM-DD.',
            'hasta.date_format' => 'El parámetro hasta debe usar el formato YYYY-MM-DD.',
            'limite.integer' => 'El parámetro limite debe ser un número entero.',
            'limite.min' => 'El parámetro limite debe ser al menos 1.',
            'limite.max' => 'El parámetro limite no puede superar 100.',
            'cursor.regex' => 'El cursor debe ser el valor opaco devuelto por SITRA.',
        ]);
        if ($validator->fails()) {
            return CustomsApiError::response(
                422,
                'Los parámetros de consulta de prealertas no son válidos.',
                $validator->errors()->toArray(),
            );
        }
        $input = $validator->validated();
        $timezone = (string) config('ips.operation_timezone', 'America/La_Paz');
        $today = Carbon::now($timezone)->toDateString();
        $from = $input['desde'] ?? Carbon::now($timezone)->subDays(6)->toDateString();
        $to = $input['hasta'] ?? $today;
        $fromDate = Carbon::createFromFormat('Y-m-d', $from, $timezone)->startOfDay();
        $toDate = Carbon::createFromFormat('Y-m-d', $to, $timezone)->startOfDay();

        $nextUrl = null;
        $response = $this->respond(function () use ($request, $prealerts, $from, $to, $fromDate, $toDate, $input, &$nextUrl) {
            if ($fromDate->greaterThan($toDate)) {
                throw new IpsOperationException('La fecha desde no puede ser posterior a la fecha hasta.', 422);
            }
            if ($fromDate->diffInDays($toDate) > 30) {
                throw new IpsOperationException('El rango de consulta no puede superar 31 días.', 422);
            }

            $page = $prealerts->index($from, $to, (int) ($input['limite'] ?? 20), $input['cursor'] ?? null);
            $nextCursor = $page['continuation_cursor'] ?? null;
            if (is_string($nextCursor) && $nextCursor !== '') {
                $query = http_build_query([
                    'desde' => $from,
                    'hasta' => $to,
                    'limite' => (int) ($input['limite'] ?? 20),
                    'cursor' => $nextCursor,
                ], '', '&', PHP_QUERY_RFC3986);
                $nextUrl = $request->url().'?'.$query;
            }

            return ['data' => ['dispatches' => $page['dispatches'] ?? []]];
        });

        if ($response->isSuccessful() && $nextUrl !== null) {
            $response->headers->set('Link', '<'.$nextUrl.'>; rel="next"');
        }

        return $response;
    }

    public function prealert(string $codigo, CustomsPrealertService $prealerts): JsonResponse
    {
        return $this->respond(fn () => ['data' => $prealerts->show($codigo)]);
    }

    private function assertWorkspaceFound(array $result): void
    {
        $sources = collect($result['sources'] ?? []);
        if ($sources->contains('ok')) {
            return;
        }

        if ($sources->contains('unavailable')) {
            throw new IpsOperationException('IPS o CDS no está disponible; la consulta quedó incompleta.', 503);
        }

        throw new IpsOperationException('No se encontró el paquete o identificador en IPS/CDS.', 404);
    }

    private function respond(callable $callback): JsonResponse
    {
        try {
            $result = $callback();
            return response()->json($result)->header('Cache-Control', 'no-store');
        } catch (IpsOperationException $exception) {
            return CustomsApiError::response($exception->status, $exception->getMessage());
        } catch (Throwable $exception) {
            $requestId = (string) \Illuminate\Support\Str::uuid();
            $unavailable = $this->isDependencyUnavailable($exception);
            $status = $unavailable ? 503 : 500;
            Log::log($unavailable ? 'warning' : 'error', 'Customs API request failed.', [
                'request_id' => $requestId,
                'exception_type' => $exception::class,
            ]);

            return CustomsApiError::response(
                $status,
                $unavailable
                    ? 'Una fuente de datos necesaria no está disponible temporalmente.'
                    : 'No se pudo completar la consulta aduanera.',
                requestId: $requestId,
            );
        }
    }

    private function isDependencyUnavailable(Throwable $exception): bool
    {
        while ($exception !== null) {
            if ($exception instanceof ConnectionException) {
                return true;
            }

            if ($exception instanceof QueryException) {
                $sqlState = strtoupper((string) $exception->getCode());
                if (str_starts_with($sqlState, '08') || in_array($sqlState, ['HYT00', 'HYT01'], true)) {
                    return true;
                }
            }

            $exception = $exception->getPrevious();
        }

        return false;
    }
}
