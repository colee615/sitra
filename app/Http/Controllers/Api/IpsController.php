<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\IpsOperationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\IpsWriteRequest;
use App\Services\IpsOperationService;
use App\Services\IpsRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class IpsController extends Controller
{
    public function index(Request $request, IpsRepository $ips)
    {
        $filters = $request->validate(self::filterRules());
        if ($request->route()->defaults['ips_pending'] ?? false) {
            $filters['status'] = 'pending';
        }

        return $this->respond(fn () => $ips->packages($filters));
    }

    public static function filterRules(): array
    {
        return [
            'status' => ['nullable', 'in:all,pending,delivered'],
            'q' => ['nullable', 'string', 'max:35'],
            'office_cd' => ['nullable', 'integer', 'min:1', 'max:32767'],
            'event_cd' => ['nullable', 'integer', 'min:1', 'max:32767'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d\TH:i:sP'],
            'to' => ['nullable', 'date_format:Y-m-d\TH:i:sP', 'after_or_equal:from'],
        ];
    }

    public function show(string $codigo, IpsRepository $ips)
    {
        return $this->respond(fn () => ['data' => $ips->detail(strtoupper($codigo))]);
    }

    public function catalog(IpsRepository $ips)
    {
        return $this->respond(fn () => ['data' => $ips->catalog()]);
    }

    public function operation(Request $request, string $id, IpsOperationService $operations)
    {
        return $this->respond(fn () => ['data' => $operations->find($request->user()->id, $id)]);
    }

    public function write(IpsWriteRequest $request, IpsOperationService $operations)
    {
        try {
            $result = $operations->submit($request->user()->id, $request->route()->defaults['ips_action'] ?? 'event', $request->validated());

            return response()->json($result['body'], $result['status'])->header('Idempotency-Replayed', $result['replayed'] ? 'true' : 'false');
        } catch (IpsOperationException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        } catch (Throwable $e) {
            Log::error('IPS API unavailable', ['exception_type' => $e::class]);

            return response()->json(['message' => 'Servicio no disponible. Consulte el estado antes de reenviar una operación.'], 503);
        }
    }

    private function respond(callable $callback)
    {
        try {
            return response()->json($callback())->header('Cache-Control', 'no-store');
        } catch (IpsOperationException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        } catch (Throwable $e) {
            Log::error('IPS query unavailable', ['exception_type' => $e::class]);

            return response()->json(['message' => 'No se pudo consultar IPS. Intente nuevamente.'], 503);
        }
    }
}
