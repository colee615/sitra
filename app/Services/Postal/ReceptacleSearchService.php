<?php

namespace App\Services\Postal;

use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;

/** Read-only operational lookup for IPS receptacles (postal bag/container labels). */
class ReceptacleSearchService
{
    /** Paginated, read-only dispatch register with an exact count of linked receptacles. */
    public function listDispatches(array $filters = []): LengthAwarePaginator
    {
        $db = DB::connection(config('postal.ips_read_connection', config('tracking.sqlserver.connection', 'sqlsrv')));
        $linkedCount = $db->table('dbo.L_RECPTCLS as linked')
            ->selectRaw('COUNT(*)')
            ->whereColumn('linked.DESPTCH_PID', 'd.DESPTCH_PID');
        $declaredPackageCount = $db->table('dbo.L_RECPTCLS as declared_bags')
            ->selectRaw('COALESCE(SUM(declared_bags.RECPTCL_MAILITMS_NO), 0)')
            ->whereColumn('declared_bags.DESPTCH_PID', 'd.DESPTCH_PID');
        $linkedPackageCount = $db->table('dbo.L_MAILITM_EVENTS as package_events')
            ->join('dbo.L_RECPTCLS as package_bags', 'package_bags.RECPTCL_PID', '=', 'package_events.RECPTCL_PID')
            ->selectRaw('COUNT(DISTINCT package_events.MAILITM_PID)')
            ->whereColumn('package_bags.DESPTCH_PID', 'd.DESPTCH_PID')
            ->whereNotNull('package_events.MAILITM_PID');

        $query = $db->table('dbo.L_DESPTCHS as d')
            ->select([
                'd.DESPTCH_PID', 'd.DESPTCH_FID', 'd.ORIG_OFFICE_FCD', 'd.DEST_OFFICE_FCD',
                'd.DESPTCH_WEIGHT', 'd.DESPTCH_DEPARTURE_DT', 'd.DESPTCH_RECPTCLS_NO', 'd.CONVEYANCE_TYPE_CD',
            ])
            ->selectSub($linkedCount, 'LINKED_RECPTCLS_NO')
            ->selectSub($declaredPackageCount, 'DECLARED_MAILITMS_NO')
            ->selectSub($linkedPackageCount, 'LINKED_MAILITMS_NO');

        $search = strtoupper(trim((string) ($filters['buscar'] ?? '')));
        if ($search !== '') {
            $analysis = UpuDispatchIdentifier::parse($search);
            if ($analysis) {
                $dispatchCode = $analysis['dispatch_code'] ?? $analysis['code'];
                $receptacleCode = $analysis['type'] === 's9' ? $analysis['code'] : null;
                $query->where(function ($where) use ($dispatchCode, $receptacleCode) {
                    $where->whereRaw('UPPER(RTRIM(LTRIM(d.DESPTCH_FID))) = ?', [$dispatchCode])
                        ->orWhereExists(function ($subquery) use ($dispatchCode, $receptacleCode) {
                            $subquery->selectRaw('1')->from('dbo.L_RECPTCLS as r')
                                ->whereColumn('r.DESPTCH_PID', 'd.DESPTCH_PID')
                                ->where(function ($match) use ($dispatchCode, $receptacleCode) {
                                    $match->whereRaw('UPPER(RTRIM(LTRIM(r.RECPTCL_FID))) LIKE ?', [$dispatchCode . '%']);
                                    if ($receptacleCode !== null) {
                                        $match->orWhereRaw('UPPER(RTRIM(LTRIM(r.RECPTCL_FID))) = ?', [$receptacleCode]);
                                    }
                                });
                        });
                });
            } else {
                $like = '%' . $search . '%';
                $query->where(function ($where) use ($like) {
                    $where->whereRaw('UPPER(RTRIM(LTRIM(d.DESPTCH_FID))) LIKE ?', [$like])
                        ->orWhereRaw('UPPER(RTRIM(LTRIM(d.ORIG_OFFICE_FCD))) LIKE ?', [$like])
                        ->orWhereRaw('UPPER(RTRIM(LTRIM(d.DEST_OFFICE_FCD))) LIKE ?', [$like])
                        ->orWhereRaw('UPPER(RTRIM(LTRIM(d.CONVEYANCE_TYPE_CD))) LIKE ?', [$like])
                        ->orWhereExists(function ($subquery) use ($like) {
                            $subquery->selectRaw('1')->from('dbo.L_RECPTCLS as r')
                                ->whereColumn('r.DESPTCH_PID', 'd.DESPTCH_PID')
                                ->where(function ($match) use ($like) {
                                    $match->whereRaw('UPPER(RTRIM(LTRIM(r.RECPTCL_FID))) LIKE ?', [$like])
                                        ->orWhereRaw('UPPER(RTRIM(LTRIM(r.RECPTCL_REG_NO))) LIKE ?', [$like])
                                        ->orWhereRaw('UPPER(RTRIM(LTRIM(r.RECPTCL_INS_NO))) LIKE ?', [$like]);
                                });
                        });
                });
            }
        }

        if (!empty($filters['desde'])) {
            $query->whereDate('d.DESPTCH_DEPARTURE_DT', '>=', $filters['desde']);
        }
        if (!empty($filters['hasta'])) {
            $query->whereDate('d.DESPTCH_DEPARTURE_DT', '<=', $filters['hasta']);
        }

        return $query->orderByDesc('d.DESPTCH_DEPARTURE_DT')->orderByDesc('d.DESPTCH_FID')
            ->paginate((int) ($filters['por_pagina'] ?? 25))->withQueryString();
    }

