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
        if (!$codes) return ['packages' => [], 'declarations' => [], 'responses' => [], 'events' => [], 'truncated' => false];
        $packages = $db->table('dbo.O_MAIL_OBJECTS as mo')
            ->leftJoin('dbo.M_MAIL_STATES as state', 'state.MAIL_STATE_CD', '=', 'mo.MAIL_STATE_CD')
            ->where(function ($q) use ($codes) {
                $q->whereIn('mo.MAIL_OBJECT_ID', $codes)->orWhereIn('mo.MAIL_OBJECT_LOCAL_ID', $codes)->orWhereIn('mo.MAIL_OBJECT_LOCAL_ID2', $codes);
            })
            ->orderByDesc('mo.POSTING_DATE')->orderBy('mo.MAIL_OBJECT_PID')->limit($limit + 1)
            ->get(['mo.MAIL_OBJECT_PID', 'mo.MAIL_OBJECT_ID', 'mo.MAIL_OBJECT_LOCAL_ID', 'mo.MAIL_OBJECT_TYPE_CD', 'mo.POSTING_DATE', 'state.MAIL_STATE_NM']);
        $truncated = $packages->count() > $limit;
        $packages = $packages->take($limit);
        $ids = $packages->pluck('MAIL_OBJECT_PID')->all();
        $declarations = []; $responses = []; $events = [];
        if ($ids) {
            foreach (['declarations' => ['O_DECLARATIONS', 'DECLARATION_PID', 'O_DECLARATION_EVENTS', 'D_EVENT_GMT_DT', 'DecData'],
                      'responses' => ['O_RESPONSES', 'RESPONSE_PID', 'O_RESPONSE_EVENTS', 'R_EVENT_GMT_DT', 'ResData']] as $kind => [$table, $id, $eventTable, $date, $root]) {
                $rows = $db->table('dbo.'.$table.' as d')
                    ->leftJoin('dbo.M_CDS_STATES as state', 'state.CDS_STATE_CD', '=', 'd.CDS_STATE_CD')
                    ->whereIn('d.MAIL_OBJECT_PID', $ids)->orderBy('d.'.$id)->limit($limit + 1)
                    ->get(['d.'.$id.' as id', 'd.MAIL_OBJECT_PID', 'd.CDS_STATE_CD', 'state.CDS_STATE_NM', DB::raw('CONVERT(nvarchar(max), d.DATA) as payload')]);
                $truncated = $truncated || $rows->count() > $limit;
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
        return ['packages' => $packages->all(), 'declarations' => $declarations, 'responses' => $responses, 'events' => $events, 'truncated' => $truncated];
    }
}
