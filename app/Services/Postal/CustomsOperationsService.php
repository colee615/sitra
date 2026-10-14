<?php

namespace App\Services\Postal;

use App\Exceptions\IpsOperationException;
use App\Services\IpsOperationService;
use App\Services\IpsRepository;
use App\Services\IpsStagePolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Customs workbench: IPS/CDS lookups plus an auditable Correos case workflow. */
final class CustomsOperationsService
{
    public function __construct(
        private readonly ReceptacleSearchService $receptacles,
        private readonly CustomsRemittanceBuilder $remittances,
        private readonly IpsRepository $ips,
        private readonly IpsOperationService $operations,
    ) {}

    public function inspectDispatch(string $code): array
    {
        $result = $this->receptacles->search($code);
        if (($result['search_type'] ?? null) !== 's8') {
            throw new IpsOperationException('El código no corresponde a un despacho S8.', 422);
        }

        return $result;
    }

    public function inspectIdentifier(string $code, ?int $officeCd = null): array
    {
        $result = $this->receptacles->search($code);
        return ($result['search_type'] ?? null) === 's8'
            ? ['type'=>'dispatch', 'result'=>$result]
            : ['type'=>'receptacle', 'result'=>$this->inspectReceptacle($code, $officeCd)];
    }

    public function inspectReceptacle(string $code, ?int $officeCd = null): array
    {
        $result = $this->receptacles->search($code);
        if (($result['search_type'] ?? null) === 's8') {
            throw new IpsOperationException('El código corresponde a un despacho S8; se requiere una saca S9.', 422);
        }
        $receptacleCount = count($result['receptacles'] ?? []);
        if ($receptacleCount === 0) {
            throw new IpsOperationException('No se encontró la saca S9 en IPS.', 404);
        }
        if ($receptacleCount !== 1) {
            throw new IpsOperationException('El código identifica más de una saca; proporciona un S9 único.', 422);
        }
        if (!empty($result['truncated'])) {
            throw new IpsOperationException('La consulta del marbete está incompleta. No se habilitó el trámite.', 409);
        }

        $bag = $result['receptacles'][0];
        $packages = collect($result['items'] ?? []);
        $knownWeightPackages = $packages->filter(fn ($item) => is_numeric($item->MAILITM_WEIGHT ?? null));
        $declaredPackageCount = is_numeric($bag->RECPTCL_MAILITMS_NO ?? null)
            ? (int) $bag->RECPTCL_MAILITMS_NO : null;
        $linkedPackageCount = $packages->count();
        $isTruncated = (bool) ($result['truncated'] ?? false);
        $reconciliation = [
            'declared_count' => $declaredPackageCount,
            'linked_count' => $linkedPackageCount,
            'count_matches' => $declaredPackageCount === null || $isTruncated
                ? null : $declaredPackageCount === $linkedPackageCount,
            'item_weight_total_kg' => (float) $knownWeightPackages->sum(fn ($item) => (float) $item->MAILITM_WEIGHT),
            'items_without_weight' => $packages->count() - $knownWeightPackages->count(),
            'bag_weight_kg' => is_numeric($bag->RECPTCL_WEIGHT ?? null) ? (float) $bag->RECPTCL_WEIGHT : null,
            'can_compare' => !$isTruncated,
        ];
        $codes = $packages->flatMap(fn ($item) => array_filter([
            ShipmentCode::normalize((string) ($item->MAILITM_FID ?? '')),
            ShipmentCode::normalize((string) ($item->MAILITM_LOCAL_ID ?? '')),
        ]))->unique()->values()->all();

        $cdsAvailable = (bool) config('postal.cds_enabled');
        try {
            $cds = $cdsAvailable
                ? $this->remittances->indexForPackages($codes)
                : ['packages'=>[], 'missing_codes'=>$codes, 'without_declaration_codes'=>[], 'deleted_declaration_codes'=>[], 'unconfirmed_codes'=>[], 'declaration_count'=>0, 'truncated'=>false];
        } catch (\Throwable $exception) {
            report($exception);
            $cdsAvailable = false;
            $cds = ['packages'=>[], 'missing_codes'=>[], 'without_declaration_codes'=>[], 'deleted_declaration_codes'=>[], 'unconfirmed_codes'=>[], 'declaration_count'=>0, 'truncated'=>false];
        }
        $cds['enabled'] = (bool) config('postal.cds_enabled');
        $cds['available'] = $cdsAvailable;
        if (!$cds['enabled'] || !$cdsAvailable || !empty($cds['truncated']) || !empty($cds['declarations_truncated'])) {
            $cds['complete'] = false;
        } else {
            $cds['complete'] = true;
        }

        $declarationsByCode = [];
        foreach ($cds['packages'] ?? [] as $group) {
            foreach ($group['matched_codes'] ?? [] as $matchedCode) {
                $declarationsByCode[ShipmentCode::normalize((string) $matchedCode)] = $group;
            }
        }

        $officeData = null;
        if ($officeCd !== null) {
            $officeData = $this->ips->receptacleDetail((string) $bag->RECPTCL_FID, $officeCd)['receptacle'];
        }

        $session = DB::table('customs_work_sessions')
            ->where('receptacle_pid', (string) $bag->RECPTCL_PID)
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('opened_at')->first();
        $receptacleActions = DB::table('customs_receptacle_actions')
            ->where('receptacle_pid', (string) $bag->RECPTCL_PID)->orderByDesc('occurred_at')->limit(100)->get();

        $sessionItems = collect();
        $scans = collect();
        $actions = collect();
        if ($session) {
            $sessionItems = DB::table('customs_session_items')->where('session_id', $session->id)
                ->orderBy('postal_code')->get()->keyBy('ips_mailitm_pid');
            $scans = DB::table('customs_physical_scans')->where('session_id', $session->id)
                ->orderByDesc('scanned_at')->limit(100)->get();
            $actions = DB::table('customs_case_actions')->where('session_id', $session->id)
                ->orderByDesc('occurred_at')->limit(200)->get();
        }

        $dispatch = !empty($bag->DESPTCH_PID) ? [
            'pid' => (string) $bag->DESPTCH_PID,
            'code' => $bag->DESPTCH_FID ?? null,
            'origin' => $bag->ORIG_OFFICE_FCD ?? null,
            'destination' => $bag->DEST_OFFICE_FCD ?? null,
            'departure_at' => $bag->DESPTCH_DEPARTURE_DT ?? null,
            'sacks_declared' => is_numeric($bag->DESPTCH_RECPTCLS_NO ?? null)
                ? (int) $bag->DESPTCH_RECPTCLS_NO : null,
            'weight_kg' => is_numeric($bag->DESPTCH_WEIGHT ?? null) ? (float) $bag->DESPTCH_WEIGHT : null,
            'transport' => $bag->CONVEYANCE_TYPE_CD ?? null,
        ] : null;

        $rows = $packages->map(function ($item) use ($declarationsByCode, $sessionItems, $officeCd) {
            $code = ShipmentCode::normalize((string) ($item->MAILITM_FID ?: $item->MAILITM_LOCAL_ID ?: ''));
            $localCode = ShipmentCode::normalize((string) ($item->MAILITM_LOCAL_ID ?? ''));
            $group = $declarationsByCode[$code] ?? ($localCode !== '' ? ($declarationsByCode[$localCode] ?? null) : null);
            $sessionItem = $sessionItems->get((string) ($item->MAILITM_PID ?? ''));
            $allowedActions = [];
            if ($sessionItem && $sessionItem->scan_status === 'scanned' && $officeCd !== null) {
                try {
                    $current = $this->currentPackage($code);
                    $allowedActions = app(IpsStagePolicy::class)->actions($current, $officeCd);
                    if ((int) ($current['event_cd'] ?? 0) !== 34) {
                        $allowedActions = array_values(array_diff($allowedActions, ['CUSTOMS_RETURN']));
                    }
                } catch (\Throwable) {
                    // A current IPS state is required to show action controls.
                }
            }
            return [
                'pid'=>(string) ($item->MAILITM_PID ?? ''), 'code'=>$code,
                'local_code'=>$localCode, 'weight_kg'=>$item->MAILITM_WEIGHT ?? null,
                'class_code'=>$item->MAIL_CLASS_CD ?? null,
                'class_name'=>$item->MAIL_CLASS_NAME ?? null,
                'origin_country_code'=>$item->ORIG_COUNTRY_CD ?? null,
                'origin_country'=>$item->ORIGIN_COUNTRY ?? null,
                'destination_country'=>$item->DEST_COUNTRY ?? null,
                'postal_status_code'=>$item->POSTAL_STATUS_CD ?? null,
                'postal_status'=>$item->POSTAL_STATUS_NAME ?? null,
                'event_name'=>$item->EVENT_NAME_ES ?: ($item->EVENT_NAME ?? null),
                'event_cd'=>(int) ($item->EVT_TYPE_CD ?? 0), 'event_at'=>$item->EVT_GMT_DT ?? null,
                'office_cd'=>isset($item->EVT_OFFICE_CD) ? (int) $item->EVT_OFFICE_CD : null,
                'state_cd'=>isset($item->STATE_IND_CD) ? (int) $item->STATE_IND_CD : null,
                'origin_country_cd'=>$item->ORIG_COUNTRY_CD ?? null,
                'destination_country'=>$item->DEST_COUNTRY ?? null,
                'declaration_group'=>$group,
                'scan_status'=>$sessionItem->scan_status ?? 'pending',
                'scanned_at'=>$sessionItem->scanned_at ?? null,
                'allowed_actions'=>$allowedActions,
            ];
        })->values();

        return [
            'identifier'=>strtoupper(trim($code)), 'receptacle'=>$bag,
            'code_analysis'=>$result['code_analysis'] ?? null,
            'summary'=>[
                'package_count'=>$linkedPackageCount,
                'declared_package_count'=>$declaredPackageCount,
                'count_matches'=>$reconciliation['count_matches'],
                'reconciliation'=>$reconciliation,
            ],
            'dispatch'=>$dispatch,
            'receptacle_events'=>$result['events'] ?? [],
            'packages'=>$rows,
            'manifests'=>$result['manifests'] ?? [],
            'cds'=>$cds,
            'office'=>$officeData,
            'session'=>$session,
            'session_items'=>$sessionItems->values(),
            'scans'=>$scans,
            'actions'=>$actions,
            'receptacle_actions'=>$receptacleActions,
            'truncated'=>(bool) ($result['truncated'] ?? false),
        ];
    }

