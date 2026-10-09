<?php

namespace App\Services;

use App\Exceptions\IpsOperationException;
use App\Support\IpsEventTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class IpsOperationService
{
    public function __construct(private readonly IpsWorkflowService $workflow) {}

    public function submit(int $userId, string $action, array $input): array
    {
        $key = hash('sha256', $input['idempotency_key']);
        unset($input['idempotency_key']);
        $effectiveTime = Carbon::parse($input['occurred_at']);
        $registeredAtUtc = now('UTC');
        if ($effectiveTime->lt($registeredAtUtc->copy()->subMinutes(2)) && empty($input['time_reason'])) {
            throw new IpsOperationException('Indique por qué se registra un evento con más de dos minutos de retraso.', 422);
        }
        $hash = hash('sha256', json_encode($this->canonicalize(['action' => $action, 'input' => $input]), JSON_THROW_ON_ERROR));
        $id = (string) Str::uuid();
        // This reservation is committed BEFORE touching IPS. A process crash leaves a durable
        // processing row which blocks blind replay across the two independent databases.
        DB::table('ips_operations')->insertOrIgnore([
            'id' => $id, 'user_id' => $userId, 'key_hash' => $key, 'request_hash' => $hash,
            'action' => $action, 'codigo' => $input['codigo'], 'event' => $input['event'],
            'status' => 'processing', 'created_at' => now(), 'updated_at' => now(),
            'event_at_utc' => $effectiveTime->copy()->utc()->toIso8601String(),
            'event_local_offset_minutes' => $effectiveTime->utcOffset(),
            'registered_at_utc' => $registeredAtUtc->toIso8601String(),
            'delivery_mode' => $input['delivery_mode'] ?? null,
            'time_reason' => $input['time_reason'] ?? null,
            'customs_return_event_at_utc' => !empty($input['customs_return_confirmed'])
                ? $effectiveTime->copy()->subSecond()->utc()->toIso8601String()
                : null,
        ]);
        $operation = DB::table('ips_operations')->where('user_id', $userId)->where('key_hash', $key)->first();
        if (! $operation) {
            throw new IpsOperationException('No se pudo reservar la operación. No se envió a IPS.', 503);
        }
        if (! hash_equals($operation->request_hash, $hash)) {
            throw new IpsOperationException('Idempotency-Key ya utilizado con otros datos.', 409);
        }
        if ($operation->id !== $id) {
            if ($operation->status === 'succeeded' || $operation->status === 'rejected') {
                return ['body' => json_decode($operation->result, true), 'status' => (int) $operation->http_status, 'replayed' => true];
            }

            return ['body' => ['operation_id' => $operation->id, 'status' => $operation->status, 'message' => 'Operación en curso o pendiente de verificación. Consulte su estado; no use otra clave.'], 'status' => 409, 'replayed' => true];
        }
        try {
            $result = $this->workflow->execute($action, $input);
            $body = ['operation_id' => $id, 'status' => 'succeeded', 'data' => $result];
            if (! empty($result['customs_return'])) {
                $body['message'] = 'Retorno de Aduana y entrega registrados en IPS.';
            }
            $status = $action === 'create' ? 201 : 200;
            $this->finish($id, 'succeeded', $body, $status);
        } catch (IpsOperationException $e) {
            $body = ['operation_id' => $id, 'status' => 'rejected', 'message' => $e->getMessage()];
            $status = $e->status;
            $this->finish($id, 'rejected', $body, $status);
        } catch (Throwable $e) {
            // Includes a lost SQL COMMIT acknowledgement or a failed primary-DB journal update.
            // Never claim success or automatically resubmit an uncertain operation.
            Log::error('IPS operation requires reconciliation', ['operation_id' => $id, 'exception_type' => $e::class]);
            $body = ['operation_id' => $id, 'status' => 'uncertain', 'message' => 'No se pudo confirmar el resultado. Consulte la operación antes de volver a enviar.'];
            $status = 503;
            try {
                $this->finish($id, 'uncertain', $body, $status);
            } catch (Throwable) {
                // The durable processing reservation remains and prevents replay.
            }
        }
        if (($body['status'] ?? '') === 'succeeded') {
            try {
                app(IpsPackageTotalsCache::class)->invalidate();
            } catch (Throwable $e) {
                Log::warning('IPS package total invalidation failed', ['operation_id' => $id, 'exception_type' => $e::class]);
            }
            try {
                app(TrackingSearchCacheService::class)->invalidate($input['codigo']);
                if (! empty($result['codigo']) && $result['codigo'] !== $input['codigo']) {
                    app(TrackingSearchCacheService::class)->invalidate($result['codigo']);
                }
                if (! empty($result['local_id'])) {
                    app(TrackingSearchCacheService::class)->invalidate($result['local_id']);
                }
            } catch (Throwable $e) {
                Log::warning('IPS tracking cache invalidation failed', ['operation_id' => $id, 'exception_type' => $e::class]);
            }
        }

        return ['body' => $body, 'status' => $status, 'replayed' => false];
    }

    public function find(int $userId, string $id): array
    {
        $row = DB::table('ips_operations')->where('user_id', $userId)->where('id', $id)->first();
        if (! $row) {
            throw new IpsOperationException('Operación no encontrada.', 404);
        }

        $eventTime = IpsEventTime::present(
            $row->event_at_utc ?? null,
            isset($row->event_local_offset_minutes) ? ((int) $row->event_local_offset_minutes / 60) : null
        );

        return [
            'operation_id' => $row->id, 'codigo' => $row->codigo, 'event' => $row->event,
            'status' => $row->status, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
            'event_at_utc' => $row->event_at_utc ?? null,
            'event_at_local' => $eventTime['event_at_local'],
            'event_timezone_label' => $eventTime['event_timezone_label'],
            'event_local_offset_minutes' => $row->event_local_offset_minutes ?? null,
            'registered_at_utc' => $row->registered_at_utc ?? null,
            'delivery_mode' => $row->delivery_mode ?? null,
            'time_reason' => $row->time_reason ?? null,
            'customs_return_event_at_utc' => $row->customs_return_event_at_utc ?? null,
            'result' => $row->result ? json_decode($row->result, true) : null,
        ];
    }

    /** @return array<string, string> Successful EMI delivery mode keyed by tracking code. */
    public function deliveryModesForCodes(array $codes): array
    {
        $codes = collect($codes)
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter(fn (string $code) => $code !== '')
            ->unique()
            ->values();
        if ($codes->isEmpty()) {
            return [];
        }

        try {
            return DB::table('ips_operations')
                ->where('event', 'EMI')
                ->where('status', 'succeeded')
                ->whereNotNull('delivery_mode')
                ->whereIn(DB::raw('UPPER(codigo)'), $codes->all())
                ->orderByDesc('registered_at_utc')
                ->get(['codigo', 'delivery_mode'])
                ->unique(fn ($row) => strtoupper(trim((string) $row->codigo)))
                ->mapWithKeys(fn ($row) => [strtoupper(trim((string) $row->codigo)) => (string) $row->delivery_mode])
                ->all();
        } catch (Throwable) {
            // Tracking must remain available if the audit migration is not installed yet.
            return [];
        }
    }

    private function finish(string $id, string $state, array $body, int $status): void
    {
        DB::table('ips_operations')->where('id', $id)->update([
            'status' => $state, 'result' => json_encode($body, JSON_THROW_ON_ERROR),
            'http_status' => $status, 'updated_at' => now(),
        ]);
    }

    private function canonicalize(array $value): array
    {
        ksort($value);
        foreach ($value as &$entry) {
            if (is_array($entry)) {
                $entry = $this->canonicalize($entry);
            }
        }

        return $value;
    }
}
