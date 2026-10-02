<?php

namespace App\Services\Postal;

use App\Services\IpsStagePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Aggregates the full filtered population; never derives totals from a page of results. */
class OperationalDashboard
{
    public const STATES = [
        'delivered' => 'Entregado', 'transit' => 'En tránsito / reparto',
        'customs' => 'En aduana', 'observed' => 'Retenido / intento fallido',
        'pending' => 'En oficina / pendiente', 'closed' => 'Operación cerrada',
        'other' => 'Otros movimientos',
    ];

    private const RECEIPTS = [1, 3, 5, 30, 32, 33, 42, 43, 44, 68, 71, 75, 78];

    private const DISPATCHES = [2, 12, 35, 72, 74];

    private const DELIVERIES = [37, 1250];

    public function ips(array $filters): array
    {
        return Cache::remember($this->key('ips', $filters), 90, fn () => $this->buildIps($filters));
    }

    private function db(): Connection
    {
        return DB::connection(config('postal.ips_read_connection', 'sqlsrv'));
    }

    private function key(string $source, array $filters): string
    {
        $connections = [];
        foreach ([config('postal.ips_read_connection'), config('postal.cds_connection')] as $name) {
            $connections[$name] = array_intersect_key(config('database.connections.'.$name, []), array_flip(['driver', 'host', 'port', 'database', 'prefix']));
        }

        return 'postal-dashboard-v1:'.$source.':'.hash('sha256', json_encode([
            $connections, config('postal.timezone'), $filters,
        ]));
    }

    public function catalog(): array
    {
        return Cache::remember($this->key('catalog', []), 1800, function () {
            $db = $this->db();

            return [
                'offices' => $db->table('dbo.N_OWN_OFFICES')->orderBy('OFFICE_NM')->get(['OWN_OFFICE_CD as code', 'OFFICE_NM as name'])->all(),
                'services' => $db->table('dbo.C_MAIL_CLASSES')->orderBy('MAIL_CLASS_CD')->get(['MAIL_CLASS_CD as code', 'MAIL_CLASS_NM as name'])->all(),
                'countries' => $db->table('dbo.C_COUNTRIES')->orderBy('COUNTRY_NM')->get(['COUNTRY_CD as code', 'COUNTRY_NM as name'])->all(),
                'states' => self::STATES,
            ];
        });
    }

    private function events(array $filters): Builder
    {
        [$from, $until] = DashboardFilters::bounds($filters);
        $q = $this->db()->table('dbo.L_MAILITM_EVENTS as e')
            ->join('dbo.L_MAILITMS as m', 'm.MAILITM_PID', '=', 'e.MAILITM_PID')
            ->where('e.EVENT_GMT_DT', '>=', $from->format('Y-m-d H:i:s'))
            ->where('e.EVENT_GMT_DT', '<', $until->format('Y-m-d H:i:s'));
        foreach (['office' => 'e.EVENT_OFFICE_CD', 'service' => 'm.MAIL_CLASS_CD', 'origin' => 'm.ORIG_COUNTRY_CD', 'destination' => 'm.DEST_COUNTRY_CD'] as $filter => $column) {
            if (($filters[$filter] ?? null) !== null && $filters[$filter] !== '') {
                $q->where($column, $filters[$filter]);
            }
        }
        if (($filters['type'] ?? null) === 'national') {
            $q->where('m.ORIG_COUNTRY_CD', 'BO')->where('m.DEST_COUNTRY_CD', 'BO');
        } elseif (($filters['type'] ?? null) === 'international') {
            $q->whereNotNull('m.ORIG_COUNTRY_CD')->whereNotNull('m.DEST_COUNTRY_CD')
                ->where('m.ORIG_COUNTRY_CD', '<>', '')->where('m.DEST_COUNTRY_CD', '<>', '')
                ->where(fn ($q) => $q->where('m.ORIG_COUNTRY_CD', '<>', 'BO')->orWhere('m.DEST_COUNTRY_CD', '<>', 'BO'));
        } elseif (($filters['type'] ?? null) === 'unknown') {
            $q->where(fn ($q) => $q->whereNull('m.ORIG_COUNTRY_CD')->orWhereNull('m.DEST_COUNTRY_CD')->orWhere('m.ORIG_COUNTRY_CD', '')->orWhere('m.DEST_COUNTRY_CD', ''));
        }

        return $q;
    }

