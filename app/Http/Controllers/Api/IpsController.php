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
            'status' => ['nullable', 'in:all,pending,reception,returns,delivered,warehouse'],
            'q' => ['nullable', 'string', 'max:35'],
            'office_cd' => ['nullable', 'integer', 'min:1', 'max:32767'],
            'event_cd' => ['nullable', 'integer', 'min:1', 'max:32767'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d\TH:i:sP'],
            'to' => ['nullable', 'date_format:Y-m-d\TH:i:sP', 'after_or_equal:from'],
        ];
    }

    public function show(Request $request, string $codigo, IpsRepository $ips)
    {
        $filters = $request->validate(['office_cd' => ['nullable', 'integer', 'min:1', 'max:32767']]);
        return $this->respond(function () use ($codigo, $ips, $filters) {
            $detail = $ips->detail(strtoupper($codigo));
            $detail['package']['allowed_actions'] = isset($filters['office_cd'])
                ? app(\App\Services\IpsStagePolicy::class)->actions($detail['package'], (int) $filters['office_cd']) : [];
            return ['data' => $detail];
        });
    }

    public function catalog(IpsRepository $ips)
    {
        return $this->respond(fn () => ['data' => $ips->catalog()]);
    }

    public function deliveryCatalog(IpsRepository $ips)
    {
        return $this->respond(fn () => ['data' => $ips->deliveryCatalog()]);
    }

    public function users(Request $request, IpsRepository $ips)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->respond(fn () => $ips->users($filters));
    }

    public function user(int $pid, IpsRepository $ips)
    {
        return $this->respond(fn () => ['data' => $ips->user($pid)]);
    }

    public function createUser(Request $request, IpsRepository $ips)
    {
        $data = $request->validate([
            'user_fid' => ['required', 'string', 'max:256', 'regex:/^[A-Za-z0-9._-]+$/'],
            'user_name' => ['required', 'string', 'max:256'],
            'user_domain' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/'],
            'office_cd' => ['nullable', 'integer', 'min:1', 'max:32767'],
            'email' => ['nullable', 'email', 'max:256'],
        ]);

        return $this->respond(fn () => ['data' => $ips->createUser($data)], 201);
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

    private function respond(callable $callback, int $status = 200)
    {
        try {
            return response()->json($callback(), $status)->header('Cache-Control', 'no-store');
        } catch (IpsOperationException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        } catch (Throwable $e) {
            Log::error('IPS query unavailable', ['exception_type' => $e::class]);

            return response()->json(['message' => 'No se pudo consultar IPS. Intente nuevamente.'], 503);
        }
    }
}