    public function search(string $identifier): array
    {
        $identifier = strtoupper(trim($identifier));
        $db = DB::connection(config('postal.ips_read_connection', config('tracking.sqlserver.connection', 'sqlsrv')));

        $codeAnalysis = UpuDispatchIdentifier::parse($identifier);
        if ($codeAnalysis) {
            $identifier = $codeAnalysis['code'];
        }
        if (($codeAnalysis['type'] ?? null) === 's8') {
            return $this->searchDispatch($db, $codeAnalysis);
        }

        $rows = $db->table('dbo.L_RECPTCLS as r')
            ->leftJoin('dbo.L_DESPTCHS as d', 'd.DESPTCH_PID', '=', 'r.DESPTCH_PID')
            ->where(function ($query) use ($identifier) {
                $query->whereRaw('UPPER(RTRIM(LTRIM(r.RECPTCL_FID))) = ?', [$identifier])
                    ->orWhereRaw('UPPER(RTRIM(LTRIM(r.RECPTCL_REG_NO))) = ?', [$identifier])
                    ->orWhereRaw('UPPER(RTRIM(LTRIM(r.RECPTCL_INS_NO))) = ?', [$identifier]);
            })
            ->orderByDesc('r.EVT_GMT_DT')->orderBy('r.RECPTCL_PID')->limit(21)
            ->get([
                'r.RECPTCL_PID', 'r.RECPTCL_FID', 'r.RECPTCL_REG_NO', 'r.RECPTCL_INS_NO',
                'r.RECPTCL_WEIGHT', 'r.RECPTCL_MAILITMS_NO', 'r.RECPTCL_SEAL_NUMBER',
                'r.MAIL_SUBCLASS_FCD', 'r.RECPTCL_CHARACTERISTIC_CD', 'r.RECPTCL_CONTENT_FORMAT_CD',
                'r.DOCUMENTATION_ENCLOSED', 'r.COMMENTS', 'r.EVT_TYPE_CD', 'r.EVT_GMT_DT',
                'r.STATE_IND_CD', 'r.POSTAL_STATUS_CD', 'r.EVT_CONSGNT_PID',
                'd.DESPTCH_PID', 'd.DESPTCH_FID', 'd.ORIG_OFFICE_FCD', 'd.DEST_OFFICE_FCD',
                'd.DESPTCH_WEIGHT', 'd.DESPTCH_DEPARTURE_DT', 'd.DESPTCH_RECPTCLS_NO', 'd.CONVEYANCE_TYPE_CD',
            ]);
        $ambiguous = $rows->count() > 20;
        $receptacles = $rows->take(20);
        $result = ['identifier'=>$identifier, 'code_analysis'=>$codeAnalysis, 'search_type'=>$codeAnalysis['type'] ?? 'receptacle',
            'dispatches'=>[], 'receptacles'=>$receptacles->all(), 'events'=>[], 'items'=>[], 'manifests'=>[], 'truncated'=>$ambiguous];
        if ($receptacles->count() !== 1) return $result;

        $receptacle = $receptacles->first();
        $id = $receptacle->RECPTCL_PID;
        $result['events'] = $db->table('dbo.L_RECPTCL_EVENTS as e')
            ->leftJoin('dbo.C_EVENT_TYPES as type', 'type.EVENT_TYPE_CD', '=', 'e.EVENT_TYPE_CD')
            ->leftJoin('dbo.CT_EVENT_TYPES as local_type', function ($join) {
                $join->on('local_type.EVENT_TYPE_CD','=','e.EVENT_TYPE_CD')->where('local_type.LANGUAGE_CD','=','ES');
            })
            ->leftJoin('dbo.N_OWN_OFFICES as office', 'office.OWN_OFFICE_CD', '=', 'e.EVENT_OFFICE_CD')
            ->leftJoin('dbo.L_USERS as user', 'user.USER_PID', '=', 'e.USER_PID')
            ->where('e.RECPTCL_PID', $id)->orderByDesc('e.EVENT_GMT_DT')->limit(501)
            ->get(['e.EVENT_GMT_DT','e.EVENT_TYPE_CD','local_type.LOCAL_EVENT_TYPE_NM as EVENT_NAME_ES',
                'type.EVENT_TYPE_NM as EVENT_NAME','e.EVENT_OFFICE_CD','office.OFFICE_FCD','office.OFFICE_NM',
                'e.USER_PID','user.USER_FID','user.USER_NM','e.NEXT_OFFICE_CD','e.WORKSTATION_PID']);
        if ($result['events']->count() > 500) { $result['truncated'] = true; $result['events'] = $result['events']->take(500); }

        $itemIds = $db->table('dbo.L_MAILITM_EVENTS')->where('RECPTCL_PID',$id)
            ->whereNotNull('MAILITM_PID')->distinct()->limit(1001)->pluck('MAILITM_PID');
        $result['truncated'] = $result['truncated'] || $itemIds->count() > 1000;
        $itemIds = $itemIds->take(1000)->values();
        if ($itemIds->isEmpty()) return $result;

        $result['items'] = $db->table('dbo.L_MAILITMS as mi')
            ->leftJoin('dbo.C_COUNTRIES as origin','origin.COUNTRY_CD','=','mi.ORIG_COUNTRY_CD')
            ->leftJoin('dbo.C_COUNTRIES as destination','destination.COUNTRY_CD','=','mi.DEST_COUNTRY_CD')
            ->leftJoin('dbo.C_EVENT_TYPES as event_type','event_type.EVENT_TYPE_CD','=','mi.EVT_TYPE_CD')
            ->leftJoin('dbo.CT_EVENT_TYPES as local_event_type',function($join) {
                $join->on('local_event_type.EVENT_TYPE_CD','=','mi.EVT_TYPE_CD')->where('local_event_type.LANGUAGE_CD','=','ES');
            })
            ->leftJoin('dbo.C_MAIL_CLASSES as mail_class','mail_class.MAIL_CLASS_CD','=','mi.MAIL_CLASS_CD')
            ->leftJoin('dbo.C_POSTAL_STATUSES as postal_status','postal_status.POSTAL_STATUS_CD','=','mi.POSTAL_STATUS_CD')
            ->whereIn('mi.MAILITM_PID',$itemIds)->orderBy('mi.MAILITM_FID')->orderBy('mi.MAILITM_LOCAL_ID')
            ->get(['mi.MAILITM_FID','mi.MAILITM_LOCAL_ID','mi.MAILITM_WEIGHT','mi.MAILITM_VALUE',
                'mail_class.MAIL_CLASS_NM as MAIL_CLASS_NAME','postal_status.POSTAL_STATUS_NM as POSTAL_STATUS_NAME',
                'mi.MAIL_CLASS_CD','mi.POSTAL_STATUS_CD','mi.EVT_GMT_DT','mi.EVT_TYPE_CD','local_event_type.LOCAL_EVENT_TYPE_NM as EVENT_NAME_ES',
                'event_type.EVENT_TYPE_NM as EVENT_NAME','origin.COUNTRY_NM as ORIGIN_COUNTRY',
                'destination.COUNTRY_NM as DEST_COUNTRY']);

        $result['manifests'] = $db->table('dbo.L_MANIFESTS_MAILITMS as link')
            ->join('dbo.L_MANIFEST_LISTS as manifest','manifest.MANIFEST_LIST_ID','=','link.MANIFEST_LIST_ID')
            ->leftJoin('dbo.L_USERS as user','user.USER_PID','=','manifest.USER_PID')
            ->whereIn('link.MAILITM_PID',$itemIds)->whereNull('link.DEL_IND')
            ->orderByDesc('manifest.CREATION_LCL_DT')->distinct()->limit(101)
            ->get(['manifest.MANIFEST_LIST_ID','manifest.FORM_NM','manifest.MANIF_TYPE_ID',
                'manifest.CREATION_LCL_DT','manifest.OBSERVATION','user.USER_FID','user.USER_NM']);
        if ($result['manifests']->count() > 100) { $result['truncated'] = true; $result['manifests'] = $result['manifests']->take(100); }
        return $result;
    }