    /** Last operational event in the selected period and office, not present-day inventory. */
    private function cohort(array $filters): Builder
    {
        $technical = implode(',', IpsStagePolicy::TECHNICAL_EVENTS);
        $ranked = $this->events($filters)->select([
            'm.MAILITM_PID', 'm.MAILITM_FID', 'm.MAIL_CLASS_CD', 'm.ORIG_COUNTRY_CD', 'm.DEST_COUNTRY_CD',
            'm.MAILITM_WEIGHT', 'm.POSTAL_STATUS_CD', 'e.EVENT_TYPE_CD', 'e.EVENT_GMT_DT', 'e.EVENT_OFFICE_CD',
        ])->selectRaw("ROW_NUMBER() OVER (PARTITION BY m.MAILITM_PID ORDER BY CASE WHEN e.EVENT_TYPE_CD IN ($technical) THEN 1 ELSE 0 END, e.EVENT_GMT_DT DESC, e.EVENT_TYPE_CD DESC, e.EVENT_OFFICE_CD DESC) AS event_rank");
        $state = "CASE WHEN EVENT_TYPE_CD IN (37,1250) THEN 'delivered'
            WHEN EVENT_TYPE_CD IN (15,76,81) THEN 'closed'
            WHEN EVENT_TYPE_CD IN (4,6,31,34) THEN 'customs'
            WHEN EVENT_TYPE_CD IN (36,69,70,73,1251,1252) THEN 'observed'
            WHEN EVENT_TYPE_CD IN (2,12,35,39,72,74) THEN 'transit'
            WHEN EVENT_TYPE_CD IN (1,3,5,30,32,33,42,43,44,68,71,75,78) THEN 'pending'
            ELSE 'other' END";
        $latest = $this->db()->query()->fromSub($ranked, 'ranked')->where('event_rank', 1)->select('ranked.*')->selectRaw("$state AS stage");

        return $this->db()->query()->fromSub($latest, 'cohort')
            ->when($filters['state'] ?? null, fn ($q, $state) => $q->where('stage', $state));
    }

    private function filteredEvents(array $filters): Builder
    {
        $q = $this->events($filters);
        if ($filters['state'] ?? null) {
            $q->whereIn('m.MAILITM_PID', $this->cohort($filters)->select('MAILITM_PID'));
        }

        return $q;
    }

