<?php

namespace App\Services\Postal;

use App\Exceptions\IpsOperationException;
use Illuminate\Support\Facades\DB;

/** Read-only PREDES pre-alerts for postal dispatches addressed to Bolivia. */
final class CustomsPrealertService
{
    public function __construct(private readonly CdsRepository $cds) {}

    /** List a bounded page of inbound PREDES dispatches, ordered by PREDES closing date. */
    public function index(string $from, string $to, int $limit = 20, ?string $cursor = null): array
    {
        $query = $this->dispatchQuery()
            ->whereBetween('d.DESPTCH_CLOSING_DATE', [$from.' 00:00:00', $to.' 23:59:59'])
            ->orderBy('d.DESPTCH_CLOSING_DATE')->orderBy('d.DESPTCH_PID');

        $cursorData = $cursor ? $this->decodeCursor($cursor, $from, $to) : null;
        if ($cursorData) {
            $query->where(function ($where) use ($cursorData) {
                $where->where('d.DESPTCH_CLOSING_DATE', '>', $cursorData['closed_at'])
                    ->orWhere(function ($tie) use ($cursorData) {
                        $tie->where('d.DESPTCH_CLOSING_DATE', '=', $cursorData['closed_at'])
                            ->where('d.DESPTCH_PID', '>', $cursorData['pid']);
                    });
            });
        }

        $rows = $query->limit($limit + 1)->get($this->dispatchColumns());
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit)->values();
        $summaries = $this->summaries($page);
        $last = $page->last();