    public function openSession(string $code, int $officeCd, int $userId, string $source = 'correos_manual'): object
    {
        $this->ips->assertReady();
        $lookup = $this->receptacles->search($code);
        if (($lookup['search_type'] ?? null) === 's8' || count($lookup['receptacles'] ?? []) !== 1) {
            throw new IpsOperationException('Busca un marbete S9 único antes de abrir el expediente.', 422);
        }
        if (!empty($lookup['truncated'])) {
            throw new IpsOperationException('La consulta de la saca está incompleta.', 409);
        }
        $bag = $lookup['receptacles'][0];
        $detail = $this->ips->receptacleDetail((string) $bag->RECPTCL_FID, $officeCd)['receptacle'];
        if ((int) $detail['event_cd'] !== 131 || (int) $detail['office_cd'] !== $officeCd) {
            throw new IpsOperationException('Primero registra en IPS la recepción y apertura física de esta saca en la oficina.', 409);
        }
        if (!config('postal.cds_enabled')) {
            throw new IpsOperationException('CDS no está habilitado; no se puede abrir un expediente aduanero verificable.', 503);
        }

        $packageRows = collect($lookup['items'] ?? []);
        if ($packageRows->isEmpty()) {
            throw new IpsOperationException('IPS no tiene paquetes enlazados a este marbete; no se abrió un expediente vacío.', 409);
        }
        $dispatchCode = trim((string) ($packageRows->first()->DESPTCH_FID ?? ''));
        $sessionId = (string) Str::uuid();

        return DB::transaction(function () use ($bag, $officeCd, $userId, $packageRows, $dispatchCode, $sessionId, $source) {
            $existing = DB::table('customs_work_sessions')->where('receptacle_pid', (string) $bag->RECPTCL_PID)
                ->lockForUpdate()->first();
            if ($existing) {
                if ($existing->status !== 'open') throw new IpsOperationException('Este marbete ya tiene un expediente cerrado.', 409);
                return $existing;
            }

            $now = now();
            DB::table('customs_work_sessions')->insertOrIgnore([
                'id'=>$sessionId, 'dispatch_code'=>$dispatchCode ?: null,
                'receptacle_pid'=>(string) $bag->RECPTCL_PID, 'receptacle_code'=>trim((string) $bag->RECPTCL_FID),
                'office_cd'=>$officeCd, 'status'=>'open', 'operator_mode'=>$source,
                'opened_by'=>$userId, 'opened_at'=>$now, 'created_at'=>$now, 'updated_at'=>$now,
            ]);
            $session = DB::table('customs_work_sessions')->where('receptacle_pid', (string) $bag->RECPTCL_PID)->first();
            if (!$session || $session->id !== $sessionId) {
                if (!$session || $session->status !== 'open') throw new IpsOperationException('No se pudo abrir la sesión de trabajo.', 409);
                return $session;
            }
            foreach ($packageRows as $item) {
                DB::table('customs_session_items')->insert([
                    'id'=>(string) Str::uuid(), 'session_id'=>$sessionId,
                    'ips_mailitm_pid'=>(string) $item->MAILITM_PID,
                    'postal_code'=>ShipmentCode::normalize((string) ($item->MAILITM_FID ?? '')) ?: null,
                    'local_code'=>ShipmentCode::normalize((string) ($item->MAILITM_LOCAL_ID ?? '')) ?: null,
                    'scan_status'=>'pending', 'created_at'=>$now, 'updated_at'=>$now,
                ]);
            }

            return DB::table('customs_work_sessions')->where('id', $sessionId)->first();
        });
    }