    private function totals(array $filters): array
    {
        $receipts = implode(',', self::RECEIPTS);
        $dispatches = implode(',', self::DISPATCHES);
        $deliveries = implode(',', self::DELIVERIES);
        $row = $this->filteredEvents($filters)->selectRaw("COUNT(*) AS movements, COUNT(DISTINCT m.MAILITM_PID) AS packages,
            COUNT(DISTINCT CASE WHEN e.EVENT_TYPE_CD IN ($receipts) THEN m.MAILITM_PID END) AS received,
            COUNT(DISTINCT CASE WHEN e.EVENT_TYPE_CD IN ($dispatches) THEN m.MAILITM_PID END) AS dispatched,
            COUNT(DISTINCT CASE WHEN e.EVENT_TYPE_CD IN ($deliveries) THEN m.MAILITM_PID END) AS delivered,
            COALESCE(SUM(CASE WHEN e.EVENT_TYPE_CD IN ($receipts) THEN 1 ELSE 0 END),0) AS entries,
            COALESCE(SUM(CASE WHEN e.EVENT_TYPE_CD IN ($dispatches) THEN 1 ELSE 0 END),0) AS exits,
            COUNT(DISTINCT e.EVENT_OFFICE_CD) AS offices, COUNT(DISTINCT e.USER_PID) AS operators")->first();

        return array_map('intval', (array) $row);
    }

    private function buildIps(array $filters): array
    {
        $db = $this->db();
        $cohort = $this->cohort($filters);
        $totals = $this->totals($filters);
        [$from, $until] = DashboardFilters::bounds($filters);
        $days = (int) $from->diffInDays($until);
        $previousFilters = array_merge($filters, [
            'from' => CarbonImmutable::parse($filters['from'])->subDays($days)->toDateString(),
            'to' => CarbonImmutable::parse($filters['from'])->subDay()->toDateString(),
        ]);
        $previous = $this->totals($previousFilters);
        $states = (clone $cohort)->select('stage')->selectRaw('COUNT(*) AS total')->groupBy('stage')->pluck('total', 'stage')->all();
        $weight = (clone $cohort)->selectRaw('SUM(MAILITM_WEIGHT) AS total, AVG(MAILITM_WEIGHT) AS average, COUNT(MAILITM_WEIGHT) AS known')->first();
        // Postal statuses 6 and 7 refer to items. Codes 22/23 belong to receptacles, not returned items.
        $returns = (clone $cohort)->whereIn('POSTAL_STATUS_CD', [6, 7])->count();
        $typeSql = "CASE WHEN ORIG_COUNTRY_CD IS NULL OR DEST_COUNTRY_CD IS NULL OR ORIG_COUNTRY_CD = '' OR DEST_COUNTRY_CD = '' THEN 'Sin origen/destino'
            WHEN ORIG_COUNTRY_CD = 'BO' AND DEST_COUNTRY_CD = 'BO' THEN 'Nacional' ELSE 'Internacional' END";
        $types = (clone $cohort)->selectRaw("$typeSql AS name, COUNT(*) AS total")->groupByRaw($typeSql)->get()->all();
        $services = (clone $cohort)->leftJoin('dbo.C_MAIL_CLASSES as s', 's.MAIL_CLASS_CD', '=', 'cohort.MAIL_CLASS_CD')
            ->select('cohort.MAIL_CLASS_CD as code', 's.MAIL_CLASS_NM as name')->selectRaw('COUNT(*) AS total')->groupBy('cohort.MAIL_CLASS_CD', 's.MAIL_CLASS_NM')->orderByDesc('total')->get()->all();
        $offices = $this->filteredEvents($filters)->leftJoin('dbo.N_OWN_OFFICES as o', 'o.OWN_OFFICE_CD', '=', 'e.EVENT_OFFICE_CD')
            ->select('e.EVENT_OFFICE_CD as code', 'o.OFFICE_NM as name')->selectRaw('COUNT(*) AS total, COUNT(DISTINCT m.MAILITM_PID) AS packages')
            ->groupBy('e.EVENT_OFFICE_CD', 'o.OFFICE_NM')->orderByDesc('total')->get()->all();
        $geography = [];
        foreach (['origin' => 'ORIG_COUNTRY_CD', 'destination' => 'DEST_COUNTRY_CD'] as $key => $col) {
            $geography[$key] = (clone $cohort)->leftJoin('dbo.C_COUNTRIES as c', 'c.COUNTRY_CD', '=', 'cohort.'.$col)
                ->select('cohort.'.$col.' as code', 'c.COUNTRY_NM as name')->selectRaw('COUNT(*) AS total')
                ->groupBy('cohort.'.$col, 'c.COUNTRY_NM')->orderByDesc('total')->get()->all();
        }
        $receipts = implode(',', self::RECEIPTS);
        $dispatches = implode(',', self::DISPATCHES);
        $dateSql = $db->getDriverName() === 'sqlite' ? "date(e.EVENT_GMT_DT, '-4 hours')" : 'CONVERT(date, DATEADD(minute, -240, e.EVENT_GMT_DT))';
        $daily = $this->filteredEvents($filters)->selectRaw("$dateSql AS date, COUNT(*) AS movements,
            COUNT(DISTINCT m.MAILITM_PID) AS packages,
            SUM(CASE WHEN e.EVENT_TYPE_CD IN ($receipts) THEN 1 ELSE 0 END) AS entries,
            SUM(CASE WHEN e.EVENT_TYPE_CD IN ($dispatches) THEN 1 ELSE 0 END) AS exits,
            SUM(CASE WHEN e.EVENT_TYPE_CD IN (37,1250) THEN 1 ELSE 0 END) AS deliveries")
            ->groupByRaw($dateSql)->orderBy('date')->get()->keyBy('date');
        $timeline = [];
        for ($date = CarbonImmutable::parse($filters['from']); $date->toDateString() <= $filters['to']; $date = $date->addDay()) {
            $key = $date->toDateString();
            $timeline[] = ['date' => $key] + array_map('intval', (array) ($daily[$key] ?? (object) ['movements' => 0, 'packages' => 0, 'entries' => 0, 'exits' => 0, 'deliveries' => 0]));
        }
        $frequent = $this->filteredEvents($filters)->leftJoin('dbo.C_EVENT_TYPES as t', 't.EVENT_TYPE_CD', '=', 'e.EVENT_TYPE_CD')
            ->leftJoin('dbo.CT_EVENT_TYPES as es', fn ($join) => $join->on('es.EVENT_TYPE_CD', '=', 'e.EVENT_TYPE_CD')->where('es.LANGUAGE_CD', 'ES'))
            ->select('e.EVENT_TYPE_CD as code')->selectRaw('COALESCE(es.LOCAL_EVENT_TYPE_NM, t.EVENT_TYPE_NM) AS name, COUNT(*) AS total')
            ->groupBy('e.EVENT_TYPE_CD', 'es.LOCAL_EVENT_TYPE_NM', 't.EVENT_TYPE_NM')->orderByDesc('total')->get()->all();
        $serviceFlow = $this->filteredEvents($filters)->leftJoin('dbo.C_MAIL_CLASSES as s', 's.MAIL_CLASS_CD', '=', 'm.MAIL_CLASS_CD')
            ->select('m.MAIL_CLASS_CD as code', 's.MAIL_CLASS_NM as name')->selectRaw("COUNT(*) AS movements, COUNT(DISTINCT m.MAILITM_PID) AS packages,
                SUM(CASE WHEN e.EVENT_TYPE_CD IN ($receipts) THEN 1 ELSE 0 END) AS entries,
                SUM(CASE WHEN e.EVENT_TYPE_CD IN ($dispatches) THEN 1 ELSE 0 END) AS exits")
            ->groupBy('m.MAIL_CLASS_CD', 's.MAIL_CLASS_NM')->orderByDesc('movements')->get()->all();
        $recent = (clone $cohort)->orderByDesc('EVENT_GMT_DT')->orderBy('MAILITM_PID')->limit(12)->get(['MAILITM_FID as code', 'stage', 'EVENT_GMT_DT as date', 'MAIL_CLASS_CD as service'])->all();
        $inventory = Cache::remember($this->key('inventory', []), 1800, fn () => $db->table('dbo.L_MAILITMS')->count());

        return compact('totals', 'previous', 'previousFilters', 'states', 'weight', 'returns', 'types', 'services', 'offices', 'geography', 'timeline', 'frequent', 'serviceFlow', 'recent', 'inventory') + [
            'status' => 'ok', 'generated_at' => CarbonImmutable::now(config('postal.timezone'))->toIso8601String(), 'filters' => $filters,
        ];
    }

    public function cds(array $filters): array
    {
        if (! config('postal.cds_enabled')) {
            return ['status' => 'disabled'];
        }
        // IPS office/state/country dimensions cannot be reliably joined to CDS organisations.
        if (collect(['office', 'state', 'origin', 'destination', 'type'])->contains(fn ($key) => ($filters[$key] ?? null) !== null && $filters[$key] !== '')) {
            return ['status' => 'unsupported', 'message' => 'CDS no dispone de una equivalencia verificada para los filtros de oficina, estado postal, país o tipo. Limpia esos filtros para consultar sus cifras.'];
        }

        return Cache::remember($this->key('cds', $filters), 90, function () use ($filters) {
            $db = DB::connection(config('postal.cds_connection', 'cds'));
            // POSTING_DATE has no recorded timezone: retain its source calendar date.
            $base = $db->table('dbo.O_MAIL_OBJECTS as m')->where('m.POSTING_DATE', '>=', $filters['from'].' 00:00:00')
                ->where('m.POSTING_DATE', '<', CarbonImmutable::parse($filters['to'])->addDay()->format('Y-m-d H:i:s'))
                ->when($filters['service'] ?? null, fn ($q, $service) => $q->where('m.MAIL_CLASS_CD', $service));
            $objects = (clone $base)->count();
            $declarations = (clone $base)->join('dbo.O_DECLARATIONS as d', 'd.MAIL_OBJECT_PID', '=', 'm.MAIL_OBJECT_PID')->count();
            $responses = (clone $base)->join('dbo.O_RESPONSES as r', 'r.MAIL_OBJECT_PID', '=', 'm.MAIL_OBJECT_PID')->count();
            $withoutResponse = (clone $base)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('dbo.O_RESPONSES as r')->whereColumn('r.MAIL_OBJECT_PID', 'm.MAIL_OBJECT_PID'))->count();
            $states = (clone $base)->join('dbo.O_DECLARATIONS as d', 'd.MAIL_OBJECT_PID', '=', 'm.MAIL_OBJECT_PID')
                ->leftJoin('dbo.M_CDS_STATES as s', 's.CDS_STATE_CD', '=', 'd.CDS_STATE_CD')->select('s.CDS_STATE_NM as name')
                ->selectRaw('COUNT(*) AS total')->groupBy('s.CDS_STATE_NM')->orderByDesc('total')->get()->all();

            return compact('objects', 'declarations', 'responses', 'withoutResponse', 'states') + ['status' => 'ok', 'generated_at' => now(config('postal.timezone'))->toIso8601String()];
        });
    }
}
