<?php

namespace App\Services\Postal;

use Illuminate\Support\Facades\DB;

class CdsRepository
{
    public function __construct(private CdsXmlParser $xml) {}

    public function search(array $codes): array
    {
        $db = DB::connection(config('postal.cds_connection', 'cds'));
        $limit = (int) config('postal.record_limit', 100);
        $codes = array_values(array_unique(array_filter(array_map([ShipmentCode::class, 'normalize'], $codes))));
        if (!$codes) return ['packages' => [], 'declarations' => [], 'responses' => [], 'events' => [], 'truncated' => false,
            'packages_truncated' => false, 'declarations_truncated' => false];
        $packages = $db->table('dbo.O_MAIL_OBJECTS as mo')
            ->leftJoin('dbo.M_MAIL_STATES as state', 'state.MAIL_STATE_CD', '=', 'mo.MAIL_STATE_CD')
            ->where(function ($q) use ($codes) {
                $q->whereIn('mo.MAIL_OBJECT_ID', $codes)->orWhereIn('mo.MAIL_OBJECT_LOCAL_ID', $codes)->orWhereIn('mo.MAIL_OBJECT_LOCAL_ID2', $codes);
            })
            ->orderByDesc('mo.POSTING_DATE')->orderBy('mo.MAIL_OBJECT_PID')->limit($limit + 1)
            ->get(['mo.MAIL_OBJECT_PID', 'mo.MAIL_OBJECT_ID', 'mo.MAIL_OBJECT_LOCAL_ID', 'mo.MAIL_OBJECT_LOCAL_ID2',
                'mo.MAIL_OBJECT_TYPE_CD', 'mo.MAIL_CLASS_CD', 'mo.MAIL_CATEGORY_CD', 'mo.MAIL_FLOW_CD',
                'mo.ORIG_POST_ORGANIZATION_CD', 'mo.DEST_POST_ORGANIZATION_CD', 'mo.POSTING_DATE', 'state.MAIL_STATE_NM']);
        $packagesTruncated = $packages->count() > $limit;
        $truncated = $packagesTruncated;
        $packages = $packages->take($limit);
        [$packages, $siblingsTruncated] = $this->expandSiblingObjects($db, $packages, $codes, $limit);
        $packagesTruncated = $packagesTruncated || $siblingsTruncated;
        $truncated = $truncated || $siblingsTruncated;
        $ids = $packages->pluck('MAIL_OBJECT_PID')->all();
        $declarations = []; $responses = []; $events = [];
        $declarationsTruncated = false;
        if ($ids) {
            foreach (['declarations' => ['O_DECLARATIONS', 'DECLARATION_PID', 'O_DECLARATION_EVENTS', 'D_EVENT_GMT_DT', 'DecData'],
                      'responses' => ['O_RESPONSES', 'RESPONSE_PID', 'O_RESPONSE_EVENTS', 'R_EVENT_GMT_DT', 'ResData']] as $kind => [$table, $id, $eventTable, $date, $root]) {
                $select = ['d.'.$id.' as id', 'd.MAIL_OBJECT_PID', 'd.CDS_STATE_CD', 'state.CDS_STATE_NM', DB::raw('CONVERT(nvarchar(max), d.DATA) as payload')];
                if ($kind === 'declarations') $select[] = 'd.AN_DECLARATION_ID as declaration_number';
                $rows = $db->table('dbo.'.$table.' as d')
                    ->leftJoin('dbo.M_CDS_STATES as state', 'state.CDS_STATE_CD', '=', 'd.CDS_STATE_CD')
                    ->whereIn('d.MAIL_OBJECT_PID', $ids)->orderBy('d.'.$id)->limit($limit + 1)
                    ->get($select);
                $rowsTruncated = $rows->count() > $limit;
                $truncated = $truncated || $rowsTruncated;
                if ($kind === 'declarations') $declarationsTruncated = $declarationsTruncated || $rowsTruncated;
                $rows = $rows->take($limit);
                foreach ($rows as $row) {
                    $parsed = $this->xml->parse($row->payload, $root);
                    // Keep declaration and response states distinct from the delivery state in IPS.
                    $record = ['id' => $row->id, 'package_id' => $row->MAIL_OBJECT_PID, 'state' => $row->CDS_STATE_NM, 'data' => $parsed];
                    if ($kind === 'responses') {
                        $decision = $parsed['fields']['Decis'] ?? null;
                        $record['decision'] = $decision !== null && ctype_digit($decision)
                            ? $db->table('dbo.M_CUSTOMS_DECISIONS')->where('CUSTOMS_DECISION_CD', $decision)->value('CUSTOMS_DECISION_NM') : null;
                        $responses[] = $record;
                    } else {
                        $record['declaration_number'] = $row->declaration_number ?? null;
                        $record['workflow_stage'] = $this->declarationStage($row->CDS_STATE_NM);
                        $record['content_summary'] = $this->declarationContentSummary($parsed);
                        $declarations[] = $record;
                    }
                }
                if ($rows->isEmpty()) continue;
                $eventRows = $db->table('dbo.'.$eventTable.' as e')
                    ->leftJoin('dbo.M_CDS_EVENT_TYPES as type', 'type.CDS_EVENT_TYPE_CD', '=', 'e.CDS_EVENT_TYPE_CD')
                    ->leftJoin('dbo.A_USERS as u', 'u.USER_CD', '=', 'e.USER_CD')
                    ->leftJoin('dbo.M_OFFICES as office', 'office.OFFICE_CD', '=', 'e.OFFICE_CD')
                    ->whereIn('e.'.$id, $rows->pluck('id')->all())->orderByDesc('e.'.$date)->limit(501)
                    ->get(['e.'.$id.' as record_id', 'e.'.$date.' as occurred_at', 'e.CDS_EVENT_TYPE_CD as code', 'type.CDS_EVENT_TYPE_NM as name', 'e.USER_CD as user_code', 'u.USER_NM as user_name', 'office.OFFICE_NM as office']);
                $truncated = $truncated || $eventRows->count() > 500;
                foreach ($eventRows->take(500) as $event) {
                    $events[] = (array) $event + ['source' => 'CDS', 'kind' => $kind];
                }
            }
        }
        $countryCodes = collect($declarations)->flatMap(function ($record) {
            $fields = $record['data']['fields'];
            return array_merge([$fields['SCtr'] ?? null, $fields['RCtr'] ?? null], array_column($record['data']['pieces'], 'OCtr'));
        })->filter()->unique()->values()->all();
        $countries = $countryCodes ? $db->table('dbo.R_COUNTRIES')->whereIn('COUNTRY_CD', $countryCodes)->pluck('COUNTRY_NM', 'COUNTRY_CD')->all() : [];
        $natureCodes = collect($declarations)->map(fn ($record) => $record['data']['fields']['NTyp'] ?? null)->filter()->unique()->values()->all();
        $natures = $natureCodes ? $db->table('dbo.R_NATURE_TYPES')->whereIn('NATURE_TYPE_CD', $natureCodes)->pluck('NATURE_TYPE_NM', 'NATURE_TYPE_CD')->all() : [];
        foreach ($declarations as &$record) {
            $fields = $record['data']['fields'];
            $record['countries'] = $countries;
            $record['nature'] = $fields['NTypDesc'] ?? $natures[$fields['NTyp'] ?? ''] ?? $fields['NTyp'] ?? null;
        }
        unset($record);
        return ['packages' => $packages->all(), 'declarations' => $declarations, 'responses' => $responses, 'events' => $events,
            'truncated' => $truncated, 'packages_truncated' => $packagesTruncated, 'declarations_truncated' => $declarationsTruncated];
    }