    public function recordReceptacleEvent(string $code, string $event, int $officeCd, int $userId, string $idempotencyKey, string $source = 'correos_manual'): array
    {
        if (!in_array($event, ['RECEPTACLE_RECEIVE', 'RECEPTACLE_OPEN'], true)) {
            throw new IpsOperationException('Solo se permiten recepción y apertura física de la saca.', 422);
        }
        $code = strtoupper(trim($code));
        $detail = $this->ips->receptacleDetail($code, $officeCd)['receptacle'];
        $requestData = ['code'=>$code, 'event'=>$event, 'office_cd'=>$officeCd, 'source'=>$source];
        $requestHash = hash('sha256', json_encode($this->canonicalize($requestData), JSON_THROW_ON_ERROR));
        $idempotencyHash = hash('sha256', $idempotencyKey);
        $existing = DB::table('customs_receptacle_actions')->where('receptacle_pid', $detail['id'])
            ->where('idempotency_hash', $idempotencyHash)->first();
        if ($existing && (!hash_equals((string) $existing->request_hash, $requestHash)
            || (int) $existing->actor_user_id !== $userId)) {
            throw new IpsOperationException('La clave ya fue usada con otro usuario o datos.', 409);
        }
        if ($existing && in_array($existing->status, ['succeeded', 'rejected'], true)) {
            $body = json_decode((string) $existing->result, true) ?: [];
            if ($existing->status === 'succeeded' && $event === 'RECEPTACLE_OPEN') {
                try {
                    $body['customs_session'] = (array) $this->openSession($code, $officeCd, $userId, $source);
                    unset($body['customs_session_error']);
                } catch (IpsOperationException $exception) {
                    $body['customs_session_error'] = $exception->getMessage();
                }
            }
            return ['body'=>$body, 'status'=>(int) ($body['status'] === 'rejected' ? 409 : 200), 'replayed'=>true];
        }
        if ($existing) {
            $storedInput = json_decode((string) $existing->ips_request, true);
            if (!$storedInput) throw new IpsOperationException('La operación de saca requiere conciliación antes de reintentar.', 409);
            return $this->submitReceptacleAction($existing, $storedInput, $userId, $source, true);
        }
        if (!in_array($event, $detail['allowed_actions'], true)) {
            throw new IpsOperationException('La etapa actual de IPS no habilita esta acción en la oficina seleccionada.', 409);
        }

        $actionId = (string) Str::uuid();
        $ipsInput = [
            'codigo'=>$code, 'event'=>$event, 'office_cd'=>$officeCd,
            'expected_event_cd'=>(int) $detail['event_cd'], 'expected_event_at'=>$detail['event_at'],
            'occurred_at'=>Carbon::now(config('ips.operation_timezone', 'America/La_Paz'))->format('Y-m-d\TH:i:sP'),
            'physical_receipt_confirmed'=>true, 'idempotency_key'=>'customs-bag:'.$actionId,
            'external_actor_id'=>'sitra-user:'.$userId,
        ];
        $now = now();
        DB::table('customs_receptacle_actions')->insertOrIgnore([
            'id'=>$actionId, 'receptacle_pid'=>$detail['id'], 'receptacle_code'=>$code,
            'office_cd'=>$officeCd, 'event'=>$event, 'source'=>$source, 'status'=>'processing',
            'idempotency_hash'=>$idempotencyHash, 'request_hash'=>$requestHash,
            'ips_request'=>json_encode($ipsInput, JSON_THROW_ON_ERROR), 'actor_user_id'=>$userId,
            'occurred_at'=>now(config('ips.operation_timezone', 'America/La_Paz')),
            'created_at'=>$now, 'updated_at'=>$now,
        ]);
        $actionRow = DB::table('customs_receptacle_actions')->where('receptacle_pid', $detail['id'])
            ->where('idempotency_hash', $idempotencyHash)->first();
        if (!$actionRow || !hash_equals((string) $actionRow->request_hash, $requestHash)) {
            throw new IpsOperationException('La clave ya fue usada con otros datos.', 409);
        }
        return $this->submitReceptacleAction($actionRow,
            json_decode((string) $actionRow->ips_request, true) ?: $ipsInput,
            $userId, $source, $actionRow->id !== $actionId);
    }

