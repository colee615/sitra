<?php

namespace App\Services\Postal;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/** Read-only, date-bounded IPS activity report for postal operations. */
class PostalActivityReport
{
    public function search(CarbonImmutable $fromUtc, CarbonImmutable $untilUtc, array $filters, int $limit = 1000): array
    {
        $db = DB::connection(config('postal.ips_read_connection', config('tracking.sqlserver.connection', 'sqlsrv')));
        $ranked = $this->rankedEvents($db, $fromUtc, $untilUtc, $filters);

        $total = $db->query()->fromSub(clone $ranked, 'ranked_activity_count')
            ->where('event_rank', 1)->count();

        $rows = $db->query()->fromSub(clone $ranked, 'ranked_activity')
            ->where('event_rank', 1)
            ->orderByDesc('EVENT_GMT_DT')
            ->limit($limit + 1)
            ->get();
        $truncated = $rows->count() > $limit;
        $rows = $rows->take($limit)->values();

        // The filter must come from the full valid IPS office catalog, not only
        // offices that happened to report an event during the selected period.
        $offices = $db->table('dbo.N_OWN_OFFICES as office')
            ->whereIn('office.VALID_IND', ['1', '3'])
            ->orderBy('office.OFFICE_NM')
            ->get([
                'office.OWN_OFFICE_CD as code',
                'office.OFFICE_FCD as short_name',
                'office.OFFICE_NM as name',
            ]);

        return [
            'rows' => $rows,
            'total' => $total,
            'truncated' => $truncated,
            'offices' => $offices,
        ];
    }

    private function rankedEvents(Connection $db, CarbonImmutable $fromUtc, CarbonImmutable $untilUtc, array $filters)
    {
        $query = $db->table('dbo.L_MAILITM_EVENTS as e')
            ->join('dbo.L_MAILITMS as mi', 'mi.MAILITM_PID', '=', 'e.MAILITM_PID')
            ->leftJoin('dbo.CT_EVENT_TYPES as local_type', function ($join) {
                $join->on('local_type.EVENT_TYPE_CD', '=', 'e.EVENT_TYPE_CD')
                    ->where('local_type.LANGUAGE_CD', '=', 'ES');
            })
            ->leftJoin('dbo.C_EVENT_TYPES as event_type', 'event_type.EVENT_TYPE_CD', '=', 'e.EVENT_TYPE_CD')
            ->leftJoin('dbo.N_OWN_OFFICES as office', 'office.OWN_OFFICE_CD', '=', 'e.EVENT_OFFICE_CD')
            ->leftJoin('dbo.N_OWN_OFFICES as next_office', 'next_office.OWN_OFFICE_CD', '=', 'e.NEXT_OFFICE_CD')
            ->leftJoin('dbo.L_USERS as user', 'user.USER_PID', '=', 'e.USER_PID')
            ->leftJoin('dbo.C_ITEM_CONDITIONS as item_condition', 'item_condition.ITEM_CONDITION_CD', '=', 'e.CONDITION_CD')
            ->leftJoin('dbo.L_RECPTCLS as receptacle', 'receptacle.RECPTCL_PID', '=', 'e.RECPTCL_PID')
            ->leftJoin('dbo.C_POSTAL_STATUSES as postal_status', 'postal_status.POSTAL_STATUS_CD', '=', 'mi.POSTAL_STATUS_CD')
            ->leftJoin('dbo.C_MAIL_CLASSES as mail_class', 'mail_class.MAIL_CLASS_CD', '=', 'mi.MAIL_CLASS_CD')
            ->leftJoin('dbo.C_COUNTRIES as origin', 'origin.COUNTRY_CD', '=', 'mi.ORIG_COUNTRY_CD')
            ->leftJoin('dbo.C_COUNTRIES as destination', 'destination.COUNTRY_CD', '=', 'mi.DEST_COUNTRY_CD')
            ->where('e.EVENT_GMT_DT', '>=', $fromUtc->format('Y-m-d H:i:s'))
            ->where('e.EVENT_GMT_DT', '<', $untilUtc->format('Y-m-d H:i:s'))
            ->when(!empty($filters['office']), fn ($q) => $q->where('e.EVENT_OFFICE_CD', $filters['office']))
            ->when(!empty($filters['event']), fn ($q) => $q->where('e.EVENT_TYPE_CD', $filters['event']))
            ->when(!empty($filters['search']), function ($q) use ($filters) {
                $term = '%'.trim($filters['search']).'%';
                $q->where(function ($nested) use ($term) {
                    $nested->whereRaw('UPPER(RTRIM(LTRIM(mi.MAILITM_FID))) LIKE UPPER(?)', [$term])
                        ->orWhereRaw('UPPER(RTRIM(LTRIM(mi.MAILITM_LOCAL_ID))) LIKE UPPER(?)', [$term])
                        ->orWhereRaw('UPPER(COALESCE(office.OFFICE_FCD, \'\')) LIKE UPPER(?)', [$term])
                        ->orWhereRaw('UPPER(COALESCE(office.OFFICE_NM, \'\')) LIKE UPPER(?)', [$term])
                        ->orWhereRaw('UPPER(COALESCE(local_type.LOCAL_EVENT_TYPE_NM, event_type.EVENT_TYPE_NM, \'\')) LIKE UPPER(?)', [$term]);
                });
            })
            ->select([
                'mi.MAILITM_PID', 'mi.MAILITM_FID', 'mi.MAILITM_LOCAL_ID', 'mi.MAILITM_WEIGHT',
                'mi.POSTAL_STATUS_CD', 'postal_status.POSTAL_STATUS_NM', 'mi.MAIL_CLASS_CD', 'mail_class.MAIL_CLASS_NM',
                'origin.COUNTRY_NM as ORIGIN_COUNTRY', 'destination.COUNTRY_NM as DESTINATION_COUNTRY',
                'e.EVENT_GMT_DT', 'e.EVENT_TYPE_CD', 'e.EVENT_OFFICE_CD', 'e.NEXT_OFFICE_CD',
                'e.RETENTION_REASON_CD', 'e.ATTEMPTED_DELIVERY_LOCATION', 'e.CONDITION_CD',
                'e.RECPTCL_PID', 'office.OFFICE_FCD', 'office.OFFICE_NM',
                'next_office.OFFICE_FCD as NEXT_OFFICE_FCD', 'next_office.OFFICE_NM as NEXT_OFFICE_NM',
                'user.USER_FID', 'user.USER_NM', 'item_condition.ITEM_CONDITION_NM', 'receptacle.RECPTCL_FID',
            ])
            ->selectRaw('COALESCE(local_type.LOCAL_EVENT_TYPE_NM, event_type.EVENT_TYPE_NM) as EVENT_NAME')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY mi.MAILITM_PID ORDER BY e.EVENT_GMT_DT DESC, e.EVENT_TYPE_CD DESC) as event_rank');

        return $query;
    }
}
