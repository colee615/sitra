<?php

namespace App\Services\Postal;

use Illuminate\Support\Facades\DB;

/** Read-only operational lookup for IPS receptacles (postal bag/container labels). */
final class ReceptacleSearchService
{
    public function search(string $identifier): array
    {
        $identifier = strtoupper(trim($identifier));
        $db = DB::connection(config('postal.ips_read_connection', config('tracking.sqlserver.connection', 'sqlsrv')));

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
        $result = ['identifier'=>$identifier, 'receptacles'=>$receptacles->all(), 'events'=>[], 'items'=>[], 'manifests'=>[], 'truncated'=>$ambiguous];
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
}