    private function submitReceptacleAction(object $actionRow, array $ipsInput, int $userId, string $source, bool $replayed): array
    {
        try {
            $operation = $this->operations->submit($userId, 'receptacle', $ipsInput);
            $body = $operation['body'];
            $status = (string) ($body['status'] ?? 'uncertain');
            DB::table('customs_receptacle_actions')->where('id', $actionRow->id)->update([
                'status'=>$status, 'ips_operation_id'=>$body['operation_id'] ?? null,
                'result'=>json_encode($body, JSON_THROW_ON_ERROR), 'updated_at'=>now(),
            ]);
            if ($status === 'succeeded' && $ipsInput['event'] === 'RECEPTACLE_OPEN') {
                try {
                    $body['customs_session'] = (array) $this->openSession(
                        $ipsInput['codigo'], (int) $ipsInput['office_cd'], $userId, $source
                    );
                    unset($body['customs_session_error']);
                } catch (IpsOperationException $exception) {
                    $body['customs_session_error'] = $exception->getMessage();
                }
                DB::table('customs_receptacle_actions')->where('id', $actionRow->id)->update([
                    'result'=>json_encode($body, JSON_THROW_ON_ERROR), 'updated_at'=>now(),
                ]);
            }
            return ['body'=>$body, 'status'=>(int) ($operation['status'] ?? 200),
                'replayed'=>$replayed || (bool) ($operation['replayed'] ?? false)];
        } catch (\Throwable $exception) {
            DB::table('customs_receptacle_actions')->where('id', $actionRow->id)->update([
                'status'=>'uncertain', 'result'=>json_encode(['message'=>'No se pudo confirmar la operación. Consulte antes de reenviar.']),
                'updated_at'=>now(),
            ]);
            throw $exception;
        }
    }