        return [
            'filters' => [
                'destination_country_code' => 'BO',
                'predes_closing_date_from' => $from,
                'predes_closing_date_to' => $to,
            ],
            'source_status' => ['ips' => 'ok'],
            'pagination' => [
                'limit' => $limit,
                'has_more' => $hasMore,
                'next_cursor' => $hasMore && $last
                    ? $this->encodeCursor((string) $last->DESPTCH_CLOSING_DATE, (string) $last->DESPTCH_PID, $from, $to)
                    : null,
            ],
            'dispatches' => $page->map(function ($dispatch) use ($summaries) {
                $code = ShipmentCode::normalize((string) $dispatch->DESPTCH_FID);
                return [
                    'identifier' => $code,
                    'predes_closing_at' => $dispatch->DESPTCH_CLOSING_DATE,
                    'origin_ctci' => substr($code, 0, 6),
                    'destination_ctci' => substr($code, 6, 6),
                    'summary' => $summaries[(string) $dispatch->DESPTCH_PID] ?? $this->emptySummary(),
                    'detail_path' => '/v1/aduana/prealertas/'.$code,
                ];
            })->all(),
        ];
    }

    /** Return the complete PREDES S8 → S9 → S10 tree and any CDS records matched by S10. */
    public function show(string $identifier): array
    {
        $analysis = UpuDispatchIdentifier::parse($identifier);
        if (($analysis['type'] ?? null) !== 's8') {
            throw new IpsOperationException('El código de prealerta debe ser un despacho UPU S8.', 422);
        }

        $code = (string) $analysis['code'];
        $dispatch = $this->dispatchQuery()
            ->whereRaw('UPPER(RTRIM(LTRIM(d.DESPTCH_FID))) = ?', [$code])
            ->orderByDesc('d.DESPTCH_CLOSING_DATE')->orderByDesc('d.DESPTCH_PID')
            ->first($this->dispatchColumns());

        if (!$dispatch) {
            throw new IpsOperationException('No se encontró una prealerta PREDES S8 con destino a Bolivia.', 404);
        }

        return $this->buildDetail($dispatch, $analysis);
    }

    private function dispatchQuery()
    {
        // The destination CTCI is positions 7–12 in an S8; BO selects inbound dispatches.
        return DB::connection(config('ips.connection', 'ips_catalog'))
            ->table('dbo.N_EDI_DESPTCHS as d')
            ->whereNotNull('d.DESPTCH_CLOSING_DATE')
            ->whereRaw("UPPER(SUBSTRING(RTRIM(LTRIM(d.DESPTCH_FID)), 7, 2)) = 'BO'");
    }

    private function dispatchColumns(): array
    {
        return [
            'd.DESPTCH_PID', 'd.DESPTCH_FID', 'd.DESPTCH_CLOSING_DATE',
            'd.INWARD_PARCELS_WEIGHT', 'd.INWARD_PARCELS_NO',
        ];
    }

    private function summaries($dispatches): array
    {
        if ($dispatches->isEmpty()) return [];

        $db = DB::connection(config('ips.connection', 'ips_catalog'));
        $dispatchIds = $dispatches->pluck('DESPTCH_PID')->map(fn ($id) => (string) $id)->all();
        $sacks = $db->table('dbo.N_EDI_RECPTCLS')
            ->whereIn('DESPTCH_PID', $dispatchIds)
            ->get(['DESPTCH_PID', 'RECPTCL_PID', 'RECPTCL_MAILITMS_NO', 'RECPTCL_WEIGHT']);
        $receptacleIds = $sacks->pluck('RECPTCL_PID')->map(fn ($id) => (string) $id)->all();
        $items = $this->queryInChunks($receptacleIds, fn ($chunk) => $db->table('dbo.N_EDI_MAILITMS')
            ->whereIn('RECPTCL_PID', $chunk)->get(['RECPTCL_PID', 'MAILITM_FID']));
        $itemsBySack = $items->groupBy(fn ($item) => (string) $item->RECPTCL_PID);
        $byDispatch = [];

        foreach ($dispatchIds as $id) $byDispatch[$id] = $this->emptySummary();
        foreach ($sacks as $sack) {
            $key = (string) $sack->DESPTCH_PID;
            $declared = is_numeric($sack->RECPTCL_MAILITMS_NO) ? (int) $sack->RECPTCL_MAILITMS_NO : null;
            $itemRows = $itemsBySack->get((string) $sack->RECPTCL_PID, collect());
            $identified = $itemRows->filter(fn ($item) => trim((string) $item->MAILITM_FID) !== '')->count();
            $summary = &$byDispatch[$key];
            $summary['sacks_count']++;
            $summary['packages_identified'] += $identified;
            if (is_numeric($sack->RECPTCL_WEIGHT)) $summary['sacks_weight_total_kg'] += (float) $sack->RECPTCL_WEIGHT;
            else $summary['sacks_weight_complete'] = false;
            if ($declared === null) {
                $summary['packages_declared_complete'] = false;
            } else {
                $summary['packages_declared'] += $declared;
                if ($declared !== $identified) $summary['sacks_with_count_mismatch']++;
            }
            unset($summary);
        }

        foreach ($byDispatch as &$summary) {
            $summary['sacks_weight_total_kg'] = round($summary['sacks_weight_total_kg'], 3);
        }
        unset($summary);

        return $byDispatch;
    }

    private function emptySummary(): array
    {
        return [
            'sacks_count' => 0,
            'packages_declared' => 0,
            'packages_declared_complete' => true,
            'packages_identified' => 0,
            'sacks_weight_total_kg' => 0.0,
            'sacks_weight_complete' => true,
            'sacks_with_count_mismatch' => 0,
        ];
    }

    private function buildDetail(object $dispatch, array $analysis): array
    {
        $db = DB::connection(config('ips.connection', 'ips_catalog'));
        $dispatchPid = (string) $dispatch->DESPTCH_PID;
        $sackRows = $db->table('dbo.N_EDI_RECPTCLS')
            ->where('DESPTCH_PID', $dispatchPid)
            ->orderBy('RECPTCL_FID')->get([
                'RECPTCL_PID', 'RECPTCL_FID', 'RECPTCL_MAILITMS_NO', 'RECPTCL_WEIGHT',
                'MAIL_SUBCLASS_FCD', 'RECPTCL_CONTENT_FORMAT_CD', 'RECPTCL_SUB_TYPE_CD',
                'EXPORTED_IND', 'NET_WEIGHT_IND', 'EXEMPT_IND', 'OTHER_CHARACS',
                'PREDES_DISPATCH_DOCUMENT_INDICATOR',
            ]);
        $sackIds = $sackRows->pluck('RECPTCL_PID')->map(fn ($id) => (string) $id)->all();
        $itemRows = $this->queryInChunks($sackIds, fn ($chunk) => $db->table('dbo.N_EDI_MAILITMS')
            ->whereIn('RECPTCL_PID', $chunk)->orderBy('MAILITM_FID')
            ->get([
                'RECPTCL_PID', 'MAILITM_FID', 'MAIL_ITEM_CATEGORY_CD',
                'ASSOCIATED_SERVICE_CD', 'MAIL_SUBCLASS_FCD', 'DEST_COUNTRY_CD', 'MAIL_WEIGHT',
                'INSURED_SDR_VALUE', 'ORIG_COUNTRY_CD', 'DESTINATION_ID', 'HANDLING_CLASS',
                'ORIGIN_OPERATOR',
            ]));
        $itemsBySack = $itemRows->groupBy(fn ($item) => (string) $item->RECPTCL_PID);
        $arrival = $this->arrivalData($sackRows);

        $packageCodes = $itemRows->map(fn ($item) => ShipmentCode::normalize((string) ($item->MAILITM_FID ?? '')))
            ->filter()->unique()->values()->all();
        $cdsIndex = $this->cdsIndex($packageCodes);

        $sacks = [];
        $packageCountDeclared = 0;
        $packageCountDeclaredComplete = true;
        $packageCountIdentified = 0;
        $sackWeightTotal = 0.0;
        $sackWeightComplete = true;
        $sacksWithMismatch = 0;
        $receivedSacks = 0;
        $packagesWithDeclaration = 0;
        $declarationCount = 0;
        $countedDeclarationCodes = [];

        foreach ($sackRows as $sack) {
            $sackId = (string) $sack->RECPTCL_PID;
            $sackCode = ShipmentCode::normalize((string) $sack->RECPTCL_FID);
            $sackItems = $itemsBySack->get($sackId, collect());
            $declared = is_numeric($sack->RECPTCL_MAILITMS_NO) ? (int) $sack->RECPTCL_MAILITMS_NO : null;
            $identifiedRows = $sackItems->filter(fn ($item) => trim((string) $item->MAILITM_FID) !== '');
            $identified = $identifiedRows->count();
            $matches = $declared === null ? null : $declared === $identified;
            $packageCountIdentified += $identified;
            if (is_numeric($sack->RECPTCL_WEIGHT)) $sackWeightTotal += (float) $sack->RECPTCL_WEIGHT;
            else $sackWeightComplete = false;
            if ($declared === null) $packageCountDeclaredComplete = false;
            else $packageCountDeclared += $declared;
            if ($matches === false) $sacksWithMismatch++;
            $receipt = $arrival[$sackCode] ?? ['event' => null, 'info' => null];
            if ($receipt['event']) $receivedSacks++;

            $packages = [];
            foreach ($sackItems as $item) {
                $itemCode = ShipmentCode::normalize((string) ($item->MAILITM_FID ?? ''));
                $cdsData = $itemCode !== '' ? ($cdsIndex['by_code'][$itemCode] ?? null) : null;
                if ($cdsData) {
                    $cdsData['lookup_status'] = $cdsData['declarations']
                        ? 'declaration_available'
                        : (in_array($itemCode, $cdsIndex['uncertain_codes'], true)
                            ? 'not_confirmed' : 'registered_without_declaration');
                }
                if ($cdsData && $cdsData['declarations'] && !isset($countedDeclarationCodes[$itemCode])) {
                    $packagesWithDeclaration++;
                    $declarationCount += count($cdsData['declarations']);
                    $countedDeclarationCodes[$itemCode] = true;
                }
                $packages[] = [
                    'identifier' => $itemCode !== '' ? $itemCode : null,
                    'category_code' => $this->clean($item->MAIL_ITEM_CATEGORY_CD),
                    'associated_service_code' => $this->clean($item->ASSOCIATED_SERVICE_CD),
                    'class_code' => $this->clean($item->MAIL_SUBCLASS_FCD),
                    'origin_country_code' => $this->clean($item->ORIG_COUNTRY_CD),
                    'destination_country_code' => $this->clean($item->DEST_COUNTRY_CD),
                    'destination_id' => $this->clean($item->DESTINATION_ID),
                    'weight_kg' => is_numeric($item->MAIL_WEIGHT) ? (float) $item->MAIL_WEIGHT : null,
                    'insured_value_sdr' => is_numeric($item->INSURED_SDR_VALUE) ? (float) $item->INSURED_SDR_VALUE : null,
                    'handling_class' => $this->clean($item->HANDLING_CLASS),
                    'origin_operator' => $this->clean($item->ORIGIN_OPERATOR),
                    'cds' => $cdsData ?? $this->missingCdsData($itemCode, $cdsIndex),
                ];
            }

            $sacks[] = [
                'identifier' => $sackCode,
                'number_of_items_declared' => $declared,
                'items_identified' => $identified,
                'count_matches' => $matches,
                'weight_kg' => is_numeric($sack->RECPTCL_WEIGHT) ? (float) $sack->RECPTCL_WEIGHT : null,
                'class_code' => $this->clean($sack->MAIL_SUBCLASS_FCD),
                'content_format_code' => $this->clean($sack->RECPTCL_CONTENT_FORMAT_CD),
                'subtype_code' => $this->clean($sack->RECPTCL_SUB_TYPE_CD),
                'exported_indicator' => $this->clean($sack->EXPORTED_IND),
                'net_weight_indicator' => $this->clean($sack->NET_WEIGHT_IND),
                'exempt_indicator' => $this->clean($sack->EXEMPT_IND),
                'dispatch_document_indicator' => $this->clean($sack->PREDES_DISPATCH_DOCUMENT_INDICATOR),
                'other_characteristics' => $this->clean($sack->OTHER_CHARACS),
                'physical_receipt' => $this->presentReceipt($receipt),
                'packages' => $packages,
            ];
        }

        $legs = $this->transportLegs($dispatchPid);
        $sacksCount = count($sacks);
        $arrivalStatus = match (true) {
            $sacksCount === 0 => 'not_applicable',
            $receivedSacks === 0 => 'not_recorded_in_ips',
            $receivedSacks === $sacksCount => 'received_in_ips',
            default => 'partially_recorded_in_ips',
        };
        $cdsStatus = $cdsIndex['status'];
        $declarationCoverage = $cdsStatus !== 'ok'
            ? ($packageCodes ? $cdsStatus : 'not_applicable')
            : (!$packageCodes
                ? 'not_applicable'
                : ($cdsIndex['truncated']
                    ? 'incomplete_lookup'
                    : ($packagesWithDeclaration === 0
                        ? 'none_found'
                        : ($packagesWithDeclaration === count($packageCodes) ? 'complete' : 'partial'))));

        return [
            'identifier' => ShipmentCode::normalize((string) $dispatch->DESPTCH_FID),
            'type' => 'S8',
            'status' => 'prealert_available',
            'source_status' => ['ips_predes' => 'ok', 'cds' => $cdsStatus],
            'dispatch' => [
                'identifier' => ShipmentCode::normalize((string) $dispatch->DESPTCH_FID),
                'origin_ctci' => $analysis['origin_ctci'],
                'destination_ctci' => $analysis['destination_ctci'],
                'destination_country_code' => 'BO',
                'dispatch_sequence' => $analysis['dispatch_sequence'],
                'predes_closing_at' => $dispatch->DESPTCH_CLOSING_DATE,
                'inward_parcels_weight_kg' => is_numeric($dispatch->INWARD_PARCELS_WEIGHT) ? (float) $dispatch->INWARD_PARCELS_WEIGHT : null,
                'inward_parcels_count' => is_numeric($dispatch->INWARD_PARCELS_NO) ? (int) $dispatch->INWARD_PARCELS_NO : null,
                'transport_legs' => $legs,
            ],
            'summary' => [
                'sacks_count' => $sacksCount,
                'packages_declared' => $packageCountDeclared,
                'packages_declared_complete' => $packageCountDeclaredComplete,
                'packages_identified' => $packageCountIdentified,
                'sacks_weight_total_kg' => round($sackWeightTotal, 3),
                'sacks_weight_complete' => $sackWeightComplete,
                'sacks_with_count_mismatch' => $sacksWithMismatch,
                'packages_with_cds_declaration' => $packagesWithDeclaration,
                'declarations_count' => $declarationCount,
                'declaration_coverage' => $declarationCoverage,
                'sacks_physically_received' => $receivedSacks,
                'arrival_status' => $arrivalStatus,
            ],
            'sacks' => $sacks,
            'completeness' => [
                'predes_sacks_complete' => true,
                'predes_items_complete' => true,
                'cds_lookup_complete' => in_array($cdsStatus, ['ok', 'not_applicable'], true) && !$cdsIndex['truncated'],
                'cds_truncated' => $cdsIndex['truncated'],
            ],
        ];
    }

    private function transportLegs(string $dispatchPid): array
    {
        $rows = DB::connection(config('ips.connection', 'ips_catalog'))
            ->table('dbo.N_EDI_DESPTCH_EVENTS as e')
            ->leftJoin('dbo.N_EDI_FLIGHTS as f', 'f.EDI_ROUTE_PID', '=', 'e.EDI_ROUTE_PID')
            ->where('e.DESPTCH_PID', $dispatchPid)
            ->orderBy('e.EVENT_LCL_DT')->orderBy('f.FLIGHT_ORDER_NO')
            ->get([
                'e.EVENT_LCL_DT', 'e.DESPTCH_DT', 'e.DESPTCH_ARV_TM', 'e.ESTIMATED_IND',
                'f.FLIGHT_FID', 'f.ORIG_PORT_CD', 'f.DEST_PORT_CD', 'f.FLIGHT_DPT_TM',
                'f.FLIGHT_ARV_TM', 'f.CARRIER_CD', 'f.CARRIER_NO', 'f.TRANSPORT_TYPE_CD',
            ]);

        return $rows->map(fn ($row) => [
            'dispatch_event_at' => $row->EVENT_LCL_DT,
            'dispatch_date' => $row->DESPTCH_DT,
            'arrival_date' => $row->DESPTCH_ARV_TM,
            'estimated_indicator' => $this->clean($row->ESTIMATED_IND),
            'flight' => $this->clean($row->FLIGHT_FID),
            'origin_airport_code' => $this->clean($row->ORIG_PORT_CD),
            'destination_airport_code' => $this->clean($row->DEST_PORT_CD),
            'scheduled_departure_at' => $row->FLIGHT_DPT_TM,
            'scheduled_arrival_at' => $row->FLIGHT_ARV_TM,
            'carrier_code' => $this->clean($row->CARRIER_CD),
            'carrier_number' => $this->clean($row->CARRIER_NO),
            'transport_type_code' => $this->clean($row->TRANSPORT_TYPE_CD),
        ])->values()->all();
    }

    private function arrivalData($predesSacks): array
    {
        if ($predesSacks->isEmpty()) return [];
        $db = DB::connection(config('ips.connection', 'ips_catalog'));
        $codes = $predesSacks->pluck('RECPTCL_FID')
            ->map(fn ($value) => ShipmentCode::normalize((string) $value))->filter()->unique()->values()->all();
        $localSacks = $this->queryInChunks($codes, fn ($chunk) => $db->table('dbo.L_RECPTCLS')
            ->whereIn('RECPTCL_FID', $chunk)->get(['RECPTCL_PID', 'RECPTCL_FID']));
        $codeByPid = [];
        foreach ($localSacks as $localSack) {
            $codeByPid[(string) $localSack->RECPTCL_PID] = ShipmentCode::normalize((string) $localSack->RECPTCL_FID);
        }
        $localIds = array_keys($codeByPid);
        if (!$localIds) return [];

        $events = $this->queryInChunks($localIds, fn ($chunk) => $db->table('dbo.L_RECPTCL_EVENTS as e')
            ->leftJoin('dbo.N_OWN_OFFICES as office', 'office.OWN_OFFICE_CD', '=', 'e.EVENT_OFFICE_CD')
            ->whereIn('e.RECPTCL_PID', $chunk)->where('e.EVENT_TYPE_CD', 130)
            ->orderByDesc('e.EVENT_GMT_DT')->get([
                'e.RECPTCL_PID', 'e.EVENT_GMT_DT', 'e.EVENT_OFFICE_CD', 'office.OFFICE_FCD', 'office.OFFICE_NM',
            ]));
        $infos = $this->queryInChunks($localIds, fn ($chunk) => $db->table('dbo.L_RECPTCL_INB_INFOS')
            ->whereIn('RECPTCL_PID', $chunk)->orderByDesc('ACTUAL_LCL_DT')->get([
                'RECPTCL_PID', 'ACTUAL_LOCATION_CD', 'ACTUAL_LCL_DT', 'DESPTCH_DT', 'MASTER_AIR_WAYBILL',
                'CARRIER_CD', 'TRANSPORT_NO', 'TRANSPORT_TYPE_CD',
            ]));
        $result = [];
        foreach ($events as $event) {
            $key = $codeByPid[(string) $event->RECPTCL_PID] ?? null;
            if ($key === null) continue;
            $result[$key]['event'] ??= $event;
        }
        foreach ($infos as $info) {
            $key = $codeByPid[(string) $info->RECPTCL_PID] ?? null;
            if ($key === null) continue;
            $result[$key]['info'] ??= $info;
        }
        return $result;
    }

    private function presentReceipt(array $receipt): array
    {
        $event = $receipt['event'] ?? null;
        $info = $receipt['info'] ?? null;
        return [
            'status' => $event ? 'received_in_ips' : 'not_recorded_in_ips',
            'received_at' => $event->EVENT_GMT_DT ?? null,
            'office_code' => $this->clean($event->OFFICE_FCD ?? null),
            'office_name' => $this->clean($event->OFFICE_NM ?? null),
            'actual_location_code' => $this->clean($info->ACTUAL_LOCATION_CD ?? null),
            'actual_local_at' => $info->ACTUAL_LCL_DT ?? null,
            'inbound_dispatch_date' => $info->DESPTCH_DT ?? null,
            'master_air_waybill' => $this->clean($info->MASTER_AIR_WAYBILL ?? null),
            'carrier_code' => $this->clean($info->CARRIER_CD ?? null),
            'transport_number' => $this->clean($info->TRANSPORT_NO ?? null),
            'transport_type_code' => $this->clean($info->TRANSPORT_TYPE_CD ?? null),
        ];
    }

    private function cdsIndex(array $codes): array
    {
        if (!config('postal.cds_enabled')) {
            return ['status' => 'disabled', 'truncated' => false, 'by_code' => [], 'uncertain_codes' => []];
        }
        if (!$codes) return ['status' => 'not_applicable', 'truncated' => false, 'by_code' => [], 'uncertain_codes' => []];

        try {
            $lookupCodes = array_slice($codes, 0, 5000);
            $result = $this->cds->prealertIndex($lookupCodes, 5000);
            $truncated = (bool) ($result['truncated'] ?? false) || count($codes) > count($lookupCodes);
            $declarationsByPackage = collect($result['declarations'] ?? [])->groupBy(fn ($row) => (string) ($row['package_id'] ?? ''));
            $responsesByPackage = collect($result['responses'] ?? [])->groupBy(fn ($row) => (string) ($row['package_id'] ?? ''));
            $byCode = [];

            foreach ($result['packages'] ?? [] as $package) {
                $pid = (string) ($package->MAIL_OBJECT_PID ?? '');
                $declarations = $declarationsByPackage->get($pid, collect())->map(fn ($row) => [
                    'number' => $row['declaration_number'] ?? null,
                    'state' => $row['state'] ?? null,
                    'workflow_stage' => $row['workflow_stage'] ?? null,
                    'data_status' => $row['data_status'] ?? null,
                    'piece_count' => $row['piece_count'] ?? null,
                    'document_count' => $row['document_count'] ?? null,
                    'content_summary' => $row['content_summary'] ?? null,
                    'data' => $row['data'] ?? ['status' => 'empty', 'fields' => [], 'pieces' => [], 'documents' => []],
                ])->values()->all();
                $responses = $responsesByPackage->get($pid, collect())->map(fn ($row) => [
                    'state' => $row['state'] ?? null,
                    'decision_code' => $row['decision_code'] ?? null,
                    'decision' => $row['decision'] ?? null,
                    'data_status' => $row['data_status'] ?? null,
                    'data' => $row['data'] ?? ['status' => 'empty', 'fields' => [], 'pieces' => [], 'documents' => []],
                ])->values()->all();
                $record = ['declarations' => $declarations, 'responses' => $responses];
                foreach ([$package->MAIL_OBJECT_ID ?? null, $package->MAIL_OBJECT_LOCAL_ID ?? null, $package->MAIL_OBJECT_LOCAL_ID2 ?? null] as $alias) {
                    $key = ShipmentCode::normalize((string) $alias);
                    if ($key !== '' && in_array($key, $lookupCodes, true)) $byCode[$key][] = $record;
                }
            }

            foreach ($byCode as $code => $records) {
                $byCode[$code] = [
                    'declarations' => collect($records)->flatMap(fn ($record) => $record['declarations'])
                        ->unique(fn ($row) => $row['number'].'|'.$row['state'].'|'.sha1(serialize($row['data'])))->values()->all(),
                    'responses' => collect($records)->flatMap(fn ($record) => $record['responses'])
                        ->unique(fn ($row) => $row['state'].'|'.$row['decision_code'].'|'.sha1(serialize($row['data'])))->values()->all(),
                ];
            }
        } catch (\Throwable $exception) {
            report($exception);
            return ['status' => 'unavailable', 'truncated' => false, 'by_code' => [], 'uncertain_codes' => $codes];
        }

        $uncertainCodes = $truncated ? $codes : [];

        return [
            'status' => 'ok',
            'truncated' => $truncated,
            'by_code' => $byCode,
            'uncertain_codes' => array_values(array_unique($uncertainCodes)),
        ];
    }

    private function missingCdsData(string $code, array $cds): array
    {
        if ($code === '') {
            return ['lookup_status' => 'identifier_missing', 'declarations' => [], 'responses' => []];
        }
        $uncertain = in_array($code, $cds['uncertain_codes'] ?? [], true);
        $status = $cds['status'] !== 'ok'
            ? $cds['status']
            : ($uncertain ? 'not_confirmed' : 'not_found');
        return ['lookup_status' => $status, 'declarations' => [], 'responses' => []];
    }

    private function queryInChunks(array $ids, callable $query): \Illuminate\Support\Collection
    {
        return collect($ids)->chunk(500)->flatMap(fn ($chunk) => $query($chunk->all()))->values();
    }

    private function decodeCursor(string $cursor, string $from, string $to): array
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/').str_repeat('=', (4 - strlen($cursor) % 4) % 4), true);
        $envelope = $decoded !== false ? json_decode($decoded, true) : null;
        $data = is_array($envelope) ? ($envelope['data'] ?? null) : null;
        $signature = is_array($envelope) ? ($envelope['signature'] ?? null) : null;
        $json = is_array($data) ? json_encode($data, JSON_UNESCAPED_SLASHES) : false;
        $key = (string) config('app.key');
        $signatureValid = is_string($json) && is_string($signature) && $key !== ''
            && hash_equals(hash_hmac('sha256', $json, $key), $signature);

        if (!$signatureValid || ($data['v'] ?? null) !== 1 || ($data['from'] ?? null) !== $from || ($data['to'] ?? null) !== $to
            || empty($data['closed_at']) || empty($data['pid'])) {
            throw new IpsOperationException('El cursor no es válido o pertenece a otro rango de fechas.', 422);
        }
        return $data;
    }

    private function encodeCursor(string $closedAt, string $pid, string $from, string $to): string
    {
        $data = ['v' => 1, 'closed_at' => $closedAt, 'pid' => $pid, 'from' => $from, 'to' => $to];
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        $envelope = json_encode([
            'data' => $data,
            'signature' => hash_hmac('sha256', $json ?: '{}', (string) config('app.key')),
        ], JSON_UNESCAPED_SLASHES);

        return rtrim(strtr(base64_encode($envelope ?: '{}'), '+/', '-_'), '=');
    }

    private function clean(mixed $value): ?string
    {
        $clean = trim((string) ($value ?? ''));
        return $clean === '' ? null : $clean;
    }
}
