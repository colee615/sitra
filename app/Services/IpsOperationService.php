<?php

namespace App\Services;

use App\Exceptions\IpsOperationException;
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
        $hash = hash('sha256', json_encode($this->canonicalize(['action' => $action, 'input' => $input]), JSON_THROW_ON_ERROR));
        $id = (string) Str::uuid();
        // This reservation is committed BEFORE touching IPS. A process crash leaves a durable
        // processing row which blocks blind replay across the two independent databases.
        DB::table('ips_operations')->insertOrIgnore([
            'id' => $id, 'user_id' => $userId, 'key_hash' => $key, 'request_hash' => $hash,
            'action' => $action, 'codigo' => $input['codigo'], 'event' => $input['event'],
            'status' => 'processing', 'created_at' => now(), 'updated_at' => now(),
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

        return [
            'operation_id' => $row->id, 'codigo' => $row->codigo, 'event' => $row->event,
            'status' => $row->status, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
            'result' => $row->result ? json_decode($row->result, true) : null,
        ];
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