    public function recordPhysicalScan(string $sessionId, string $scannedCode, int $userId): array
    {
        $code = ShipmentCode::normalize($scannedCode);
        if ($code === '' || strlen($code) > 35) throw new IpsOperationException('Código de paquete inválido.', 422);

        return DB::transaction(function () use ($sessionId, $code, $userId) {
            $session = DB::table('customs_work_sessions')->where('id', $sessionId)->lockForUpdate()->first();
            if (!$session || $session->status !== 'open') throw new IpsOperationException('El expediente no está abierto.', 409);
            $matches = DB::table('customs_session_items')->where('session_id', $sessionId)
                ->where(fn ($query) => $query->where('postal_code', $code)->orWhere('local_code', $code))
                ->lockForUpdate()->get();
            $matchedItem = $matches->count() === 1 ? $matches->first() : null;
            $result = $matches->count() > 1 ? 'ambiguous'
                : (!$matchedItem ? 'unexpected' : ($matchedItem->scan_status === 'scanned' ? 'duplicate' : 'expected'));
            $now = now();
            DB::table('customs_physical_scans')->insert([
                'id'=>(string) Str::uuid(), 'session_id'=>$sessionId,
                'session_item_id'=>$matchedItem?->id, 'scanned_code'=>$code, 'result'=>$result,
                'actor_user_id'=>$userId, 'scanned_at'=>$now, 'created_at'=>$now, 'updated_at'=>$now,
            ]);
            if ($matchedItem && $result === 'expected') {
                DB::table('customs_session_items')->where('id', $matchedItem->id)->update([
                    'scan_status'=>'scanned', 'scanned_by'=>$userId, 'scanned_at'=>$now, 'updated_at'=>$now,
                ]);
            }

            return ['result'=>$result, 'code'=>$code, 'session_id'=>$sessionId,
                'message'=>match ($result) {
                    'expected'=>'Paquete cotejado con el contenido enlazado en IPS.',
                    'duplicate'=>'Este paquete ya se había escaneado en esta sesión.',
                    'ambiguous'=>'El código aparece en más de un paquete de la saca; revisa el identificador.',
                    default=>'El paquete no está enlazado a esta saca en IPS.',
                }];
        });
    }

