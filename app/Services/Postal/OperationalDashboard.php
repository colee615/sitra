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

    public function ips(array $filters, bool $detailed = false): array
    {
        return Cache::remember($this->key($detailed ? 'ips-report' : 'ips', $filters), 90, fn () => $this->buildIps($filters, $detailed));
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

        return 'postal-dashboard-v6:'.$source.':'.hash('sha256', json_encode([
            $connections, config('postal.timezone'), $filters,
        ]));
    }

    public function catalog(): array
    {
        return Cache::remember($this->key('catalog', []), 1800, function () {
            $db = $this->db();

            return [
                'offices' => $db->table('dbo.N_OWN_OFFICES')->whereIn('VALID_IND', ['1', '3'])->orderBy('OFFICE_NM')->get(['OWN_OFFICE_CD as code', 'OFFICE_NM as name'])->all(),
                'services' => $db->table('dbo.C_MAIL_CLASSES')->orderBy('MAIL_CLASS_CD')->get(['MAIL_CLASS_CD as code', 'MAIL_CLASS_NM as name'])->all(),
                'countries' => $db->table('dbo.C_COUNTRIES')->orderBy('COUNTRY_NM')->get(['COUNTRY_CD as code', 'COUNTRY_NM as name'])->all(),
                'states' => self::STATES,
            ];
        });
    }

    private function operatorCountries(?string $operator): array
    {
        if (! $operator) {
            return [];
        }

        return Cache::remember($this->key('operator-countries', ['operator' => $operator]), 1800, function () use ($operator) {
            $db = $this->db();

            return $db->getDriverName() === 'sqlite'
                ? ($operator === 'BOA' ? ['BO'] : [])
                : $db->table('dbo.C_OPERATORS_COUNTRIES')->where('OPERATOR_CD', $operator)->pluck('COUNTRY_CD')->all();
        });
    }

    private function directionMetrics(array $countries): array
    {
        if (! $countries) {
            $receipts = implode(',', self::RECEIPTS);
            $dispatches = implode(',', self::DISPATCHES);

            return [
                'received' => "COUNT(DISTINCT CASE WHEN e.EVENT_TYPE_CD IN ($receipts) THEN m.MAILITM_PID END)",
                'dispatched' => "COUNT(DISTINCT CASE WHEN e.EVENT_TYPE_CD IN ($dispatches) THEN m.MAILITM_PID END)",
                'bindings' => [],
            ];
        }

        $placeholders = implode(',', array_fill(0, count($countries), '?'));

        return [
            'received' => "COUNT(DISTINCT CASE WHEN m.DEST_COUNTRY_CD IN ($placeholders) THEN m.MAILITM_PID END)",
            'dispatched' => "COUNT(DISTINCT CASE WHEN m.ORIG_COUNTRY_CD IN ($placeholders) THEN m.MAILITM_PID END)",
            'bindings' => array_merge($countries, $countries),
        ];
    }

    private function events(array $filters): Builder
    {
        [$from, $until] = DashboardFilters::bounds($filters);
        $db = $this->db();
        $q = $db->table('dbo.L_MAILITM_EVENTS as e')
            ->join('dbo.L_MAILITMS as m', 'm.MAILITM_PID', '=', 'e.MAILITM_PID')
            ->where('e.EVENT_GMT_DT', '>=', $from->format('Y-m-d H:i:s'))
            ->where('e.EVENT_GMT_DT', '<', $until->format('Y-m-d H:i:s'));
        $operator = $filters['operator'] ?? null;
        if ($operator) {
            $countries = $this->operatorCountries($operator);
            if ($countries) {
                $q->where(fn ($operatorQuery) => $operatorQuery->whereIn('m.ORIG_COUNTRY_CD', $countries)->orWhereIn('m.DEST_COUNTRY_CD', $countries));
            } else {
                $q->whereRaw('1 = 0');
            }
        }
        foreach (['office' => 'e.EVENT_OFFICE_CD', 'service' => 'm.MAIL_CLASS_CD', 'origin' => 'm.ORIG_COUNTRY_CD', 'destination' => 'm.DEST_COUNTRY_CD'] as $filter => $column) {
            if (($filters[$filter] ?? null) !== null && $filters[$filter] !== '') {
                if ($filter === 'office' && ! ctype_digit((string) $filters[$filter])) {
                    $q->whereRaw('1 = 0');
                    continue;
                }
                $q->where($column, $filters[$filter]);
            }
        }
        if (($filters['mail_category'] ?? null) !== null && ($filters['mail_category'] ?? '') !== '') {
            if ($db->getDriverName() === 'sqlite') {
                $q->whereRaw('1 = 0');
            } else {
                $category = $filters['mail_category'];
                $q->where(fn ($categoryQuery) => $categoryQuery
                    ->whereExists(fn ($sub) => $sub->selectRaw('1')->from('dbo.L_LETTERS as l')->whereColumn('l.MAILITM_PID', 'm.MAILITM_PID')->where('l.MAIL_CATEGORY_CD', $category))
                    ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('dbo.L_PARCELS as p')->whereColumn('p.MAILITM_PID', 'm.MAILITM_PID')->where('p.MAIL_CATEGORY_CD', $category)));
            }
        }
        if (($filters['mail_product'] ?? null) !== null && ($filters['mail_product'] ?? '') !== '') {
            $product = substr($filters['mail_product'], 0, 1);
            $firstCharacter = $db->getDriverName() === 'sqlite' ? 'SUBSTR(m.MAILITM_FID, 1, 1)' : 'SUBSTRING(m.MAILITM_FID, 1, 1)';
            $q->whereRaw("UPPER($firstCharacter) = ?", [$product]);
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
    private function cohort(array $filters, bool $includeProductType = false): Builder
    {
        $technical = implode(',', IpsStagePolicy::TECHNICAL_EVENTS);
        $columns = [
            'm.MAILITM_PID', 'm.MAILITM_FID', 'm.MAIL_CLASS_CD', 'm.ORIG_COUNTRY_CD', 'm.DEST_COUNTRY_CD',
            'm.MAILITM_WEIGHT', 'm.POSTAL_STATUS_CD', 'e.EVENT_TYPE_CD', 'e.EVENT_GMT_DT', 'e.EVENT_OFFICE_CD',
        ];
        if ($includeProductType) {
            $columns[] = 'm.PRODUCT_TYPE_CD';
        }
        $ranked = $this->events($filters);
        if ($includeProductType && $this->db()->getDriverName() !== 'sqlite') {
            $ranked->leftJoin('dbo.L_LETTERS as letter', 'letter.MAILITM_PID', '=', 'm.MAILITM_PID')
                ->leftJoin('dbo.L_PARCELS as parcel', 'parcel.MAILITM_PID', '=', 'm.MAILITM_PID');
        }
        $ranked->select($columns);
        if ($includeProductType) {
            $ranked->selectRaw($this->db()->getDriverName() === 'sqlite'
                ? 'NULL AS MAIL_CATEGORY_CD'
                : "COALESCE(letter.MAIL_CATEGORY_CD, parcel.MAIL_CATEGORY_CD) AS MAIL_CATEGORY_CD");
        }
        $ranked->selectRaw("ROW_NUMBER() OVER (PARTITION BY m.MAILITM_PID ORDER BY CASE WHEN e.EVENT_TYPE_CD IN ($technical) THEN 1 ELSE 0 END, e.EVENT_GMT_DT DESC, e.EVENT_TYPE_CD DESC, e.EVENT_OFFICE_CD DESC) AS event_rank");
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
        $countries = $this->operatorCountries($filters['operator'] ?? null);
        $direction = $this->directionMetrics($countries);
        $row = $this->filteredEvents($filters)->selectRaw("COUNT(*) AS movements, COUNT(DISTINCT m.MAILITM_PID) AS packages,
            {$direction['received']} AS received,
            {$direction['dispatched']} AS dispatched,
            COUNT(DISTINCT CASE WHEN e.EVENT_TYPE_CD IN ($deliveries) THEN m.MAILITM_PID END) AS delivered,
            COALESCE(SUM(CASE WHEN e.EVENT_TYPE_CD IN ($receipts) THEN 1 ELSE 0 END),0) AS entries,
            COALESCE(SUM(CASE WHEN e.EVENT_TYPE_CD IN ($dispatches) THEN 1 ELSE 0 END),0) AS exits,
            COUNT(DISTINCT e.EVENT_OFFICE_CD) AS offices, COUNT(DISTINCT e.USER_PID) AS operators", $direction['bindings'])->first();

        return array_map('intval', (array) $row);
    }

    private function buildIps(array $filters, bool $detailed = false): array
    {
        $db = $this->db();
        $cohort = $this->cohort($filters, $detailed);
        $totals = $this->totals($filters);
        $operatorCountries = $this->operatorCountries($filters['operator'] ?? null);
        $direction = $this->directionMetrics($operatorCountries);
        [$from, $until] = DashboardFilters::bounds($filters);
        $days = (int) $from->diffInDays($until);
        $previousFrom = ($filters['comparison'] ?? 'period') === 'year'
            ? CarbonImmutable::parse($filters['from'])->subYear()->toDateString()
            : CarbonImmutable::parse($filters['from'])->subDays($days)->toDateString();
        $previousTo = ($filters['comparison'] ?? 'period') === 'year'
            ? CarbonImmutable::parse($filters['to'])->subYear()->toDateString()
            : CarbonImmutable::parse($filters['from'])->subDay()->toDateString();
        $previousFilters = array_merge($filters, [
            'from' => $previousFrom,
            'to' => $previousTo,
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
                ->when($operatorCountries, fn ($q) => $q->whereNotIn('cohort.'.$col, $operatorCountries))
                ->select('cohort.'.$col.' as code', 'c.COUNTRY_NM as name')->selectRaw('COUNT(*) AS total')
                ->groupBy('cohort.'.$col, 'c.COUNTRY_NM')->orderByDesc('total')->get()->all();
        }
        $receipts = implode(',', self::RECEIPTS);
        $dispatches = implode(',', self::DISPATCHES);
        $dateSql = $db->getDriverName() === 'sqlite' ? "date(e.EVENT_GMT_DT, '-4 hours')" : 'CONVERT(date, DATEADD(minute, -240, e.EVENT_GMT_DT))';
        $daily = $this->filteredEvents($filters)->selectRaw("$dateSql AS date, COUNT(*) AS movements,
            COUNT(DISTINCT m.MAILITM_PID) AS packages,
            {$direction['received']} AS received,
            {$direction['dispatched']} AS dispatched,
            SUM(CASE WHEN e.EVENT_TYPE_CD IN ($receipts) THEN 1 ELSE 0 END) AS entries,
            SUM(CASE WHEN e.EVENT_TYPE_CD IN ($dispatches) THEN 1 ELSE 0 END) AS exits,
            SUM(CASE WHEN e.EVENT_TYPE_CD IN (37,1250) THEN 1 ELSE 0 END) AS deliveries", $direction['bindings'])
            ->groupByRaw($dateSql)->orderBy('date')->get()->keyBy('date');
        $timeline = [];
        for ($date = CarbonImmutable::parse($filters['from']); $date->toDateString() <= $filters['to']; $date = $date->addDay()) {
            $key = $date->toDateString();
            $timeline[] = ['date' => $key] + array_map('intval', (array) ($daily[$key] ?? (object) ['movements' => 0, 'packages' => 0, 'received' => 0, 'dispatched' => 0, 'entries' => 0, 'exits' => 0, 'deliveries' => 0]));
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
        $report = ['previous_states' => [], 'previous_returns' => 0, 'no_product' => 0, 'previous_no_product' => 0, 'categories' => [], 'products' => [], 'hourly' => [], 'monthly' => [], 'hourly_services' => [], 'monthly_services' => []];
        if ($detailed) {
            $previousCohort = $this->cohort($previousFilters, true);
            $report['previous_states'] = (clone $previousCohort)->select('stage')->selectRaw('COUNT(*) AS total')->groupBy('stage')->pluck('total', 'stage')->all();
            $report['previous_returns'] = (clone $previousCohort)->whereIn('POSTAL_STATUS_CD', [6, 7])->count();
            $report['no_product'] = (clone $cohort)->where(fn ($q) => $q->whereNull('PRODUCT_TYPE_CD')->orWhere('PRODUCT_TYPE_CD', ''))->count();
            $report['previous_no_product'] = (clone $previousCohort)->where(fn ($q) => $q->whereNull('PRODUCT_TYPE_CD')->orWhere('PRODUCT_TYPE_CD', ''))->count();
            if ($db->getDriverName() === 'sqlite') {
                $report['categories'] = [];
                $report['products'] = (clone $cohort)->selectRaw("COALESCE(NULLIF(PRODUCT_TYPE_CD, ''), 'Sin tipo UPU') AS code, COUNT(*) AS total")
                    ->groupByRaw("COALESCE(NULLIF(PRODUCT_TYPE_CD, ''), 'Sin tipo UPU')")->orderByDesc('total')->get()->all();
            } else {
                $categorySql = "COALESCE(NULLIF(LTRIM(RTRIM(MAIL_CATEGORY_CD)), ''), 'Sin categoría')";
                $report['categories'] = (clone $cohort)->selectRaw("$categorySql AS code, COUNT(*) AS total")
                    ->groupByRaw($categorySql)->orderByDesc('total')->get()->all();
                $identifierSql = "UPPER(REPLACE(REPLACE(LTRIM(RTRIM(MAILITM_FID)), '-', ''), ' ', ''))";
                $validS10 = "$identifierSql LIKE '[A-Z][A-Z][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][A-Z][A-Z]'";
                $productSql = "CASE WHEN $validS10 THEN LEFT($identifierSql, 1) + 'A-' + LEFT($identifierSql, 1) + 'Z' ELSE 'Sin identificador S10' END";
                $report['products'] = (clone $cohort)->selectRaw("$productSql AS code, COUNT(*) AS total")
                    ->groupByRaw($productSql)->orderByDesc('total')->get()->all();
            }
            $hourSql = $db->getDriverName() === 'sqlite'
                ? "CAST(strftime('%H', datetime(e.EVENT_GMT_DT, '-4 hours')) AS INTEGER)"
                : 'DATEPART(hour, DATEADD(minute, -240, e.EVENT_GMT_DT))';
            $hourRows = $this->filteredEvents($filters)->selectRaw("$hourSql AS hour, COUNT(DISTINCT m.MAILITM_PID) AS items, COUNT(*) AS movements,
                {$direction['received']} AS received,
                {$direction['dispatched']} AS dispatched", $direction['bindings'])
                ->groupByRaw($hourSql)->get()->keyBy('hour');
            for ($hour = 0; $hour < 24; $hour++) {
                $row = $hourRows[$hour] ?? null;
                $report['hourly'][] = ['hour' => $hour, 'items' => (int) ($row->items ?? 0), 'movements' => (int) ($row->movements ?? 0), 'received' => (int) ($row->received ?? 0), 'dispatched' => (int) ($row->dispatched ?? 0)];
            }
            $hourServiceRows = $this->filteredEvents($filters)->leftJoin('dbo.C_MAIL_CLASSES as s', 's.MAIL_CLASS_CD', '=', 'm.MAIL_CLASS_CD')
                ->selectRaw("$hourSql AS hour")->addSelect('m.MAIL_CLASS_CD as code', 's.MAIL_CLASS_NM as name')
                ->selectRaw('COUNT(DISTINCT m.MAILITM_PID) AS total')->groupByRaw($hourSql)->groupBy('m.MAIL_CLASS_CD', 's.MAIL_CLASS_NM')->get()->all();
            $report['hourly_services'] = $hourServiceRows;
            $trendFrom = CarbonImmutable::parse($filters['from'])->startOfMonth()->toDateString();
            $monthlyFilters = array_merge($filters, ['from' => $trendFrom]);
            $monthSql = $db->getDriverName() === 'sqlite'
                ? "strftime('%Y-%m-01', datetime(e.EVENT_GMT_DT, '-4 hours'))"
                : "CONVERT(char(7), DATEADD(minute, -240, e.EVENT_GMT_DT), 120) + '-01'";
            $monthRows = $this->filteredEvents($monthlyFilters)->selectRaw("$monthSql AS date, COUNT(DISTINCT m.MAILITM_PID) AS items,
                {$direction['received']} AS received,
                {$direction['dispatched']} AS dispatched", $direction['bindings'])
                ->groupByRaw($monthSql)->orderBy('date')->get()->keyBy(fn ($row) => CarbonImmutable::parse($row->date)->toDateString());
            for ($month = CarbonImmutable::parse($trendFrom)->startOfMonth(); $month->toDateString() <= CarbonImmutable::parse($filters['to'])->startOfMonth()->toDateString(); $month = $month->addMonth()) {
                $key = $month->toDateString(); $row = $monthRows[$key] ?? null;
                $report['monthly'][] = ['date' => $key, 'items' => (int) ($row->items ?? 0), 'received' => (int) ($row->received ?? 0), 'dispatched' => (int) ($row->dispatched ?? 0)];
            }
            $report['monthly_services'] = $this->filteredEvents($monthlyFilters)->leftJoin('dbo.C_MAIL_CLASSES as s', 's.MAIL_CLASS_CD', '=', 'm.MAIL_CLASS_CD')
                ->selectRaw("$monthSql AS date")->addSelect('m.MAIL_CLASS_CD as code', 's.MAIL_CLASS_NM as name')
                ->selectRaw('COUNT(DISTINCT m.MAILITM_PID) AS total')->groupByRaw($monthSql)->groupBy('m.MAIL_CLASS_CD', 's.MAIL_CLASS_NM')->get()->all();
        }
        $inventory = Cache::remember($this->key('inventory', []), 1800, fn () => $db->table('dbo.L_MAILITMS')->count());

        return compact('totals', 'previous', 'previousFilters', 'states', 'weight', 'returns', 'types', 'services', 'offices', 'geography', 'timeline', 'frequent', 'serviceFlow', 'recent', 'inventory') + $report + [
            'status' => 'ok', 'generated_at' => CarbonImmutable::now(config('postal.timezone'))->toIso8601String(), 'filters' => $filters,
        ];
    }

    public function cds(array $filters, bool $detailed = false): array
    {
        if (! config('postal.cds_enabled')) {
            return ['status' => 'disabled'];
        }
        // CDS office events are supported; the remaining IPS dimensions cannot be reliably joined to CDS organisations.
        if (collect(['state', 'origin', 'destination', 'type'])->contains(fn ($key) => ($filters[$key] ?? null) !== null && $filters[$key] !== '')) {
            return ['status' => 'unsupported', 'message' => 'CDS no dispone de una equivalencia verificada para los filtros de estado postal, país o tipo. Limpia esos filtros para consultar sus cifras.'];
        }

        return Cache::remember($this->key($detailed ? 'cds-detail' : 'cds', $filters), 90, function () use ($filters, $detailed) {
            $db = DB::connection(config('postal.cds_connection', 'cds'));
            // POSTING_DATE has no recorded timezone: retain its source calendar date.
            $baseFor = function (string $from, string $to) use ($db, $filters) {
                $base = $db->table('dbo.O_MAIL_OBJECTS as m')->where('m.POSTING_DATE', '>=', $from.' 00:00:00')
                    ->where('m.POSTING_DATE', '<', CarbonImmutable::parse($to)->addDay()->format('Y-m-d H:i:s'))
                    ->when($filters['service'] ?? null, fn ($q, $service) => $q->where('m.MAIL_CLASS_CD', $service))
                    ->when($filters['cds_origin_operator'] ?? null, fn ($q, $operator) => $q->where('m.ORIG_POST_ORGANIZATION_CD', $operator))
                    ->when($filters['cds_destination_operator'] ?? null, fn ($q, $operator) => $q->where('m.DEST_POST_ORGANIZATION_CD', $operator))
                    ->when($filters['cds_state'] ?? null, fn ($q, $state) => $q->whereExists(fn ($sub) => $sub->selectRaw('1')->from('dbo.O_DECLARATIONS as d')->whereColumn('d.MAIL_OBJECT_PID', 'm.MAIL_OBJECT_PID')->where('d.CDS_STATE_CD', $state)));

                if ($filters['operator'] ?? null) {
                    $operator = $filters['operator'];
                    $base->where(fn ($q) => $q->where('m.ORIG_POST_ORGANIZATION_CD', $operator)->orWhere('m.DEST_POST_ORGANIZATION_CD', $operator));
                }

                if (($filters['office'] ?? null) && $db->getDriverName() !== 'sqlite') {
                    $timezone = config('postal.timezone');
                    $officeFrom = CarbonImmutable::parse($from, $timezone)->startOfDay()->utc()->format('Y-m-d H:i:s');
                    $officeUntil = CarbonImmutable::parse($to, $timezone)->addDay()->startOfDay()->utc()->format('Y-m-d H:i:s');
                    $base->where(function ($officeQuery) use ($filters, $officeFrom, $officeUntil) {
                        $office = $filters['office'];
                        $officeQuery->whereExists(fn ($sub) => $sub->selectRaw('1')->from('dbo.O_DECLARATIONS as d')
                            ->join('dbo.O_DECLARATION_EVENTS as e', 'e.DECLARATION_PID', '=', 'd.DECLARATION_PID')
                            ->whereColumn('d.MAIL_OBJECT_PID', 'm.MAIL_OBJECT_PID')->where('e.OFFICE_CD', $office)
                            ->where('e.D_EVENT_GMT_DT', '>=', $officeFrom)->where('e.D_EVENT_GMT_DT', '<', $officeUntil))
                            ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('dbo.O_RESPONSES as r')
                                ->join('dbo.O_RESPONSE_EVENTS as e', 'e.RESPONSE_PID', '=', 'r.RESPONSE_PID')
                                ->whereColumn('r.MAIL_OBJECT_PID', 'm.MAIL_OBJECT_PID')->where('e.OFFICE_CD', $office)
                                ->where('e.R_EVENT_GMT_DT', '>=', $officeFrom)->where('e.R_EVENT_GMT_DT', '<', $officeUntil));
                    });
                }

                return $base;
            };
            $base = $baseFor($filters['from'], $filters['to']);
            $objects = (clone $base)->count();
            $declarations = (clone $base)->join('dbo.O_DECLARATIONS as d', 'd.MAIL_OBJECT_PID', '=', 'm.MAIL_OBJECT_PID')->count();
            $responses = (clone $base)->join('dbo.O_RESPONSES as r', 'r.MAIL_OBJECT_PID', '=', 'm.MAIL_OBJECT_PID')->count();
            $declaredObjects = $respondedObjects = $withoutDeclaration = 0;
            $withoutResponse = (clone $base)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('dbo.O_RESPONSES as r')->whereColumn('r.MAIL_OBJECT_PID', 'm.MAIL_OBJECT_PID'))->count();
            $states = (clone $base)->join('dbo.O_DECLARATIONS as d', 'd.MAIL_OBJECT_PID', '=', 'm.MAIL_OBJECT_PID')
                ->leftJoin('dbo.M_CDS_STATES as s', 's.CDS_STATE_CD', '=', 'd.CDS_STATE_CD')->select('d.CDS_STATE_CD as code', 's.CDS_STATE_NM as name')
                ->selectRaw('COUNT(*) AS total')->groupBy('d.CDS_STATE_CD', 's.CDS_STATE_NM')->orderByDesc('total')->get()->all();
            $services = $origins = $destinations = $categories = $products = $timeline = $hourly = $monthly = $previous = $offices = [];
            if ($detailed) {
                if ($db->getDriverName() !== 'sqlite') {
                    $offices = $db->table('dbo.M_OFFICES')->where('POSTAL_ORGANIZATION_CD', $filters['operator'] ?? 'BOA')->orderBy('OFFICE_NM')
                        ->get(['OFFICE_CD as code', 'OFFICE_NM as name'])->all();
                }
                $declaredObjects = (clone $base)->whereExists(fn ($q) => $q->selectRaw('1')->from('dbo.O_DECLARATIONS as d')->whereColumn('d.MAIL_OBJECT_PID', 'm.MAIL_OBJECT_PID'))->count();
                $respondedObjects = (clone $base)->whereExists(fn ($q) => $q->selectRaw('1')->from('dbo.O_RESPONSES as r')->whereColumn('r.MAIL_OBJECT_PID', 'm.MAIL_OBJECT_PID'))->count();
                $withoutDeclaration = (clone $base)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('dbo.O_DECLARATIONS as d')->whereColumn('d.MAIL_OBJECT_PID', 'm.MAIL_OBJECT_PID'))->count();
                $days = (int) CarbonImmutable::parse($filters['from'])->diffInDays(CarbonImmutable::parse($filters['to'])->addDay());
                $previousFrom = ($filters['comparison'] ?? 'period') === 'year'
                    ? CarbonImmutable::parse($filters['from'])->subYear()->toDateString()
                    : CarbonImmutable::parse($filters['from'])->subDays($days)->toDateString();
                $previousTo = ($filters['comparison'] ?? 'period') === 'year'
                    ? CarbonImmutable::parse($filters['to'])->subYear()->toDateString()
                    : CarbonImmutable::parse($filters['from'])->subDay()->toDateString();
                $previousBase = $baseFor($previousFrom, $previousTo);
                $previous = [
                    'objects' => (clone $previousBase)->count(),
                    'declarations' => (clone $previousBase)->join('dbo.O_DECLARATIONS as d', 'd.MAIL_OBJECT_PID', '=', 'm.MAIL_OBJECT_PID')->count(),
                    'responses' => (clone $previousBase)->join('dbo.O_RESPONSES as r', 'r.MAIL_OBJECT_PID', '=', 'm.MAIL_OBJECT_PID')->count(),
                    'declaredObjects' => (clone $previousBase)->whereExists(fn ($q) => $q->selectRaw('1')->from('dbo.O_DECLARATIONS as d')->whereColumn('d.MAIL_OBJECT_PID', 'm.MAIL_OBJECT_PID'))->count(),
                    'respondedObjects' => (clone $previousBase)->whereExists(fn ($q) => $q->selectRaw('1')->from('dbo.O_RESPONSES as r')->whereColumn('r.MAIL_OBJECT_PID', 'm.MAIL_OBJECT_PID'))->count(),
                    'withoutResponse' => (clone $previousBase)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('dbo.O_RESPONSES as r')->whereColumn('r.MAIL_OBJECT_PID', 'm.MAIL_OBJECT_PID'))->count(),
                ];
                $services = (clone $base)->select('m.MAIL_CLASS_CD as code')->selectRaw('COUNT(*) AS total')
                    ->groupBy('m.MAIL_CLASS_CD')->orderByDesc('total')->get()->all();
                if ($db->getDriverName() !== 'sqlite') {
                    $categories = (clone $base)->select('m.MAIL_CATEGORY_CD as code')->selectRaw('COUNT(*) AS total')
                        ->groupBy('m.MAIL_CATEGORY_CD')->orderByDesc('total')->get()->all();
                    $products = (clone $base)->select('m.MAIL_OBJECT_TYPE_CD as code')->selectRaw('COUNT(*) AS total')
                        ->groupBy('m.MAIL_OBJECT_TYPE_CD')->orderByDesc('total')->get()->all();
                }
                if ($db->getDriverName() !== 'sqlite') {
                    $origins = (clone $base)->where('m.ORIG_POST_ORGANIZATION_CD', '<>', $filters['operator'] ?? 'BOA')
                        ->select('m.ORIG_POST_ORGANIZATION_CD as code')->selectRaw('COUNT(*) AS total')
                        ->groupBy('m.ORIG_POST_ORGANIZATION_CD')->orderByDesc('total')->get()->all();
                    $destinations = (clone $base)->where('m.DEST_POST_ORGANIZATION_CD', '<>', $filters['operator'] ?? 'BOA')
                        ->select('m.DEST_POST_ORGANIZATION_CD as code')->selectRaw('COUNT(*) AS total')
                        ->groupBy('m.DEST_POST_ORGANIZATION_CD')->orderByDesc('total')->get()->all();
                }
                $dateSql = $db->getDriverName() === 'sqlite' ? 'date(m.POSTING_DATE)' : 'CONVERT(date, m.POSTING_DATE)';
                $objectsByDate = (clone $base)->selectRaw("$dateSql AS date, COUNT(*) AS objects")
                    ->groupByRaw($dateSql)->orderBy('date')->get()->keyBy(fn ($row) => CarbonImmutable::parse($row->date)->toDateString());
                $declarationsByDate = (clone $base)->join('dbo.O_DECLARATIONS as d', 'd.MAIL_OBJECT_PID', '=', 'm.MAIL_OBJECT_PID')
                    ->selectRaw("$dateSql AS date, COUNT(*) AS declarations")->groupByRaw($dateSql)->orderBy('date')->get()->keyBy(fn ($row) => CarbonImmutable::parse($row->date)->toDateString());
                $responsesByDate = (clone $base)->join('dbo.O_RESPONSES as r', 'r.MAIL_OBJECT_PID', '=', 'm.MAIL_OBJECT_PID')
                    ->selectRaw("$dateSql AS date, COUNT(*) AS responses")->groupByRaw($dateSql)->orderBy('date')->get()->keyBy(fn ($row) => CarbonImmutable::parse($row->date)->toDateString());
                for ($date = CarbonImmutable::parse($filters['from']); $date->toDateString() <= $filters['to']; $date = $date->addDay()) {
                    $key = $date->toDateString();
                    $timeline[] = ['date' => $key] + array_map('intval', [
                        'objects' => $objectsByDate[$key]->objects ?? 0,
                        'declarations' => $declarationsByDate[$key]->declarations ?? 0,
                        'responses' => $responsesByDate[$key]->responses ?? 0,
                    ]);
                }
                $hourSql = $db->getDriverName() === 'sqlite' ? "CAST(strftime('%H', m.POSTING_DATE) AS INTEGER)" : 'DATEPART(hour, m.POSTING_DATE)';
                $hourObjects = (clone $base)->selectRaw("$hourSql AS hour, COUNT(*) AS objects")->groupByRaw($hourSql)->get()->keyBy('hour');
                $hourDeclarations = (clone $base)->join('dbo.O_DECLARATIONS as d', 'd.MAIL_OBJECT_PID', '=', 'm.MAIL_OBJECT_PID')
                    ->selectRaw("$hourSql AS hour, COUNT(*) AS declarations")->groupByRaw($hourSql)->get()->keyBy('hour');
                $hourResponses = (clone $base)->join('dbo.O_RESPONSES as r', 'r.MAIL_OBJECT_PID', '=', 'm.MAIL_OBJECT_PID')
                    ->selectRaw("$hourSql AS hour, COUNT(*) AS responses")->groupByRaw($hourSql)->get()->keyBy('hour');
                $hourStateRows = (clone $base)->join('dbo.O_DECLARATIONS as d', 'd.MAIL_OBJECT_PID', '=', 'm.MAIL_OBJECT_PID')
                    ->leftJoin('dbo.M_CDS_STATES as s', 's.CDS_STATE_CD', '=', 'd.CDS_STATE_CD')
                    ->selectRaw("$hourSql AS hour")->addSelect('s.CDS_STATE_NM as name')->selectRaw('COUNT(*) AS total')
                    ->groupByRaw($hourSql)->groupBy('s.CDS_STATE_NM')->get();
                $statesByHour = [];
                foreach ($hourStateRows as $row) $statesByHour[(int) $row->hour][$row->name ?: 'Sin estado'] = (int) $row->total;
                for ($hour = 0; $hour < 24; $hour++) {
                    $objectsRow = $hourObjects[$hour] ?? null; $declarationRow = $hourDeclarations[$hour] ?? null; $responseRow = $hourResponses[$hour] ?? null;
                    $hourly[] = ['hour' => $hour, 'objects' => (int) ($objectsRow->objects ?? 0), 'declarations' => (int) ($declarationRow->declarations ?? 0), 'responses' => (int) ($responseRow->responses ?? 0), 'states' => $statesByHour[$hour] ?? []];
                }
                $trendFrom = CarbonImmutable::parse($filters['from'])->startOfMonth()->toDateString();
                $monthlyBase = $baseFor($trendFrom, $filters['to']);
                $monthSql = $db->getDriverName() === 'sqlite' ? "strftime('%Y-%m-01', m.POSTING_DATE)" : "CONVERT(char(7), m.POSTING_DATE, 120) + '-01'";
                $monthObjects = (clone $monthlyBase)->selectRaw("$monthSql AS date, COUNT(*) AS objects")->groupByRaw($monthSql)->get()->keyBy(fn ($row) => CarbonImmutable::parse($row->date)->toDateString());
                $monthDeclarations = (clone $monthlyBase)->join('dbo.O_DECLARATIONS as d', 'd.MAIL_OBJECT_PID', '=', 'm.MAIL_OBJECT_PID')
                    ->selectRaw("$monthSql AS date, COUNT(*) AS declarations")->groupByRaw($monthSql)->get()->keyBy(fn ($row) => CarbonImmutable::parse($row->date)->toDateString());
                $monthResponses = (clone $monthlyBase)->join('dbo.O_RESPONSES as r', 'r.MAIL_OBJECT_PID', '=', 'm.MAIL_OBJECT_PID')
                    ->selectRaw("$monthSql AS date, COUNT(*) AS responses")->groupByRaw($monthSql)->get()->keyBy(fn ($row) => CarbonImmutable::parse($row->date)->toDateString());
                $monthStateRows = (clone $monthlyBase)->join('dbo.O_DECLARATIONS as d', 'd.MAIL_OBJECT_PID', '=', 'm.MAIL_OBJECT_PID')
                    ->leftJoin('dbo.M_CDS_STATES as s', 's.CDS_STATE_CD', '=', 'd.CDS_STATE_CD')
                    ->selectRaw("$monthSql AS date")->addSelect('s.CDS_STATE_NM as name')->selectRaw('COUNT(*) AS total')
                    ->groupByRaw($monthSql)->groupBy('s.CDS_STATE_NM')->get();
                $statesByMonth = [];
                foreach ($monthStateRows as $row) $statesByMonth[CarbonImmutable::parse($row->date)->toDateString()][$row->name ?: 'Sin estado'] = (int) $row->total;
                for ($month = CarbonImmutable::parse($trendFrom)->startOfMonth(); $month->toDateString() <= CarbonImmutable::parse($filters['to'])->startOfMonth()->toDateString(); $month = $month->addMonth()) {
                    $key = $month->toDateString(); $objectsRow = $monthObjects[$key] ?? null; $declarationRow = $monthDeclarations[$key] ?? null; $responseRow = $monthResponses[$key] ?? null;
                    $monthly[] = ['date' => $key, 'objects' => (int) ($objectsRow->objects ?? 0), 'declarations' => (int) ($declarationRow->declarations ?? 0), 'responses' => (int) ($responseRow->responses ?? 0), 'states' => $statesByMonth[$key] ?? []];
                }
            }

            return compact('objects', 'declarations', 'responses', 'declaredObjects', 'respondedObjects', 'withoutDeclaration', 'withoutResponse', 'previous', 'states', 'services', 'categories', 'products', 'origins', 'destinations', 'offices', 'timeline', 'hourly', 'monthly') + ['status' => 'ok', 'generated_at' => now(config('postal.timezone'))->toIso8601String()];
        });
    }
}
