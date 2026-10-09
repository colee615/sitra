<?php

namespace App\Services\Postal;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Aggregated, read-only delivery performance based on IPS delivery events. */
class PostalDeliveryPerformanceReport
{
    private const DELIVERY_CODES = [37, 1250];

    private function db(): Connection
    {
        return DB::connection(config('postal.ips_read_connection', config('tracking.sqlserver.connection', 'sqlsrv')));
    }

    public function offices()
    {
        return $this->db()->table('dbo.N_OWN_OFFICES')
            ->whereIn('VALID_IND', ['1', '3'])
            ->orderBy('OFFICE_NM')
            ->get(['OWN_OFFICE_CD as code', 'OFFICE_FCD as short_name', 'OFFICE_NM as name']);
    }

    public function services()
    {
        return $this->db()->table('dbo.C_MAIL_CLASSES')
            ->orderBy('MAIL_CLASS_NM')
            ->get(['MAIL_CLASS_CD as code', 'MAIL_CLASS_NM as name']);
    }

    /**
     * Build summary and detail rows. Aggregates always use the complete filtered
     * population; only the rendered detail table is capped.
     */
    public function build(array $filters, int $detailLimit = 1000): array
    {
        $db = $this->db();
        $events = $this->deliveryEvents($filters);
        $ranked = $this->uniqueDeliveries($events);
        $unique = fn () => $db->query()->fromSub(clone $ranked, 'unique_deliveries')
            ->where('delivery_rank', 1)
            ->when($filters['office'] !== '', fn ($q) => $q->where('EVENT_OFFICE_CD', $filters['office']))
            ->when($filters['user'] !== '', fn ($q) => $q->where('USER_PID', $filters['user']));

        $eventCountQuery = $db->query()->fromSub(clone $events, 'delivery_events')
            ->when($filters['office'] !== '', fn ($q) => $q->where('EVENT_OFFICE_CD', $filters['office']))
            ->when($filters['user'] !== '', fn ($q) => $q->where('USER_PID', $filters['user']));
        $eventCount = (int) $eventCountQuery->count();
        $summary = $unique()->selectRaw('COUNT(*) AS delivered, COUNT(DISTINCT USER_PID) AS registrars, COUNT(DISTINCT EVENT_OFFICE_CD) AS offices')->first();
        $users = $this->userRanking($unique(), 10)->get();
        $topUser = $users->first();
        $officeRanking = $this->officeRanking($unique(), 12)->get();
        $services = $this->serviceRanking($unique())->get();

        $days = max(1, (int) $filters['from']->diffInDays($filters['to']) + 1);
        $monthlyTrend = $days > 31;
        $dateSql = $db->getDriverName() === 'sqlite'
            ? ($monthlyTrend ? "strftime('%Y-%m', EVENT_GMT_DT, '-4 hours')" : "date(EVENT_GMT_DT, '-4 hours')")
            : ($monthlyTrend
                ? 'CONVERT(char(7), DATEADD(minute, -240, EVENT_GMT_DT), 120)'
                : 'CONVERT(char(10), DATEADD(minute, -240, EVENT_GMT_DT), 120)');
        $dailyRows = $unique()
            ->selectRaw("$dateSql AS local_date, COUNT(*) AS delivered")
            ->groupByRaw($dateSql)
            ->orderBy('local_date')
            ->get()
            ->keyBy(fn ($row) => $monthlyTrend
                ? CarbonImmutable::parse($row->local_date.'-01')->format('Y-m')
                : CarbonImmutable::parse($row->local_date)->toDateString());

        $trend = [];
        $bucket = $monthlyTrend ? $filters['from']->startOfMonth() : $filters['from'];
        $lastBucket = $monthlyTrend ? $filters['to']->startOfMonth() : $filters['to'];
        for ($date = $bucket; $date->lessThanOrEqualTo($lastBucket); $date = $monthlyTrend ? $date->addMonth() : $date->addDay()) {
            $key = $monthlyTrend ? $date->format('Y-m') : $date->toDateString();
            $trend[] = [
                'date' => $key,
                'label' => $monthlyTrend ? $date->format('m/y') : $date->format('d/m'),
                'delivered' => (int) ($dailyRows[$key]->delivered ?? 0),
            ];
        }
        $trendMax = max(1, max(array_column($trend, 'delivered') ?: [0]));
        foreach ($trend as &$point) {
            $point['height'] = $point['delivered'] > 0 ? max(5, (int) round(($point['delivered'] / $trendMax) * 100)) : 0;
        }
        unset($point);

        $rowsQuery = $unique()->orderByDesc('EVENT_GMT_DT')->orderBy('MAILITM_FID');
        $rows = (clone $rowsQuery)->limit($detailLimit + 1)->get();
        $truncated = $rows->count() > $detailLimit;

        return [
            'totals' => [
                'delivered' => (int) ($summary->delivered ?? 0),
                'delivery_events' => $eventCount,
                'registrars' => (int) ($summary->registrars ?? 0),
                'offices' => (int) ($summary->offices ?? 0),
                'daily_average' => round(((int) ($summary->delivered ?? 0)) / $days, 1),
                'top_registrar' => $topUser?->USER_NM ?: $topUser?->USER_FID ?: ($topUser ? 'Usuario sin nombre en IPS' : 'Sin registros'),
                'top_registrar_total' => (int) ($topUser->delivered ?? 0),
            ],
            'trend' => $trend,
            'trend_grain' => $monthlyTrend ? 'mes' : 'día',
            'users' => $users,
            'offices_ranking' => $officeRanking,
            'services' => $services,
            'rows' => $rows->take($detailLimit)->values(),
            'detail_total' => (int) ($summary->delivered ?? 0),
            'detail_truncated' => $truncated,
            'detail_limit' => $detailLimit,
            'generated_at' => CarbonImmutable::now(config('postal.timezone', 'America/La_Paz')),
        ];
    }