    /** Return a lightweight, bounded index for packages in an IPS receptacle. */
    public function declarationIndex(array $codes, int $limit = 1000): array
    {
        $db = DB::connection(config('postal.cds_connection', 'cds'));
        $limit = max(1, min($limit, 1000));
        $codes = array_values(array_unique(array_filter(array_map([ShipmentCode::class, 'normalize'], $codes))));
        if (!$codes) return ['packages' => [], 'declarations' => [], 'truncated' => false,
            'packages_truncated' => false, 'declarations_truncated' => false];

        // Three IN clauses are used to match the CDS primary and local IDs. Keep
        // each chunk below SQL Server's parameter limit (600 × 3 bindings).
        $packages = collect();
        $truncated = false;
        $packagesTruncated = false;
        foreach (array_chunk($codes, 600) as $chunk) {
            $rows = $db->table('dbo.O_MAIL_OBJECTS as mo')
                ->leftJoin('dbo.M_MAIL_STATES as state', 'state.MAIL_STATE_CD', '=', 'mo.MAIL_STATE_CD')
                ->where(function ($query) use ($chunk) {
                    $query->whereIn('mo.MAIL_OBJECT_ID', $chunk)
                        ->orWhereIn('mo.MAIL_OBJECT_LOCAL_ID', $chunk)
                        ->orWhereIn('mo.MAIL_OBJECT_LOCAL_ID2', $chunk);
                })
                ->orderByDesc('mo.POSTING_DATE')->orderBy('mo.MAIL_OBJECT_PID')->limit($limit + 1)
                ->get(['mo.MAIL_OBJECT_PID', 'mo.MAIL_OBJECT_ID', 'mo.MAIL_OBJECT_LOCAL_ID', 'mo.MAIL_OBJECT_LOCAL_ID2',
                    'mo.MAIL_OBJECT_TYPE_CD', 'mo.MAIL_CLASS_CD', 'mo.MAIL_FLOW_CD', 'mo.POSTING_DATE', 'state.MAIL_STATE_NM']);
            $packages = $packages->concat($rows)->unique('MAIL_OBJECT_PID')->values();
            if ($packages->count() > $limit) {
                $truncated = true;
                $packagesTruncated = true;
                break;
            }
        }

        $packages = $packages->unique('MAIL_OBJECT_PID')->values();
        if ($packages->count() > $limit) {
            $truncated = true;
            $packagesTruncated = true;
        }
        $packages = $packages->take($limit);
        [$packages, $siblingsTruncated] = $this->expandSiblingObjects($db, $packages, $codes, $limit);
        $packagesTruncated = $packagesTruncated || $siblingsTruncated;
        $truncated = $truncated || $siblingsTruncated;
        $ids = $packages->pluck('MAIL_OBJECT_PID')->all();
        if (!$ids) return ['packages' => [], 'declarations' => [], 'truncated' => $truncated,
            'packages_truncated' => $packagesTruncated, 'declarations_truncated' => false];

        $rows = $db->table('dbo.O_DECLARATIONS as d')
            ->leftJoin('dbo.M_CDS_STATES as state', 'state.CDS_STATE_CD', '=', 'd.CDS_STATE_CD')
            ->whereIn('d.MAIL_OBJECT_PID', $ids)->orderBy('d.DECLARATION_PID')->limit($limit + 1)
            ->get(['d.DECLARATION_PID as id', 'd.MAIL_OBJECT_PID as package_id', 'd.AN_DECLARATION_ID as declaration_number',
                'state.CDS_STATE_NM as state', DB::raw('CONVERT(nvarchar(max), d.DATA) as payload')]);
        $declarationsTruncated = $rows->count() > $limit;
        if ($declarationsTruncated) $truncated = true;

        return [
            'packages' => $packages->all(),
            'declarations' => $rows->take($limit)->map(function ($row) {
                $parsed = $this->xml->parse($row->payload, 'DecData');
                return [
                    'id' => $row->id,
                    'package_id' => $row->package_id,
                    'declaration_number' => $row->declaration_number,
                    'state' => $row->state,
                    'workflow_stage' => $this->declarationStage($row->state),
                    'data_status' => $parsed['status'],
                    'piece_count' => count($parsed['pieces']),
                    'document_count' => count($parsed['documents']),
                    'content_summary' => $this->declarationContentSummary($parsed),
                ];
            })->all(),
            'truncated' => $truncated,
            'packages_truncated' => $packagesTruncated,
            'declarations_truncated' => $declarationsTruncated,
        ];
    }