    /** Submit a package-level IPS customs event and keep its Correos audit record separate. */
    public function recordCaseAction(string $sessionId, string $code, string $action, array $input, int $userId, string $source = 'correos_manual'): array
    {
        $action = strtolower($action);
        $event = match ($action) {
            'present' => 'CUSTOMS_PRESENT', 'decision' => 'CUSTOMS_INFO', 'return' => 'CUSTOMS_RETURN',
            default => throw new IpsOperationException('Acción aduanera desconocida.', 422),
        };
        $session = DB::table('customs_work_sessions')->where('id', $sessionId)->first();
        if (!$session || $session->status !== 'open') throw new IpsOperationException('El expediente no está abierto.', 409);
        $normalizedCode = ShipmentCode::normalize($code);
        $item = DB::table('customs_session_items')->where('session_id', $sessionId)
            ->where(fn ($query) => $query->where('postal_code', $normalizedCode)->orWhere('local_code', $normalizedCode))
            ->first();
        if (!$item || $item->scan_status !== 'scanned') {
            throw new IpsOperationException('Escanea y coteja físicamente el paquete antes de registrar el trámite.', 409);
        }
        $idempotencyKey = trim((string) ($input['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') throw new IpsOperationException('Falta la clave de idempotencia.', 422);
        $requestData = [
            'event'=>$event, 'codigo'=>$normalizedCode, 'office_cd'=>(int) $session->office_cd,
            'source'=>$source, 'decision_code'=>$input['customs_release_status_cd'] ?? null,
            'customs_office_cd'=>$input['customs_office_cd'] ?? null,
            'customs_office_type_cd'=>$input['customs_office_type_cd'] ?? null,
            'notes'=>trim((string) ($input['notes'] ?? '')),
            'evidence_reference'=>trim((string) ($input['evidence_reference'] ?? '')),
        ];
        $requestHash = hash('sha256', json_encode($this->canonicalize($requestData), JSON_THROW_ON_ERROR));
        $idempotencyHash = hash('sha256', $idempotencyKey);
        $existingAction = DB::table('customs_case_actions')->where('session_id', $sessionId)
            ->where('idempotency_hash', $idempotencyHash)->first();
        if ($existingAction && !hash_equals((string) $existingAction->request_hash, $requestHash)) {
            throw new IpsOperationException('La clave ya se utilizó con otros datos.', 409);
        }
        if ($existingAction && (int) $existingAction->actor_user_id !== $userId) {
            throw new IpsOperationException('La clave pertenece a otro usuario y no se puede reintentar con esta cuenta.', 409);
        }
        if ($existingAction && in_array($existingAction->status, ['succeeded', 'rejected'], true)) {
            return ['action_id'=>$existingAction->id, 'status'=>$existingAction->status,
                'result'=>json_decode((string) $existingAction->result, true), 'replayed'=>true];
        }
        if ($existingAction) {
            $storedInput = json_decode((string) $existingAction->ips_request, true);
            if (!$storedInput) throw new IpsOperationException('La operación quedó pendiente de conciliación.', 409);
            return $this->submitCaseAction($existingAction, $storedInput, $userId, true);
        }

        $officeCd = (int) $session->office_cd;
        $package = $this->currentPackage($normalizedCode);
        $allowedActions = app(IpsStagePolicy::class)->actions($package, $officeCd);
        if ($action === 'return' && (int) ($package['event_cd'] ?? 0) !== 34) {
            $allowedActions = array_values(array_diff($allowedActions, ['CUSTOMS_RETURN']));
        }
        if (!in_array($event, $allowedActions, true)) {
            throw new IpsOperationException('La etapa u oficina IPS no permite este evento para el paquete.', 409);
        }
        $declarationIds = [];
        if (config('postal.cds_enabled')) {
            try {
                $declarationIds = $this->declarationIdsForPackage($normalizedCode, (string) ($item->local_code ?? ''));
            } catch (IpsOperationException $exception) {
                if ($action !== 'present') throw $exception;
            }
        }
        if (in_array($action, ['decision', 'return'], true)) {
            if (!$declarationIds) {
                throw new IpsOperationException('No hay una declaración CDS activa y confirmada para asociar al trámite.', 409);
            }
        }
        $actionId = (string) Str::uuid();
        $ipsInput = [
            'codigo'=>$normalizedCode, 'event'=>$event, 'office_cd'=>$officeCd,
            'expected_event_cd'=>(int) $package['event_cd'], 'expected_event_at'=>$package['event_at'],
            'occurred_at'=>Carbon::now(config('ips.operation_timezone', 'America/La_Paz'))->format('Y-m-d\TH:i:sP'),
            'idempotency_key'=>'customs-case:'.$actionId,
            'external_actor_id'=>'sitra-user:'.$userId,
        ];
        if ($action === 'present') {
            $ipsInput['customs_office_cd'] = (int) ($input['customs_office_cd'] ?? 0);
            $ipsInput['customs_office_type_cd'] = (string) ($input['customs_office_type_cd'] ?? '');
        } elseif ($action === 'decision') {
            $ipsInput['customs_release_status_cd'] = (string) ($input['customs_release_status_cd'] ?? '');
        } else {
            $ipsInput['customs_return_confirmed'] = true;
            $ipsInput['physical_receipt_confirmed'] = true;
        }

        $now = now();
        DB::table('customs_case_actions')->insertOrIgnore([
            'id'=>$actionId, 'session_id'=>$sessionId, 'postal_code'=>$normalizedCode, 'action'=>$action,
            'source'=>$source, 'status'=>'processing', 'decision_code'=>$requestData['decision_code'],
            'notes'=>$requestData['notes'] ?: null, 'evidence_reference'=>$requestData['evidence_reference'] ?: null,
            'declaration_ids'=>json_encode($declarationIds),
            'idempotency_hash'=>$idempotencyHash, 'request_hash'=>$requestHash,
            'ips_request'=>json_encode($ipsInput, JSON_THROW_ON_ERROR),
            'actor_user_id'=>$userId, 'occurred_at'=>now(config('ips.operation_timezone', 'America/La_Paz')),
            'created_at'=>$now, 'updated_at'=>$now,
        ]);
        $caseAction = DB::table('customs_case_actions')->where('session_id', $sessionId)
            ->where('idempotency_hash', $idempotencyHash)->first();
        if (!$caseAction || !hash_equals((string) $caseAction->request_hash, $requestHash)) {
            throw new IpsOperationException('La clave ya se utilizó con otros datos.', 409);
        }
        if ($caseAction->status === 'succeeded' || $caseAction->status === 'rejected') {
            return ['action_id'=>$caseAction->id, 'status'=>$caseAction->status,
                'result'=>json_decode((string) $caseAction->result, true), 'replayed'=>true];
        }

        $storedInput = json_decode((string) $caseAction->ips_request, true) ?: $ipsInput;
        return $this->submitCaseAction($caseAction, $storedInput, $userId, $caseAction->id !== $actionId);
    }

    private function submitCaseAction(object $caseAction, array $ipsInput, int $userId, bool $replayed): array
    {
        try {
            $result = $this->operations->submit($userId, 'event', $ipsInput);
            $body = $result['body'];
            $status = (string) ($body['status'] ?? 'uncertain');
            DB::table('customs_case_actions')->where('id', $caseAction->id)->update([
                'status'=>$status, 'ips_operation_id'=>$body['operation_id'] ?? null,
                'result'=>json_encode($body, JSON_THROW_ON_ERROR), 'updated_at'=>now(),
            ]);
            return ['action_id'=>$caseAction->id, 'status'=>$status, 'result'=>$body,
                'replayed'=>$replayed || (bool) ($result['replayed'] ?? false)];
        } catch (\Throwable $exception) {
            DB::table('customs_case_actions')->where('id', $caseAction->id)->update([
                'status'=>'uncertain', 'result'=>json_encode(['message'=>'No se pudo confirmar el resultado. Consulta la operación antes de reintentar.']),
                'updated_at'=>now(),
            ]);
            throw $exception;
        }
    }

    private function declarationIdsForPackage(string $code, string $localCode): array
    {
        $codes = array_values(array_unique(array_filter([$code, ShipmentCode::normalize($localCode)])));
        try {
            $index = $this->remittances->indexForPackages($codes);
        } catch (\Throwable $exception) {
            report($exception);
            throw new IpsOperationException('CDS no respondió; no se pudo asociar una declaración al trámite.', 503);
        }
        if (!empty($index['truncated']) || !empty($index['declarations_truncated'])) {
            throw new IpsOperationException('La consulta CDS está incompleta; no se registró el trámite aduanero.', 409);
        }
        $row = collect($index['packages'] ?? [])->first(fn (array $group) => collect($group['matched_codes'] ?? [])
            ->intersect($codes)->isNotEmpty());
        return collect($row['declarations'] ?? [])
            ->reject(function (array $declaration) {
                $stage = mb_strtolower((string) ($declaration['workflow_stage'] ?? $declaration['state'] ?? ''), 'UTF-8');
                return str_contains($stage, 'deleted') || str_contains($stage, 'eliminad');
            })->pluck('id')->filter()->values()->all();
    }

    private function currentPackage(string $code): array
    {
        $item = $this->ips->find($code);
        if (!$item) throw new IpsOperationException('Paquete no encontrado en IPS.', 404);
        $this->ips->enrich(collect([$item]));
        $package = $this->ips->present($item);
        if (trim((string) ($package['destination_country'] ?? '')) === '') {
            $package['destination_country'] = app(CustomsDestinationResolver::class)->forIpsItem($item);
        }
        return $package;
    }

    private function canonicalize(array $data): array
    {
        ksort($data);
        foreach ($data as &$value) if (is_array($value)) $value = $this->canonicalize($value);
        return $data;
    }
}