    /** All qualifying unique delivered parcels, ordered for CSV export. */
    public function exportRows(array $filters): Builder
    {
        return $this->db()->query()
            ->fromSub($this->uniqueDeliveries($this->deliveryEvents($filters)), 'unique_deliveries')
            ->where('delivery_rank', 1)
            ->when($filters['office'] !== '', fn ($q) => $q->where('EVENT_OFFICE_CD', $filters['office']))
            ->when($filters['user'] !== '', fn ($q) => $q->where('USER_PID', $filters['user']))
            ->orderByDesc('EVENT_GMT_DT')
            ->orderBy('MAILITM_FID');
    }

    private function deliveryEvents(array $filters): Builder
    {
        $db = $this->db();
        $query = $db->table('dbo.L_MAILITM_EVENTS as e')
            ->join('dbo.L_MAILITMS as mi', 'mi.MAILITM_PID', '=', 'e.MAILITM_PID')
            ->leftJoin('dbo.N_OWN_OFFICES as office', 'office.OWN_OFFICE_CD', '=', 'e.EVENT_OFFICE_CD')
            ->leftJoin('dbo.L_USERS as actor', 'actor.USER_PID', '=', 'e.USER_PID')
            ->leftJoin('dbo.C_MAIL_CLASSES as mail_class', 'mail_class.MAIL_CLASS_CD', '=', 'mi.MAIL_CLASS_CD')
            ->leftJoin('dbo.CT_EVENT_TYPES as local_type', function ($join) {
                $join->on('local_type.EVENT_TYPE_CD', '=', 'e.EVENT_TYPE_CD')
                    ->where('local_type.LANGUAGE_CD', '=', 'ES');
            })
            ->leftJoin('dbo.C_EVENT_TYPES as event_type', 'event_type.EVENT_TYPE_CD', '=', 'e.EVENT_TYPE_CD')
            ->whereIn('e.EVENT_TYPE_CD', self::DELIVERY_CODES)
            ->where('e.EVENT_GMT_DT', '>=', $filters['from_utc']->format('Y-m-d H:i:s'))
            ->where('e.EVENT_GMT_DT', '<', $filters['until_utc']->format('Y-m-d H:i:s'))
            ->when($filters['service'] !== '', fn ($q) => $q->where('mi.MAIL_CLASS_CD', $filters['service']));

        // Match the BOA postal operator coverage used by the IPS dashboard.
        $countries = $db->getDriverName() === 'sqlite'
            ? ['BO']
            : $db->table('dbo.C_OPERATORS_COUNTRIES')->where('OPERATOR_CD', 'BOA')->pluck('COUNTRY_CD')->all();
        if ($countries) {
            $query->where(fn ($q) => $q->whereIn('mi.ORIG_COUNTRY_CD', $countries)->orWhereIn('mi.DEST_COUNTRY_CD', $countries));
        } else {
            $query->whereRaw('1 = 0');
        }

        return $query->select([
            'mi.MAILITM_PID', 'mi.MAILITM_FID', 'mi.MAILITM_LOCAL_ID', 'mi.MAIL_CLASS_CD',
            'mail_class.MAIL_CLASS_NM', 'e.EVENT_GMT_DT', 'e.EVENT_TYPE_CD', 'e.EVENT_OFFICE_CD',
            'office.OFFICE_FCD', 'office.OFFICE_NM', 'e.USER_PID', 'actor.USER_FID', 'actor.USER_NM',
        ])->selectRaw('COALESCE(local_type.LOCAL_EVENT_TYPE_NM, event_type.EVENT_TYPE_NM) AS EVENT_NAME');
    }

    private function uniqueDeliveries(Builder $events): Builder
    {
        return $events->selectRaw('ROW_NUMBER() OVER (PARTITION BY e.MAILITM_PID ORDER BY e.EVENT_GMT_DT DESC, e.EVENT_TYPE_CD DESC, e.USER_PID DESC, e.EVENT_OFFICE_CD DESC) AS delivery_rank');
    }

    private function userRanking(Builder $unique, int $limit): Builder
    {
        return $unique->select('USER_PID', 'USER_FID', 'USER_NM')
            ->selectRaw('COUNT(*) AS delivered, COUNT(DISTINCT EVENT_OFFICE_CD) AS offices')
            ->groupBy('USER_PID', 'USER_FID', 'USER_NM')
            ->orderByDesc('delivered')
            ->orderBy('USER_NM')
            ->limit($limit);
    }

    private function officeRanking(Builder $unique, int $limit): Builder
    {
        return $unique->select('EVENT_OFFICE_CD', 'OFFICE_FCD', 'OFFICE_NM')
            ->selectRaw('COUNT(*) AS delivered, COUNT(DISTINCT USER_PID) AS registrars')
            ->groupBy('EVENT_OFFICE_CD', 'OFFICE_FCD', 'OFFICE_NM')
            ->orderByDesc('delivered')
            ->orderBy('OFFICE_NM')
            ->limit($limit);
    }

    private function serviceRanking(Builder $unique): Builder
    {
        return $unique->select('MAIL_CLASS_CD', 'MAIL_CLASS_NM')
            ->selectRaw('COUNT(*) AS delivered')
            ->groupBy('MAIL_CLASS_CD', 'MAIL_CLASS_NM')
            ->orderByDesc('delivered');
    }
}