    private function declarationStage(?string $state): string
    {
        $value = mb_strtolower(trim((string) $state), 'UTF-8');
        return match (true) {
            str_contains($value, 'draft'), str_contains($value, 'borrador') => 'Borrador · no enviado a Aduana',
            str_contains($value, 'sent to customs'), str_contains($value, 'enviado a aduana'), str_contains($value, 'enviada a aduana') => 'Enviada a Aduana',
            str_contains($value, 'deleted'), str_contains($value, 'eliminad') => 'Eliminada en CDS',
            str_contains($value, 'final') => 'Finalizada en CDS',
            trim((string) $state) !== '' => (string) $state,
            default => 'Estado no informado',
        };
    }

    private function declarationContentSummary(array $parsed): string
    {
        if (($parsed['status'] ?? null) !== 'ok') return 'XML vacío o no interpretable';
        $pieces = count($parsed['pieces'] ?? []);
        $documents = count($parsed['documents'] ?? []);
        return $pieces || $documents
            ? "{$pieces} artículo(s) · {$documents} documento(s)"
            : 'Sin artículos ni documentos detallados en el XML';
    }

    /** If a local alias found an object, include every CDS object with its S10. */
    private function expandSiblingObjects($db, $packages, array $codes, int $limit): array
    {
        $postalIds = $packages->pluck('MAIL_OBJECT_ID')
            ->filter()
            ->map(fn ($value) => ShipmentCode::normalize((string) $value))
            ->unique()
            ->reject(fn ($value) => in_array($value, $codes, true))
            ->values()
            ->all();
        if (!$postalIds) return [$packages, false];

        $siblings = $db->table('dbo.O_MAIL_OBJECTS as mo')
            ->leftJoin('dbo.M_MAIL_STATES as state', 'state.MAIL_STATE_CD', '=', 'mo.MAIL_STATE_CD')
            ->whereIn('mo.MAIL_OBJECT_ID', $postalIds)
            ->orderByDesc('mo.POSTING_DATE')->orderBy('mo.MAIL_OBJECT_PID')->limit($limit + 1)
            ->get(['mo.MAIL_OBJECT_PID', 'mo.MAIL_OBJECT_ID', 'mo.MAIL_OBJECT_LOCAL_ID', 'mo.MAIL_OBJECT_LOCAL_ID2',
                'mo.MAIL_OBJECT_TYPE_CD', 'mo.MAIL_CLASS_CD', 'mo.MAIL_CATEGORY_CD', 'mo.MAIL_FLOW_CD',
                'mo.ORIG_POST_ORGANIZATION_CD', 'mo.DEST_POST_ORGANIZATION_CD', 'mo.POSTING_DATE', 'state.MAIL_STATE_NM']);
        $truncated = $siblings->count() > $limit;
        $combined = $packages->concat($siblings->take($limit))->unique('MAIL_OBJECT_PID')->sortByDesc('POSTING_DATE')->values();
        if ($combined->count() > $limit) $truncated = true;
        return [$combined->take($limit)->values(), $truncated];
    }
}