    /** Search an S8 dispatch directly, or resolve it through the S8 prefix stored in S9 receptacle IDs. */
    private function searchDispatch($db, array $analysis): array
    {
        $code = $analysis['code'];
        $dispatches = $db->table('dbo.L_DESPTCHS as d')
            ->whereRaw('UPPER(RTRIM(LTRIM(d.DESPTCH_FID))) = ?', [$code])
            ->orderByDesc('d.DESPTCH_DEPARTURE_DT')->orderBy('d.DESPTCH_PID')->limit(21)
            ->get(['d.DESPTCH_PID','d.DESPTCH_FID','d.ORIG_OFFICE_FCD','d.DEST_OFFICE_FCD',
                'd.DESPTCH_WEIGHT','d.DESPTCH_DEPARTURE_DT','d.DESPTCH_RECPTCLS_NO','d.CONVEYANCE_TYPE_CD']);

        $dispatchIds = $dispatches->pluck('DESPTCH_PID')->filter()->unique()->values();
        if ($dispatchIds->isEmpty()) {
            $dispatchIds = $db->table('dbo.L_RECPTCLS as r')
                ->whereNotNull('r.DESPTCH_PID')
                ->whereRaw('UPPER(RTRIM(LTRIM(r.RECPTCL_FID))) LIKE ?', [$code . '%'])
                ->distinct()->limit(21)->pluck('r.DESPTCH_PID')->values();
            if ($dispatchIds->isNotEmpty()) {
                $dispatches = $db->table('dbo.L_DESPTCHS as d')->whereIn('d.DESPTCH_PID', $dispatchIds)
                    ->orderByDesc('d.DESPTCH_DEPARTURE_DT')->orderBy('d.DESPTCH_PID')->limit(21)
                    ->get(['d.DESPTCH_PID','d.DESPTCH_FID','d.ORIG_OFFICE_FCD','d.DEST_OFFICE_FCD',
                        'd.DESPTCH_WEIGHT','d.DESPTCH_DEPARTURE_DT','d.DESPTCH_RECPTCLS_NO','d.CONVEYANCE_TYPE_CD']);
                $dispatchIds = $dispatches->pluck('DESPTCH_PID')->filter()->unique()->values();
            }
        }

        $truncated = $dispatches->count() > 20;
        $visibleDispatches = $dispatches->take(20)->values();
        $dispatchIds = $visibleDispatches->pluck('DESPTCH_PID')->filter()->unique()->values();

        $receptacleCounts = $dispatchIds->isEmpty() ? collect() : $db->table('dbo.L_RECPTCLS')
            ->select('DESPTCH_PID')->selectRaw('COUNT(*) as LINKED_RECPTCLS_NO')
            ->whereIn('DESPTCH_PID', $dispatchIds)->groupBy('DESPTCH_PID')->get()
            ->keyBy(fn ($row) => (string) $row->DESPTCH_PID);
        foreach ($dispatches as $dispatch) {
            $dispatch->LINKED_RECPTCLS_NO = (int) ($receptacleCounts->get((string) $dispatch->DESPTCH_PID)->LINKED_RECPTCLS_NO ?? 0);
        }

        $receptacles = $dispatchIds->isEmpty() ? collect() : $db->table('dbo.L_RECPTCLS as r')
            ->whereIn('r.DESPTCH_PID', $dispatchIds)
            ->orderBy('r.RECPTCL_FID')->orderBy('r.RECPTCL_PID')->limit(101)
            ->get(['r.RECPTCL_PID','r.RECPTCL_FID','r.RECPTCL_REG_NO','r.RECPTCL_INS_NO',
                'r.RECPTCL_WEIGHT','r.RECPTCL_MAILITMS_NO','r.RECPTCL_SEAL_NUMBER',
                'r.MAIL_SUBCLASS_FCD','r.RECPTCL_CHARACTERISTIC_CD','r.RECPTCL_CONTENT_FORMAT_CD',
                'r.DOCUMENTATION_ENCLOSED','r.COMMENTS','r.EVT_TYPE_CD','r.EVT_GMT_DT',
                'r.STATE_IND_CD','r.POSTAL_STATUS_CD','r.EVT_CONSGNT_PID','r.DESPTCH_PID']);

        $receptacleIds = $receptacles->pluck('RECPTCL_PID')->filter()->unique()->values();
        $packageCounts = $receptacleIds->isEmpty() ? collect() : $db->table('dbo.L_MAILITM_EVENTS as e')
            ->select('e.RECPTCL_PID')->selectRaw('COUNT(DISTINCT e.MAILITM_PID) as LINKED_MAILITMS_NO')
            ->whereIn('e.RECPTCL_PID', $receptacleIds)->whereNotNull('e.MAILITM_PID')
            ->groupBy('e.RECPTCL_PID')->get()->keyBy(fn ($row) => (string) $row->RECPTCL_PID);
        foreach ($receptacles as $receptacle) {
            $packageCount = $packageCounts->get((string) $receptacle->RECPTCL_PID);
            $receptacle->LINKED_MAILITMS_NO = (int) ($packageCount->LINKED_MAILITMS_NO ?? 0);
        }

        $truncated = $truncated || $receptacles->count() > 100;
        return [
            'identifier' => $code,
            'code_analysis' => $analysis,
            'search_type' => 's8',
            'receptacle_total' => (int) $receptacleCounts->sum('LINKED_RECPTCLS_NO'),
            'dispatches' => $visibleDispatches->all(),
            'receptacles' => $receptacles->take(100)->all(),
            'events' => [], 'items' => [], 'manifests' => [], 'truncated' => $truncated,
        ];
    }
}
