<?php

namespace App\Services;

use App\Exceptions\IpsOperationException;
use App\Support\IpsEventTime;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class IpsRepository
{
    public function connection(): Connection
    {
        return DB::connection(config('ips.connection'));
    }

    public function catalog(?int $office = null): array
    {
        $db = $this->connection();
        $exchangeOffices = $this->exchangeOfficeCds();
        $receptacleOffice = $office ?? ($exchangeOffices[0] ?? null);
        $deliveryAgents = $db->table('dbo.N_DELIVERY_AGENTS')->whereIn('VALID_IND', ['1', '3']);
        if ($office !== null) {
            $deliveryAgents->where('DELIVERY_AGENT_FULL_CD', 'like', $this->deliveryAgentRegionPrefix($office).'%');
        }

        return [
            'events' => $db->table('dbo.C_EVENT_TYPES as e')
                ->leftJoin('dbo.CT_EVENT_TYPES as t', fn ($join) => $join->on('t.EVENT_TYPE_CD', '=', 'e.EVENT_TYPE_CD')->where('t.LANGUAGE_CD', 'ES'))
                ->where('e.MAILUNIT_TYPE_CD', 'MI')->whereIn('e.VALID_IND', ['1', '3'])
                ->orderBy('e.EVENT_TYPE_CD')->get(['e.EVENT_TYPE_CD', 'e.EVENT_TYPE_NM', 't.LOCAL_EVENT_TYPE_NM', 'e.RESULT_STATE_IND_CD']),
            'operations' => config('ips.events'),
            'offices' => $db->table('dbo.N_OWN_OFFICES')->whereIn('VALID_IND', ['1', '3'])->orderBy('OFFICE_NM')->get(['OWN_OFFICE_CD', 'OFFICE_FCD', 'OFFICE_NM']),
            'mail_classes' => $db->table('dbo.C_MAIL_CLASSES')->orderBy('MAIL_CLASS_CD')->get(['MAIL_CLASS_CD', 'MAIL_CLASS_NM']),
            'countries' => $db->table('dbo.C_COUNTRIES')->orderBy('COUNTRY_CD')->get(['COUNTRY_CD', 'COUNTRY_NM']),
            'item_conditions' => $db->table('dbo.C_ITEM_CONDITIONS')->orderBy('ITEM_CONDITION_CD')
                ->get(['ITEM_CONDITION_CD', 'ITEM_CONDITION_NM'])
                ->map(function ($condition) {
                    $condition->ITEM_CONDITION_NM = match ((string) $condition->ITEM_CONDITION_CD) {
                        '30' => 'Envío recibido en buen estado',
                        '31' => 'Envío dañado o rasgado',
                        '32' => 'Envío manipulado o violado',
                        default => $condition->ITEM_CONDITION_NM,
                    };

                    return $condition;
                }),
            'non_delivery_reasons' => $db->table('dbo.C_NON_DELIVERY_REASONS')->whereIn('VALID_IND', ['1', '3'])->get(['NON_DELIVERY_REASON_CD', 'NON_DELIVERY_REASON_NM']),
            'non_delivery_measures' => $db->table('dbo.C_NON_DELIVERY_MEASURES')->whereIn('VALID_IND', ['1', '3'])->get(['NON_DELIVERY_MEASURE_CD', 'NON_DELIVERY_MEASURE_NM']),
            'delivery_agents' => $deliveryAgents->orderBy('DELIVERY_AGENT_NM')->get(['DELIVERY_AGENT_CD', 'DELIVERY_AGENT_NM', 'DELIVERY_AGENT_FULL_CD']),
            'customs_office_types' => $db->table('dbo.C_CUSTOMS_OFFICE_TYPES')->orderBy('CUSTOMS_OFFICE_TYPE_CD')->get(['CUSTOMS_OFFICE_TYPE_CD', 'CUSTOMS_OFFICE_TYPE_NM']),
            'customs_release_statuses' => $db->table('dbo.C_CUSTOMS_RELEASE_STATUSES')->orderBy('CUSTOMS_RELEASE_STATUS_CD')->get(['CUSTOMS_RELEASE_STATUS_CD', 'CUSTOMS_RELEASE_STATUS_NM']),
            'open_inbound_receptacles' => $receptacleOffice !== null
                ? $this->openInboundReceptacles((int) $receptacleOffice)
                : collect(),
            'writes_enabled' => config('ips.writes_enabled'),
        ];
    }

    public function deliveryCatalog(int $officeCd): array
    {
        $db = $this->connection();
        $regionPrefix = $this->deliveryAgentRegionPrefix($officeCd);

        return [
            'non_delivery_reasons' => $db->table('dbo.C_NON_DELIVERY_REASONS')
                ->whereIn('VALID_IND', ['1', '3'])->orderBy('NON_DELIVERY_REASON_CD')
                ->get(['NON_DELIVERY_REASON_CD', 'NON_DELIVERY_REASON_NM']),
            'non_delivery_measures' => $db->table('dbo.C_NON_DELIVERY_MEASURES')
                ->whereIn('VALID_IND', ['1', '3'])->orderBy('NON_DELIVERY_MEASURE_CD')
                ->get(['NON_DELIVERY_MEASURE_CD', 'NON_DELIVERY_MEASURE_NM']),
            'delivery_agents' => $db->table('dbo.N_DELIVERY_AGENTS')
                ->whereIn('VALID_IND', ['1', '3'])
                ->where('DELIVERY_AGENT_FULL_CD', 'like', $regionPrefix.'%')
                ->orderBy('DELIVERY_AGENT_NM')
                ->get(['DELIVERY_AGENT_CD', 'DELIVERY_AGENT_NM', 'DELIVERY_AGENT_FULL_CD']),
            'customs_office_types' => $db->table('dbo.C_CUSTOMS_OFFICE_TYPES')
                ->orderBy('CUSTOMS_OFFICE_TYPE_CD')->get(['CUSTOMS_OFFICE_TYPE_CD', 'CUSTOMS_OFFICE_TYPE_NM']),
            'customs_release_statuses' => $db->table('dbo.C_CUSTOMS_RELEASE_STATUSES')
                ->orderBy('CUSTOMS_RELEASE_STATUS_CD')->get(['CUSTOMS_RELEASE_STATUS_CD', 'CUSTOMS_RELEASE_STATUS_NM']),
            'offices' => $db->table('dbo.N_OWN_OFFICES')->whereIn('VALID_IND', ['1', '3'])
                ->orderBy('OFFICE_NM')->get(['OWN_OFFICE_CD', 'OFFICE_FCD', 'OFFICE_NM']),
        ];
    }

    public function deliveryAgentRegionPrefix(int $officeCd): string
    {
        $office = $this->connection()->table('dbo.N_OWN_OFFICES')
            ->where('OWN_OFFICE_CD', $officeCd)
            ->whereIn('VALID_IND', ['1', '3'])
            ->first(['OFFICE_FCD']);

        if (! $office) {
            throw new IpsOperationException('La oficina IPS no existe o no está vigente.', 422);
        }

        $officeFcd = strtoupper(trim((string) $office->OFFICE_FCD));
        $regionPrefix = config('ips.delivery_agent_region_by_office_fcd.'.$officeFcd);

        if (! is_string($regionPrefix) || $regionPrefix === '') {
            throw new IpsOperationException('No hay una regional de carteros configurada para esta oficina IPS.', 422);
        }

        return $regionPrefix;
    }

    public function isDeliveryAgentAllowedAtOffice(int $deliveryAgentCd, int $officeCd): bool
    {
        $regionPrefix = $this->deliveryAgentRegionPrefix($officeCd);

        return $this->connection()->table('dbo.N_DELIVERY_AGENTS')
            ->where('DELIVERY_AGENT_CD', $deliveryAgentCd)
            ->whereIn('VALID_IND', ['1', '3'])
            ->where('DELIVERY_AGENT_FULL_CD', 'like', $regionPrefix.'%')
            ->exists();
    }

    public function packages(array $filters): array
    {
        // Counting benefits from one history scan; a small page benefits from indexed seeks.
        $total = app(IpsPackageTotalsCache::class)->remember($filters,
            fn () => $this->packagesQuery($filters, false)->count('m.MAILITM_PID'));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 25)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $ids = $this->packagesQuery($filters, true)
            ->orderByDesc('m.EVT_GMT_DT')->orderBy('m.MAILITM_PID')
            ->offset(($page - 1) * $perPage)->limit($perPage + 1)
            ->pluck('m.MAILITM_PID');
        // Hydrate only this page, so sorting/filtering never fetches every parcel's wide row.
        $rows = $ids->isEmpty() ? collect() : $this->connection()->table('dbo.L_MAILITMS as m')
            ->leftJoin('dbo.C_EVENT_TYPES as e', 'e.EVENT_TYPE_CD', '=', 'm.EVT_TYPE_CD')
            ->leftJoin('dbo.N_OWN_OFFICES as o', 'o.OWN_OFFICE_CD', '=', 'm.EVT_OFFICE_CD')
            ->whereIn('m.MAILITM_PID', $ids)->get(['m.*', 'e.EVENT_TYPE_NM', 'o.OFFICE_NM'])
            ->sortBy(fn ($row) => $ids->search($row->MAILITM_PID))->values();
        $this->enrich($rows);

        return [
            'data' => $rows->take($perPage)->map(fn ($row) => $this->presentForOffice($row, isset($filters['office_cd']) ? (int) $filters['office_cd'] : null))->values()->all(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'has_more' => $rows->count() > $perPage, 'status' => $filters['status'] ?? 'all'],
        ];
    }

    private function packagesQuery(array $filters, bool $pageQuery): \Illuminate\Database\Query\Builder
    {
        $db = $this->connection();
        $latest = $db->table('dbo.L_MAILITM_EVENTS')
            ->whereNotIn('EVENT_TYPE_CD', IpsStagePolicy::TECHNICAL_EVENTS);
        $query = $db->table('dbo.L_MAILITMS as m');
        if (($pageQuery || ! empty($filters['q'])) && $db->getDriverName() === 'sqlsrv') {
            // Seek each item's indexed history instead of sorting the entire event table.
            $latest->whereColumn('MAILITM_PID', 'm.MAILITM_PID')
                ->orderByDesc('EVENT_GMT_DT')->orderByDesc('EVENT_TYPE_CD')
                ->limit(1)->select(['EVENT_TYPE_CD', 'EVENT_OFFICE_CD', 'NEXT_OFFICE_CD']);
            $query->leftJoinLateral($latest, 'op');
        } else {
            $latest->selectRaw('MAILITM_PID, EVENT_TYPE_CD, EVENT_OFFICE_CD, NEXT_OFFICE_CD, ROW_NUMBER() OVER (PARTITION BY MAILITM_PID ORDER BY EVENT_GMT_DT DESC, EVENT_TYPE_CD DESC) AS rn');
            $query->leftJoinSub($latest, 'op', fn ($j) => $j->on('op.MAILITM_PID', '=', 'm.MAILITM_PID')->where('op.rn', 1));
        }

        $status = $filters['status'] ?? 'all';
        // The operational queues are inbound Bolivia only. An exact search in “Todos”
        // may still open an outbound item for history; its policy actions remain empty.
        $exactHistorySearch = $status === 'all' && ! empty($filters['q']);
        if (! $exactHistorySearch) {
            $query->where('m.DEST_COUNTRY_CD', config('ips.destination_country'));
        }
        if (in_array($status, ['pending', 'reception'], true)) {
            $eventColumn = DB::raw('COALESCE(op.EVENT_TYPE_CD, m.EVT_TYPE_CD)');
            $events = $status === 'pending' ? config('ips.delivery_candidate_events') : config('ips.reception_candidate_events');
            $query->whereIn($eventColumn, $events)
                ->where(function ($states) use ($status, $eventColumn) {
                    $states->whereIn('m.STATE_IND_CD', $status === 'pending' ? [0, 8] : [0, 2, 3, 6]);
                    if ($status === 'pending') {
                        $states->orWhere(function ($inTransit) use ($eventColumn) {
                            $inTransit->where($eventColumn, 13)->where('m.STATE_IND_CD', 6);
                        });
                    }
                })
                ->whereNotExists(function ($q) {
                    $q->selectRaw('1')->from('dbo.L_MAILITM_EVENTS as terminal')
                        ->whereColumn('terminal.MAILITM_PID', 'm.MAILITM_PID')->whereIn('terminal.EVENT_TYPE_CD', [37, 76]);
                });
        } elseif ($status === 'warehouse') {
            $eventColumn = DB::raw('COALESCE(op.EVENT_TYPE_CD, m.EVT_TYPE_CD)');
            $candidateMap = config('ips.delivery_action_candidates', []);
            $action = $filters['action_cd'] ?? null;
            if ($action !== null) {
                $candidates = $candidateMap[$action] ?? ['events' => [], 'states' => []];
            } else {
                $candidates = [
                    'events' => array_values(array_unique(array_merge(...array_column($candidateMap, 'events')))),
                    'states' => array_values(array_unique(array_merge(...array_column($candidateMap, 'states')))),
                ];
            }
            $query->whereIn($eventColumn, $candidates['events'])
                ->whereIn('m.STATE_IND_CD', $candidates['states'])
                ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('dbo.L_MAILITM_EVENTS as terminal')
                    ->whereColumn('terminal.MAILITM_PID', 'm.MAILITM_PID')->whereIn('terminal.EVENT_TYPE_CD', [37, 76]);
            });
        } elseif ($status === 'returns') {
            $query->whereIn('m.POSTAL_STATUS_CD', [6, 7, 22, 23]);
        } elseif ($status === 'delivered') {
            $query->where('m.STATE_IND_CD', 5);
        }
        if (isset($filters['office_cd'])) {
            $office = (int) $filters['office_cd'];
            $query->where(function ($q) use ($office, $status) {
                $q->whereRaw('COALESCE(op.EVENT_OFFICE_CD, m.EVT_OFFICE_CD) = ?', [$office]);
                if (! in_array($status, ['pending', 'warehouse'], true)) {
                    $q->orWhere('op.NEXT_OFFICE_CD', $office);
                }
                if ($status === 'pending') {
                    $q->orWhere('op.NEXT_OFFICE_CD', $office);
                }
            });
            if ($status === 'pending') {
                // A sack arrival is receivable only at its declared dispatch destination.
                $query->where(function ($q) use ($office) {
                    $q->whereRaw('COALESCE(op.EVENT_TYPE_CD, m.EVT_TYPE_CD) <> ?', [13])
                        ->orWhere('op.NEXT_OFFICE_CD', $office);
                });
            }
            if ($status === 'reception') {
                $query->where(function ($q) use ($office) {
                    $q->whereNotIn(DB::raw('COALESCE(op.EVENT_TYPE_CD, m.EVT_TYPE_CD)'), [13, 35, 72])
                        ->orWhere('op.NEXT_OFFICE_CD', $office);
                });
            }
        }
        if (! empty($filters['q'])) {
            $code = strtoupper(trim($filters['q']));
            $query->where(fn ($q) => $q->where('m.MAILITM_FID', $code)->orWhere('m.MAILITM_LOCAL_ID', $code));
        }
        if (isset($filters['event_cd'])) {
            // Workflow pages filter by the last operational milestone even
            // when a later technical event is the physical latest record.
            $eventCodes = array_values(array_map('intval', (array) $filters['event_cd']));
            if (count($eventCodes) === 1) {
                $query->whereRaw('COALESCE(op.EVENT_TYPE_CD, m.EVT_TYPE_CD) = ?', [$eventCodes[0]]);
            } elseif ($eventCodes !== []) {
                $query->whereIn(DB::raw('COALESCE(op.EVENT_TYPE_CD, m.EVT_TYPE_CD)'), $eventCodes);
            }
        }
        if (isset($filters['delivery_agent_cd'])) {
            $agentCode = strtoupper((string) $filters['delivery_agent_cd']);
            $query->whereIn(DB::raw('COALESCE(op.EVENT_TYPE_CD, m.EVT_TYPE_CD)'), [39, 74])
                ->whereIn('m.STATE_IND_CD', [0, 8])
                ->whereExists(function ($assignments) use ($agentCode) {
                    $assignments->selectRaw('1')->from('dbo.L_MAILITM_DELIV_INFOS as assignment')
                        ->whereColumn('assignment.MAILITM_PID', 'm.MAILITM_PID')
                        ->where('assignment.EVENT_TYPE_CD', 39)
                        ->where('assignment.DELIVERY_AGENT_CD', $agentCode)
                        ->whereNotExists(function ($newerAssignment) {
                            $newerAssignment->selectRaw('1')->from('dbo.L_MAILITM_DELIV_INFOS as newer_assignment')
                                ->whereColumn('newer_assignment.MAILITM_PID', 'assignment.MAILITM_PID')
                                ->where('newer_assignment.EVENT_TYPE_CD', 39)
                                ->whereRaw('newer_assignment.EVENT_GMT_DT > assignment.EVENT_GMT_DT');
                        });
                });
        }
        if (isset($filters['actor_user_pid'])) {
            $query->whereExists(function ($events) use ($filters) {
                $events->selectRaw('1')->from('dbo.L_MAILITM_EVENTS as actor_event')
                    ->whereColumn('actor_event.MAILITM_PID', 'm.MAILITM_PID')
                    ->where('actor_event.EVENT_TYPE_CD', 37)
                    ->where('actor_event.USER_PID', (int) $filters['actor_user_pid']);
                if (isset($filters['office_cd'])) {
                    $events->where('actor_event.EVENT_OFFICE_CD', (int) $filters['office_cd']);
                }
            });
        }
        if (! empty($filters['from'])) {
            $query->where('m.EVT_GMT_DT', '>=', Carbon::parse($filters['from'])->utc()->toDateTimeString());
        }
        if (! empty($filters['to'])) {
            $query->where('m.EVT_GMT_DT', '<=', Carbon::parse($filters['to'])->utc()->toDateTimeString());
        }

        return $query;
    }

    public function users(array $filters): array
    {
        $query = $this->connection()->table('dbo.L_USERS as u')
            ->leftJoin('dbo.N_OWN_OFFICES as o', 'o.OWN_OFFICE_CD', '=', 'u.OWN_OFFICE_CD')
            ->whereIn('u.VALID_IND', ['1', '3']);

        if (! empty($filters['q'])) {
            $search = mb_strtolower(trim((string) $filters['q']));
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(u.USER_FID) LIKE ?', ['%'.$search.'%'])
                    ->orWhereRaw('LOWER(u.USER_NM) LIKE ?', ['%'.$search.'%'])
                    ->orWhereRaw('LOWER(u.USER_DOMAIN) LIKE ?', ['%'.$search.'%']);
            });
        }

        if (isset($filters['user_pid'])) {
            $query->where('u.USER_PID', $filters['user_pid']);
        }

        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 25)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $rows = $query->orderBy('u.USER_DOMAIN')->orderBy('u.USER_FID')->orderBy('u.USER_PID')
            ->offset(($page - 1) * $perPage)->limit($perPage + 1)
            ->get([
                'u.USER_PID', 'u.USER_FID', 'u.USER_NM', 'u.USER_DOMAIN', 'u.USER_TYPE',
                'u.OWN_OFFICE_CD', 'o.OFFICE_NM', 'o.OFFICE_FCD', 'u.VALID_IND', 'u.IPSWEB',
                'u.RESTRICT_USER_OFFICES', 'u.E_MAIL_ADDRESS',
            ]);

        return [
            'data' => $rows->take($perPage)->map(fn ($row) => $this->presentUser($row))->values()->all(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'has_more' => $rows->count() > $perPage],
        ];
    }

    public function user(int $pid): array
    {
        $result = $this->users(['user_pid' => $pid, 'per_page' => 1]);
        $user = $result['data'][0] ?? null;
        if (! $user) {
            throw new IpsOperationException('Usuario IPS no encontrado o inactivo.', 404);
        }

        return $user;
    }

    public function createUser(array $data): array
    {
        if (! config('ips.user_provisioning_enabled')) {
            throw new IpsOperationException('Alta de usuarios IPS deshabilitada en la configuración.', 503);
        }

        $userFid = trim((string) $data['user_fid']);
        $userName = trim((string) $data['user_name']);
        $domain = strtoupper(trim((string) ($data['user_domain'] ?? 'AGBC')));
        $email = isset($data['email']) ? trim((string) $data['email']) : null;
        $officeCd = isset($data['office_cd']) && $data['office_cd'] !== '' ? (int) $data['office_cd'] : null;

        if ($userFid === '' || $userName === '' || $domain === '') {
            throw new IpsOperationException('Usuario, nombre y dominio IPS son obligatorios.', 422);
        }
        if ($officeCd !== null && ! $this->reference('N_OWN_OFFICES', 'OWN_OFFICE_CD', $officeCd)) {
            throw new IpsOperationException('Oficina IPS no existe o no está activa.', 422);
        }

        $db = $this->connection();

        $pid = $db->transaction(function () use ($db, $userFid, $userName, $domain, $email, $officeCd): int {
            $exists = $db->table('dbo.L_USERS')
                ->whereRaw('LOWER(USER_FID) = ?', [mb_strtolower($userFid)])
                ->whereRaw('RTRIM(USER_DOMAIN) = ?', [$domain])
                ->whereIn('VALID_IND', ['1', '3'])
                ->exists();

            if ($exists) {
                throw new IpsOperationException('Ya existe un usuario IPS activo con ese dominio e identificador.', 409);
            }

            $next = $db->selectOne('SELECT ISNULL(MAX(USER_PID), 0) + 1 AS next_pid FROM dbo.L_USERS WITH (UPDLOCK, HOLDLOCK)');
            $nextPid = (int) $next->next_pid;
            if ($nextPid > 32767) {
                throw new IpsOperationException('No hay rango disponible para USER_PID smallint.', 503);
            }

            $db->table('dbo.L_USERS')->insert([
                'USER_PID' => $nextPid,
                'USER_FID' => $userFid,
                'USER_NM' => $userName,
                'USER_DOMAIN' => $domain,
                'USER_TYPE' => '1',
                'USER_PASSWORD' => null,
                'USER_ACCREDITATION' => null,
                'OWN_OFFICE_CD' => $officeCd,
                'VALID_IND' => '3',
                'IPSWEB' => 'N',
                'E_MAIL_ADDRESS' => $email !== '' ? $email : null,
                'RESTRICT_USER_OFFICES' => 0,
                'PASSWORD_SALT' => null,
            ]);

            return $nextPid;
        });

        return $this->user($pid);
    }

    private function presentUser(object $row): array
    {
        return [
            'user_pid' => (int) $row->USER_PID,
            'user_fid' => trim((string) $row->USER_FID),
            'user_name' => trim((string) $row->USER_NM),
            'user_domain' => trim((string) $row->USER_DOMAIN),
            'user_type' => trim((string) $row->USER_TYPE),
            'office_cd' => isset($row->OWN_OFFICE_CD) ? (int) $row->OWN_OFFICE_CD : null,
            'office_fcd' => trim((string) ($row->OFFICE_FCD ?? '')),
            'office_name' => trim((string) ($row->OFFICE_NM ?? '')),
            'valid_ind' => trim((string) $row->VALID_IND),
            'ipsweb' => trim((string) $row->IPSWEB) === 'Y',
            'restrict_user_offices' => (bool) $row->RESTRICT_USER_OFFICES,
            'email' => trim((string) ($row->E_MAIL_ADDRESS ?? '')),
        ];
    }

    public function find(string $code, bool $lock = false): ?object
    {
        $query = $this->connection()->table('dbo.L_MAILITMS')
            ->where(fn ($q) => $q->where('MAILITM_FID', $code)->orWhere('MAILITM_LOCAL_ID', $code));
        if ($lock) {
            $query->lockForUpdate();
        }
        $rows = $query->limit(2)->get();
        if ($rows->count() > 1) {
            throw new IpsOperationException('Identificador ambiguo en IPS; requiere revisión del operador.');
        }

        return $rows->first();
    }

    public function findReceptacle(string $code, bool $lock = false): ?object
    {
        $query = $this->connection()->table('dbo.L_RECPTCLS')
            ->whereRaw('UPPER(RTRIM(LTRIM(RECPTCL_FID))) = ?', [strtoupper(trim($code))]);
        if ($lock) {
            $query->lockForUpdate();
        }
        $rows = $query->limit(2)->get();
        if ($rows->count() > 1) {
            throw new IpsOperationException('Identificador de envase ambiguo en IPS; requiere revisión del operador.');
        }

        return $rows->first();
    }

    public function receptacleDetail(string $code, ?int $office = null): array
    {
        $receptacle = $this->findReceptacle($code);
        if (! $receptacle) {
            throw new IpsOperationException('Envase no encontrado en IPS.', 404);
        }
        $db = $this->connection();
        $latest = $db->table('dbo.L_RECPTCL_EVENTS as e')
            ->where('e.RECPTCL_PID', $receptacle->RECPTCL_PID)
            ->orderByDesc('e.EVENT_GMT_DT')->orderByDesc('e.EVENT_TYPE_CD')->first();
        $officeRow = $latest ? $this->ownOffice((int) $latest->EVENT_OFFICE_CD) : null;
        $allowed = [];
        $exchangeOffice = $office !== null
            && in_array((int) $office, $this->exchangeOfficeCds(), true);
        if ($office !== null && $exchangeOffice) {
            if ((int) $receptacle->EVT_TYPE_CD !== 130
                && in_array((int) $receptacle->STATE_IND_CD, [21, 23, 27], true)
                && $this->compatible((int) $receptacle->STATE_IND_CD, 130)) {
                $allowed[] = 'RECEPTACLE_RECEIVE';
            }
            if ((int) $receptacle->EVT_TYPE_CD === 130
                && (int) $receptacle->STATE_IND_CD === 22
                && $latest && (int) $latest->EVENT_OFFICE_CD === $office
                && $this->compatible(22, 131)) {
                $allowed[] = 'RECEPTACLE_OPEN';
            }
        }
        $events = $db->table('dbo.L_RECPTCL_EVENTS as e')
            ->leftJoin('dbo.C_EVENT_TYPES as t', 't.EVENT_TYPE_CD', '=', 'e.EVENT_TYPE_CD')
            ->leftJoin('dbo.N_OWN_OFFICES as o', 'o.OWN_OFFICE_CD', '=', 'e.EVENT_OFFICE_CD')
            ->where('e.RECPTCL_PID', $receptacle->RECPTCL_PID)
            ->orderByDesc('e.EVENT_GMT_DT')->orderByDesc('e.EVENT_TYPE_CD')
            ->get(['e.EVENT_TYPE_CD', 'e.EVENT_GMT_DT', 'e.EVENT_LOCAL_OFFSET', 'e.EVENT_OFFICE_CD', 'e.USER_PID', 't.EVENT_TYPE_NM', 'o.OFFICE_FCD', 'o.OFFICE_NM'])
            ->map(fn ($event) => array_merge((array) $event, IpsEventTime::present($event->EVENT_GMT_DT, $event->EVENT_LOCAL_OFFSET)));

        $linkedItemQuery = $db->table('dbo.L_MAILITM_EVENTS')
            ->where('RECPTCL_PID', $receptacle->RECPTCL_PID)
            ->whereNotNull('MAILITM_PID')
            ->distinct();
        $linkedItemsCount = (clone $linkedItemQuery)->count('MAILITM_PID');
        $itemIds = (clone $linkedItemQuery)->limit(1001)->pluck('MAILITM_PID');
        $itemsTruncated = $itemIds->count() > 1000;
        $itemIds = $itemIds->take(1000)->values();
        $items = $itemIds->isEmpty() ? collect() : $db->table('dbo.L_MAILITMS as mi')
            ->leftJoin('dbo.C_EVENT_TYPES as event_type', 'event_type.EVENT_TYPE_CD', '=', 'mi.EVT_TYPE_CD')
            ->leftJoin('dbo.CT_EVENT_TYPES as local_event_type', function ($join) {
                $join->on('local_event_type.EVENT_TYPE_CD', '=', 'mi.EVT_TYPE_CD')
                    ->where('local_event_type.LANGUAGE_CD', '=', 'ES');
            })
            ->leftJoin('dbo.C_MAIL_CLASSES as mail_class', 'mail_class.MAIL_CLASS_CD', '=', 'mi.MAIL_CLASS_CD')
            ->leftJoin('dbo.C_POSTAL_STATUSES as postal_status', 'postal_status.POSTAL_STATUS_CD', '=', 'mi.POSTAL_STATUS_CD')
            ->whereIn('mi.MAILITM_PID', $itemIds)
            ->orderBy('mi.MAILITM_FID')->orderBy('mi.MAILITM_LOCAL_ID')
            ->get([
                'mi.MAILITM_FID', 'mi.MAILITM_LOCAL_ID', 'mi.MAILITM_WEIGHT', 'mi.MAIL_CLASS_CD',
                'mi.POSTAL_STATUS_CD', 'mi.EVT_TYPE_CD', 'mi.EVT_GMT_DT',
                'mail_class.MAIL_CLASS_NM', 'postal_status.POSTAL_STATUS_NM',
                'local_event_type.LOCAL_EVENT_TYPE_NM as EVENT_NAME_ES', 'event_type.EVENT_TYPE_NM as EVENT_NAME',
            ]);

        return [
            'receptacle' => [
                'id' => (string) $receptacle->RECPTCL_PID,
                'codigo' => trim((string) $receptacle->RECPTCL_FID),
                'state_cd' => (int) $receptacle->STATE_IND_CD,
                'event_cd' => (int) $receptacle->EVT_TYPE_CD,
                'event_at' => Carbon::parse($receptacle->EVT_GMT_DT, 'UTC')->format('Y-m-d\\TH:i:s.vP'),
                'event_local_offset' => $latest?->EVENT_LOCAL_OFFSET,
                'office_cd' => $latest ? (int) $latest->EVENT_OFFICE_CD : null,
                'office_name' => trim((string) ($officeRow->OFFICE_NM ?? '')),
                'weight_kg' => isset($receptacle->RECPTCL_WEIGHT) ? (float) $receptacle->RECPTCL_WEIGHT : null,
                'items_count' => isset($receptacle->RECPTCL_MAILITMS_NO) ? (int) $receptacle->RECPTCL_MAILITMS_NO : null,
                'allowed_actions' => $allowed,
            ],
            'events' => $events,
            'items' => $items,
            'linked_items_count' => $linkedItemsCount,
            'items_truncated' => $itemsTruncated,
        ];
    }

    public function openInboundReceptacles(int $office): \Illuminate\Support\Collection
    {
        if (! in_array($office, $this->exchangeOfficeCds(), true)) {
            return collect();
        }

        return $this->connection()->table('dbo.L_RECPTCLS as r')
            ->join('dbo.L_RECPTCL_EVENTS as e', function ($join) use ($office) {
                $join->on('e.RECPTCL_PID', '=', 'r.RECPTCL_PID')
                    ->on('e.EVENT_TYPE_CD', '=', 'r.EVT_TYPE_CD')
                    ->on('e.EVENT_GMT_DT', '=', 'r.EVT_GMT_DT')
                    ->where('e.EVENT_OFFICE_CD', '=', $office);
            })
            ->where('r.STATE_IND_CD', 20)->where('r.EVT_TYPE_CD', 131)
            ->orderBy('r.RECPTCL_FID')
            ->get(['r.RECPTCL_PID', 'r.RECPTCL_FID', 'r.RECPTCL_WEIGHT', 'r.RECPTCL_MAILITMS_NO']);
    }

    /** PREDES receptacles addressed to this exchange office that have no receipt event here yet. */
    public function expectedInboundReceptacles(int $office, int $page = 1, int $perPage = 25): array
    {
        $perPage = min(100, max(1, $perPage));
        $page = max(1, $page);
        if (! in_array($office, $this->exchangeOfficeCds(), true)) {
            return ['data' => [], 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => 0, 'has_more' => false]];
        }

        $db = $this->connection();
        $officeFcd = strtoupper(trim((string) $db->table('dbo.N_OWN_OFFICES')
            ->where('OWN_OFFICE_CD', $office)->value('OFFICE_FCD')));
        if (! in_array($officeFcd, config('ips.exchange_office_fcds', []), true)) {
            return ['data' => [], 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => 0, 'has_more' => false]];
        }
        $receivableStates = $db->table('dbo.C_STATEINDS_EVTTYPES')->where('EVENT_TYPE_CD', 130)
            ->whereIn('STATE_IND_CD', [21, 23, 27])->pluck('STATE_IND_CD')->map(fn ($state) => (int) $state)->all();
        if (! $receivableStates) {
            return ['data' => [], 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => 0, 'has_more' => false]];
        }

        $identifiedItems = $db->table('dbo.N_EDI_MAILITMS as edi_item')
            ->selectRaw("COUNT(DISTINCT CASE WHEN NULLIF(RTRIM(LTRIM(edi_item.MAILITM_FID)), '') IS NOT NULL THEN UPPER(RTRIM(LTRIM(edi_item.MAILITM_FID))) END)")
            ->whereColumn('edi_item.RECPTCL_PID', 'edi.RECPTCL_PID');
        $query = $db->table('dbo.N_EDI_RECPTCLS as edi')
            ->join('dbo.N_EDI_DESPTCHS as d', 'd.DESPTCH_PID', '=', 'edi.DESPTCH_PID')
            ->join('dbo.L_RECPTCLS as local_sack', function ($join) {
                $join->whereRaw('UPPER(RTRIM(LTRIM(local_sack.RECPTCL_FID))) = UPPER(RTRIM(LTRIM(edi.RECPTCL_FID)))');
            })
            ->whereNotNull('d.DESPTCH_CLOSING_DATE')
            ->whereNotNull('edi.RECPTCL_FID')
            ->whereRaw('UPPER(SUBSTRING(RTRIM(LTRIM(edi.RECPTCL_FID)), 7, 6)) = ?', [$officeFcd])
            ->whereIn('local_sack.STATE_IND_CD', $receivableStates)
            ->where('local_sack.EVT_TYPE_CD', '<>', 130)
            ->whereNotExists(function ($received) use ($office) {
                $received->selectRaw('1')->from('dbo.L_RECPTCLS as local_sack')
                    ->join('dbo.L_RECPTCL_EVENTS as receipt_event', 'receipt_event.RECPTCL_PID', '=', 'local_sack.RECPTCL_PID')
                    ->whereRaw('UPPER(RTRIM(LTRIM(local_sack.RECPTCL_FID))) = UPPER(RTRIM(LTRIM(edi.RECPTCL_FID)))')
                    ->where('receipt_event.EVENT_TYPE_CD', 130)
                    ->where('receipt_event.EVENT_OFFICE_CD', $office);
            })
            ->select([
                'local_sack.RECPTCL_PID', 'edi.RECPTCL_FID', 'local_sack.RECPTCL_WEIGHT',
                'edi.RECPTCL_MAILITMS_NO', 'd.DESPTCH_FID', 'd.DESPTCH_CLOSING_DATE',
            ])
            ->selectSub($identifiedItems, 'EDI_IDENTIFIED_MAILITMS_NO');

        $total = (clone $query)->count('edi.RECPTCL_PID');
        $rows = $query->orderBy('d.DESPTCH_CLOSING_DATE')->orderBy('edi.RECPTCL_FID')
            ->offset(($page - 1) * $perPage)->limit($perPage + 1)->get();

        return [
            'data' => $rows->take($perPage)->map(function ($row) use ($office) {
                $declared = is_numeric($row->RECPTCL_MAILITMS_NO) ? (int) $row->RECPTCL_MAILITMS_NO : null;
                $identified = (int) ($row->EDI_IDENTIFIED_MAILITMS_NO ?? 0);
                $count = $declared ?? ($identified > 0 ? $identified : null);

                return [
                    'id' => (string) $row->RECPTCL_PID,
                    'codigo' => trim((string) $row->RECPTCL_FID),
                    'despacho' => trim((string) ($row->DESPTCH_FID ?? '')),
                    'origin_ctci' => substr(trim((string) $row->RECPTCL_FID), 0, 6),
                    'dispatch_closed_at' => $row->DESPTCH_CLOSING_DATE
                        ? Carbon::parse($row->DESPTCH_CLOSING_DATE, 'UTC')->format('Y-m-d\\TH:i:s.vP') : null,
                    'office_cd' => $office,
                    'weight_kg' => is_numeric($row->RECPTCL_WEIGHT) ? (float) $row->RECPTCL_WEIGHT : null,
                    'items_count' => $count,
                    'items_count_source' => $declared !== null ? 'Cantidad declarada PREDES'
                        : ($identified > 0 ? 'Paquetes identificados en PREDES' : null),
                    'allowed_actions' => ['RECEPTACLE_RECEIVE'],
                ];
            })->values()->all(),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => $rows->count() > $perPage,
            ],
        ];
    }

    /** Open sacks at this office with the declared and already-linked package totals. */
    public function openedReceptaclesForPackageRegistration(int $office, int $page = 1, int $perPage = 25): array
    {
        $perPage = min(100, max(1, $perPage));
        $page = max(1, $page);
        if (! in_array($office, $this->exchangeOfficeCds(), true)) {
            return ['data' => [], 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => 0, 'has_more' => false]];
        }

        $db = $this->connection();
        $registeredItems = $db->table('dbo.L_MAILITM_EVENTS as package_event')
            ->selectRaw('COUNT(DISTINCT package_event.MAILITM_PID)')
            ->whereColumn('package_event.RECPTCL_PID', 'r.RECPTCL_PID')
            ->whereNotNull('package_event.MAILITM_PID');
        $ediReceptaclePid = $db->table('dbo.N_EDI_RECPTCLS as edi')
            ->select('edi.RECPTCL_PID')
            ->whereRaw('UPPER(RTRIM(LTRIM(edi.RECPTCL_FID))) = UPPER(RTRIM(LTRIM(r.RECPTCL_FID)))')
            ->orderByDesc('edi.RECPTCL_PID')->limit(1);
        $ediDeclaredCount = $db->table('dbo.N_EDI_RECPTCLS as edi')
            ->select('edi.RECPTCL_MAILITMS_NO')
            ->whereRaw('UPPER(RTRIM(LTRIM(edi.RECPTCL_FID))) = UPPER(RTRIM(LTRIM(r.RECPTCL_FID)))')
            ->orderByDesc('edi.RECPTCL_PID')->limit(1);
        $ediIdentifiedCount = $db->table('dbo.N_EDI_MAILITMS as edi_item')
            ->selectRaw("COUNT(DISTINCT CASE WHEN NULLIF(RTRIM(LTRIM(edi_item.MAILITM_FID)), '') IS NOT NULL THEN UPPER(RTRIM(LTRIM(edi_item.MAILITM_FID))) END)")
            ->whereIn('edi_item.RECPTCL_PID', $ediReceptaclePid);

        $query = $db->table('dbo.L_RECPTCLS as r')
            ->where('r.STATE_IND_CD', 20)->where('r.EVT_TYPE_CD', 131)
            ->whereExists(function ($events) use ($office) {
                $events->selectRaw('1')->from('dbo.L_RECPTCL_EVENTS as e')
                    ->whereColumn('e.RECPTCL_PID', 'r.RECPTCL_PID')
                    ->whereColumn('e.EVENT_TYPE_CD', 'r.EVT_TYPE_CD')
                    ->whereColumn('e.EVENT_GMT_DT', 'r.EVT_GMT_DT')
                    ->where('e.EVENT_OFFICE_CD', $office);
            })
            ->select([
                'r.RECPTCL_PID', 'r.RECPTCL_FID', 'r.RECPTCL_WEIGHT',
                'r.RECPTCL_MAILITMS_NO', 'r.EVT_GMT_DT',
            ])
            ->selectSub($registeredItems, 'REGISTERED_MAILITMS_NO')
            ->selectSub($ediDeclaredCount, 'EDI_DECLARED_MAILITMS_NO')
            ->selectSub($ediIdentifiedCount, 'EDI_IDENTIFIED_MAILITMS_NO');

        $total = (clone $query)->count('r.RECPTCL_PID');
        $rows = $query->orderBy('r.EVT_GMT_DT')->orderBy('r.RECPTCL_FID')
            ->offset(($page - 1) * $perPage)->limit($perPage + 1)->get();

        return [
            'data' => $rows->take($perPage)->map(function ($row) use ($office) {
                $ipsDeclared = is_numeric($row->RECPTCL_MAILITMS_NO) ? (int) $row->RECPTCL_MAILITMS_NO : null;
                $ediDeclared = is_numeric($row->EDI_DECLARED_MAILITMS_NO) ? (int) $row->EDI_DECLARED_MAILITMS_NO : null;
                $ediIdentified = (int) ($row->EDI_IDENTIFIED_MAILITMS_NO ?? 0);
                $declared = $ipsDeclared ?? $ediDeclared ?? ($ediIdentified > 0 ? $ediIdentified : null);
                $registered = (int) ($row->REGISTERED_MAILITMS_NO ?? 0);
                $status = $declared === null ? 'Cantidad objetivo no informada'
                    : ($registered > $declared ? 'Revisar diferencia'
                        : ($registered === $declared ? 'Conteo alcanzado' : 'Pendiente de registrar'));

                return [
                    'id' => (string) $row->RECPTCL_PID,
                    'codigo' => trim((string) $row->RECPTCL_FID),
                    'event_cd' => 131,
                    'state_cd' => 20,
                    'event_at' => Carbon::parse($row->EVT_GMT_DT, 'UTC')->format('Y-m-d\\TH:i:s.vP'),
                    'office_cd' => $office,
                    'weight_kg' => is_numeric($row->RECPTCL_WEIGHT) ? (float) $row->RECPTCL_WEIGHT : null,
                    'declared_items_count' => $declared,
                    'registered_items_count' => $registered,
                    'remaining_items_count' => $declared === null ? null : max(0, $declared - $registered),
                    'items_count_source' => $ipsDeclared !== null ? 'Declarados en IPS'
                        : ($ediDeclared !== null ? 'Declarados en PREDES'
                            : ($ediIdentified > 0 ? 'Paquetes identificados en PREDES' : null)),
                    'registration_status' => $status,
                    'allowed_actions' => [],
                ];
            })->values()->all(),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => $rows->count() > $perPage,
            ],
        ];
    }

    public function pendingReceptacleOpenings(int $office, int $page = 1, int $perPage = 25): array
    {
        $perPage = min(100, max(1, $perPage));
        $page = max(1, $page);
        $compatible = in_array($office, $this->exchangeOfficeCds(), true)
            && $this->compatible(22, 131);

        if (! $compatible) {
            return [
                'data' => [],
                'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => 0, 'has_more' => false],
            ];
        }

        $db = $this->connection();
        // La tabla operativa puede tener la cantidad sin informar antes de abrir la saca.
        // La prealerta PREDES conserva la cantidad declarada y se relaciona por marbete.
        $ediReceptaclePid = $db->table('dbo.N_EDI_RECPTCLS as edi')
            ->select('edi.RECPTCL_PID')
            ->whereRaw('UPPER(RTRIM(LTRIM(edi.RECPTCL_FID))) = UPPER(RTRIM(LTRIM(r.RECPTCL_FID)))')
            ->orderByDesc('edi.RECPTCL_PID')->limit(1);
        $ediDeclaredCount = $db->table('dbo.N_EDI_RECPTCLS as edi')
            ->select('edi.RECPTCL_MAILITMS_NO')
            ->whereRaw('UPPER(RTRIM(LTRIM(edi.RECPTCL_FID))) = UPPER(RTRIM(LTRIM(r.RECPTCL_FID)))')
            ->orderByDesc('edi.RECPTCL_PID')->limit(1);
        $ediIdentifiedCount = $db->table('dbo.N_EDI_MAILITMS as edi_item')
            ->selectRaw("COUNT(DISTINCT CASE WHEN NULLIF(RTRIM(LTRIM(edi_item.MAILITM_FID)), '') IS NOT NULL THEN UPPER(RTRIM(LTRIM(edi_item.MAILITM_FID))) END)")
            ->whereIn('edi_item.RECPTCL_PID', $ediReceptaclePid);

        $query = $db->table('dbo.L_RECPTCLS as r')
            ->where('r.STATE_IND_CD', 22)
            ->where('r.EVT_TYPE_CD', 130)
            ->whereExists(function ($events) use ($office) {
                $events->selectRaw('1')->from('dbo.L_RECPTCL_EVENTS as e')
                    ->whereColumn('e.RECPTCL_PID', 'r.RECPTCL_PID')
                    ->whereColumn('e.EVENT_TYPE_CD', 'r.EVT_TYPE_CD')
                    ->whereColumn('e.EVENT_GMT_DT', 'r.EVT_GMT_DT')
                    ->where('e.EVENT_OFFICE_CD', $office);
            })
            ->select([
                'r.RECPTCL_PID', 'r.RECPTCL_FID', 'r.RECPTCL_WEIGHT',
                'r.RECPTCL_MAILITMS_NO', 'r.EVT_GMT_DT',
            ])
            ->selectSub($ediDeclaredCount, 'EDI_DECLARED_MAILITMS_NO')
            ->selectSub($ediIdentifiedCount, 'EDI_IDENTIFIED_MAILITMS_NO');

        $total = (clone $query)->count('r.RECPTCL_PID');
        $rows = $query->orderBy('r.EVT_GMT_DT')->orderBy('r.RECPTCL_FID')
            ->offset(($page - 1) * $perPage)->limit($perPage + 1)
            ->get();

        return [
            'data' => $rows->take($perPage)->map(function ($row) use ($office) {
                $ipsCount = is_numeric($row->RECPTCL_MAILITMS_NO) ? (int) $row->RECPTCL_MAILITMS_NO : null;
                $ediDeclaredCount = is_numeric($row->EDI_DECLARED_MAILITMS_NO) ? (int) $row->EDI_DECLARED_MAILITMS_NO : null;
                $ediIdentifiedCount = (int) ($row->EDI_IDENTIFIED_MAILITMS_NO ?? 0);
                $itemsCount = $ipsCount ?? $ediDeclaredCount ?? ($ediIdentifiedCount > 0 ? $ediIdentifiedCount : null);
                $itemsCountSource = $ipsCount !== null ? 'IPS'
                    : ($ediDeclaredCount !== null ? 'PREDES'
                        : ($ediIdentifiedCount > 0 ? 'Paquetes PREDES identificados' : null));

                return [
                    'id' => (string) $row->RECPTCL_PID,
                    'codigo' => trim((string) $row->RECPTCL_FID),
                    'event_cd' => 130,
                    'state_cd' => 22,
                    'event_at' => Carbon::parse($row->EVT_GMT_DT, 'UTC')->format('Y-m-d\\TH:i:s.vP'),
                    'office_cd' => $office,
                    'weight_kg' => isset($row->RECPTCL_WEIGHT) ? (float) $row->RECPTCL_WEIGHT : null,
                    'items_count' => $itemsCount,
                    'items_count_source' => $itemsCountSource,
                    'allowed_actions' => ['RECEPTACLE_OPEN'],
                ];
            })->values()->all(),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => $rows->count() > $perPage,
            ],
        ];
    }

    public function assertOpenInboundReceptacle(string $pid, int $office): object
    {
        if (! in_array($office, $this->exchangeOfficeCds(), true)) {
            throw new IpsOperationException('La oficina no es una oficina de cambio habilitada para recibir este envase.', 422);
        }
        $row = $this->connection()->table('dbo.L_RECPTCLS')->where('RECPTCL_PID', $pid)->lockForUpdate()->first();
        if (! $row || (int) $row->STATE_IND_CD !== 20 || (int) $row->EVT_TYPE_CD !== 131) {
            throw new IpsOperationException('El envase debe estar abierto en IPS antes de clasificar sus paquetes.', 409);
        }
        $latest = $this->connection()->table('dbo.L_RECPTCL_EVENTS')->where('RECPTCL_PID', $pid)
            ->orderByDesc('EVENT_GMT_DT')->orderByDesc('EVENT_TYPE_CD')->first();
        if (! $latest || (int) $latest->EVENT_TYPE_CD !== 131 || (int) $latest->EVENT_OFFICE_CD !== $office) {
            throw new IpsOperationException('El envase no fue abierto en esta oficina de cambio.', 409);
        }

        return $row;
    }

    public function exchangeOfficeCds(): array
    {
        return $this->connection()->table('dbo.N_OWN_OFFICES')
            ->whereIn('VALID_IND', ['1', '3'])->whereIn('OFFICE_FCD', config('ips.exchange_office_fcds', []))
            ->pluck('OWN_OFFICE_CD')->map(fn ($id) => (int) $id)->all();
    }

    public function receptacleEvent(int $id): ?object
    {
        return $this->connection()->table('dbo.C_EVENT_TYPES')
            ->where('EVENT_TYPE_CD', $id)->where('MAILUNIT_TYPE_CD', 'RC')
            ->whereIn('VALID_IND', ['1', '3'])->first();
    }

    public function lockReceptacleCode(string $code): void
    {
        $row = $this->connection()->selectOne(
            "DECLARE @result int; EXEC @result = sys.sp_getapplock @Resource = ?, @LockMode = 'Exclusive', @LockOwner = 'Transaction', @LockTimeout = 10000; SELECT @result AS result;",
            ['sitra:receptacle:'.hash('sha256', strtoupper(trim($code)))]
        );
        if ((int) $row->result < 0) {
            throw new IpsOperationException('Otro proceso está operando este envase. Reintente consultando su estado.');
        }
    }

    public function verifyReceptacleWrite(string $pid, int $event, string $date, int $state, int $office): void
    {
        $row = $this->connection()->table('dbo.L_RECPTCLS')->where('RECPTCL_PID', $pid)->first();
        $eventRow = $this->connection()->table('dbo.L_RECPTCL_EVENTS')->where('RECPTCL_PID', $pid)
            ->where('EVENT_TYPE_CD', $event)->where('EVENT_GMT_DT', $date)->first();
        if (! $row || ! $eventRow || (int) $row->EVT_TYPE_CD !== $event
            || (int) $row->STATE_IND_CD !== $state
            || Carbon::parse($row->EVT_GMT_DT, 'UTC')->toDateTimeString() !== $date
            || (int) $eventRow->EVENT_OFFICE_CD !== $office) {
            throw new IpsOperationException('IPS no confirmó el evento del envase y su estado. Operación revertida.', 502);
        }
    }

    public function detail(string $code): array
    {
        $item = $this->find($code);
        if (! $item) {
            throw new IpsOperationException('Paquete no encontrado en IPS.', 404);
        }

        $this->enrich(collect([$item]));

        $package = $this->present($item);
        $package['operation_office_cds'] = match ($package['operational_event_cd']) {
            30, 35 => [$package['operational_office_cd']],
            13 => $package['next_office_cd'] ? [(int) $package['next_office_cd']] : [],
            default => $package['origin_country'] !== 'BO'
                && in_array($package['operational_event_cd'], config('ips.exchange_receipt_candidate_events', []), true)
                && in_array($package['state_cd'], [0, 2, 3], true)
                    ? $package['exchange_office_cds']
                    : [$package['operational_office_cd']],
        };
        $package['next_offices'] = in_array($package['operational_event_cd'], [30, 38], true)
            ? $this->nextOffices((int) $package['operational_office_cd'])
            : collect();
        $package['domestic_receptacles'] = $package['operational_event_cd'] === 35
            ? $this->openDomesticReceptacles($item)
            : collect();

        return [
            'package' => $package,
            'events' => $this->connection()->table('dbo.L_MAILITM_EVENTS as e')
                ->leftJoin('dbo.C_EVENT_TYPES as t', 't.EVENT_TYPE_CD', '=', 'e.EVENT_TYPE_CD')
                ->where('e.MAILITM_PID', $item->MAILITM_PID)->orderByDesc('e.EVENT_GMT_DT')
                ->get(['e.EVENT_TYPE_CD', 'e.EVENT_GMT_DT', 'e.EVENT_LOCAL_OFFSET', 'e.EVENT_OFFICE_CD', 't.EVENT_TYPE_NM'])
                ->map(function ($event) {
                    return (object) array_merge(
                        (array) $event,
                        IpsEventTime::present($event->EVENT_GMT_DT, $event->EVENT_LOCAL_OFFSET)
                    );
                }),
        ];
    }

    public function nextOffices(int $originOffice): \Illuminate\Support\Collection
    {
        return $this->connection()->table('dbo.N_NEXT_OFFICES as route')
            ->join('dbo.N_OWN_OFFICES as office', 'office.OWN_OFFICE_CD', '=', 'route.DEST_OFFICE_CD')
            ->where('route.ORIG_OFFICE_CD', $originOffice)
            ->whereIn('office.VALID_IND', ['1', '3'])
            ->orderBy('office.OFFICE_NM')
            ->get(['office.OWN_OFFICE_CD', 'office.OFFICE_FCD', 'office.OFFICE_NM']);
    }

    public function isNextOffice(int $originOffice, int $nextOffice): bool
    {
        return $this->connection()->table('dbo.N_NEXT_OFFICES as route')
            ->join('dbo.N_OWN_OFFICES as office', 'office.OWN_OFFICE_CD', '=', 'route.DEST_OFFICE_CD')
            ->where('route.ORIG_OFFICE_CD', $originOffice)
            ->where('route.DEST_OFFICE_CD', $nextOffice)
            ->whereIn('office.VALID_IND', ['1', '3'])
            ->exists();
    }

    public function openDomesticReceptaclesFor(object $item): \Illuminate\Support\Collection
    {
        if (! isset($item->OP_OFFICE_CD) && ! isset($item->EVT_OFFICE_CD)) {
            return collect();
        }
        if (! isset($item->NEXT_OFFICE_CD)) {
            return collect();
        }
        $originOffice = (int) ($item->OP_OFFICE_CD ?? $item->EVT_OFFICE_CD ?? 0);
        $destinationOffice = (int) $item->NEXT_OFFICE_CD;
        $mailClass = trim((string) ($item->MAIL_CLASS_CD ?? ''));
        if ($originOffice < 0 || $destinationOffice < 0 || $mailClass === '') {
            return collect();
        }

        return $this->openDomesticReceptaclesQuery()
            ->where('r.ORIG_OWN_OFFICE_CD', $originOffice)
            ->where('r.DEST_OWN_OFFICE_CD', $destinationOffice)
            ->where('r.MAIL_CLASS_CD', $mailClass)
            ->orderByDesc('r.YEAR')->orderByDesc('r.SERIALNO')
            ->get([
                'r.DOM_REC_PID', 'r.DOM_REC_FID', 'r.ORIG_OWN_OFFICE_CD', 'r.DEST_OWN_OFFICE_CD',
                'r.MAIL_CLASS_CD', 'r.MAILITEMCNT', 'r.WEIGHT',
            ]);
    }

    public function lockOpenDomesticReceptacle(string $pid, int $originOffice, int $destinationOffice, string $mailClass): ?object
    {
        return $this->openDomesticReceptaclesQuery()
            ->where('r.DOM_REC_PID', $pid)
            ->where('r.ORIG_OWN_OFFICE_CD', $originOffice)
            ->where('r.DEST_OWN_OFFICE_CD', $destinationOffice)
            ->where('r.MAIL_CLASS_CD', $mailClass)
            ->lockForUpdate()
            ->first(['r.DOM_REC_PID', 'r.DOM_REC_FID']);
    }

    public function hasActiveDomesticReceptacleLink(string $mailItemPid): bool
    {
        return $this->connection()->table('dbo.L_DOMESTIC_RECPTCLS_MAILITMS')
            ->where('MAILITM_PID', $mailItemPid)->whereRaw("ISNULL(DEL_IND, '0') = '0'")->exists();
    }

    public function linkDomesticReceptacle(string $receptaclePid, string $mailItemPid): void
    {
        $this->callProcedure('SP_SET_DOMRECPTLSMAILITEMS', [
            'DOM_REC_PID' => $receptaclePid,
            'MAILITM_PID' => $mailItemPid,
        ]);
    }

    public function domesticReceptacleLinkExists(string $receptaclePid, string $mailItemPid): bool
    {
        return $this->connection()->table('dbo.L_DOMESTIC_RECPTCLS_MAILITMS')
            ->where('DOM_REC_PID', $receptaclePid)->where('MAILITM_PID', $mailItemPid)
            ->whereRaw("ISNULL(DEL_IND, '0') = '0'")->exists();
    }

    private function openDomesticReceptaclesQuery(): \Illuminate\Database\Query\Builder
    {
        return $this->connection()->table('dbo.L_DOMESTIC_RECPTCLS as r')
            ->where('r.STATE_IND_CD', 121)
            ->whereRaw("ISNULL(r.DEL_IND, '0') = '0'")
            ->whereExists(function ($open) {
                $open->selectRaw('1')->from('dbo.L_DOMESTIC_RECPTCL_EVENTS as event')
                    ->whereColumn('event.DOM_REC_PID', 'r.DOM_REC_PID')
                    ->whereIn('event.EVENT_TYPE_CD', [600, 602])
                    ->whereRaw("ISNULL(event.DEL_IND, '0') = '0'")
                    ->whereNotExists(function ($later) {
                        $later->selectRaw('1')->from('dbo.L_DOMESTIC_RECPTCL_EVENTS as later_event')
                            ->whereColumn('later_event.DOM_REC_PID', 'event.DOM_REC_PID')
                            ->whereRaw("ISNULL(later_event.DEL_IND, '0') = '0'")
                            ->whereRaw('(later_event.EVENT_GMT_DT > event.EVENT_GMT_DT OR (later_event.EVENT_GMT_DT = event.EVENT_GMT_DT AND later_event.EVENT_TYPE_CD > event.EVENT_TYPE_CD))');
                    });
            });
    }

    public function enrich($rows): void
    {
        if ($rows->isEmpty()) {
            return;
        }
        $ids = $rows->pluck('MAILITM_PID')->all();
        $customers = $this->connection()->table('dbo.L_MAILITM_CUSTOMERS')
            ->whereIn('MAILITM_PID', $ids)->where('SENDER_PAYEE_IND', 'A')->get()->keyBy('MAILITM_PID');
        $events = $this->connection()->table('dbo.L_MAILITM_EVENTS')
            ->whereIn('MAILITM_PID', $ids)->orderByDesc('EVENT_GMT_DT')->orderByDesc('EVENT_TYPE_CD')->get()->groupBy('MAILITM_PID');
        $deliveryAssignments = $this->connection()->table('dbo.L_MAILITM_DELIV_INFOS')
            ->whereIn('MAILITM_PID', $ids)->where('EVENT_TYPE_CD', 39)
            ->orderByDesc('EVENT_GMT_DT')->get(['MAILITM_PID', 'EVENT_GMT_DT', 'DELIVERY_AGENT_CD'])
            ->groupBy('MAILITM_PID');
        $offices = $this->connection()->table('dbo.N_OWN_OFFICES')->whereIn('VALID_IND', ['1', '3'])->get()->keyBy('OWN_OFFICE_CD');
        $exchangeOfficeCds = $offices->filter(fn ($office) => in_array(trim((string) $office->OFFICE_FCD), config('ips.exchange_office_fcds', []), true))
            ->pluck('OWN_OFFICE_CD')->map(fn ($id) => (int) $id)->values()->all();
        foreach ($rows as $row) {
            $customer = $customers->get($row->MAILITM_PID);
            $row->RECIPIENT_NAME = $customer ? trim(($customer->CUSTOMER_FORENAME ?? '').' '.($customer->CUSTOMER_NAME ?? '')) : null;
            $row->RECIPIENT_PHONE = $customer?->CUSTOMER_PHONE_NO;
            $row->RECIPIENT_CITY = $customer?->CUSTOMER_CITY;
            $row->RECIPIENT_ADDRESS = $customer?->CUSTOMER_ADDRESS;
            $history = $events->get($row->MAILITM_PID, collect());
            $latestAssignment = $deliveryAssignments->get($row->MAILITM_PID, collect())->first();
            $op = $history->first(fn ($e) => ! in_array((int) $e->EVENT_TYPE_CD, IpsStagePolicy::TECHNICAL_EVENTS, true));
            $currentEvent = $history->first(fn ($e) => (int) $e->EVENT_TYPE_CD === (int) $row->EVT_TYPE_CD
                && Carbon::parse($e->EVENT_GMT_DT, 'UTC')->equalTo(Carbon::parse($row->EVT_GMT_DT, 'UTC')));
            $row->OP_EVENT_CD = $op?->EVENT_TYPE_CD ?? $row->EVT_TYPE_CD;
            $row->OP_OFFICE_CD = $op?->EVENT_OFFICE_CD ?? $row->EVT_OFFICE_CD;
            $row->EVT_LOCAL_OFFSET = $currentEvent?->EVENT_LOCAL_OFFSET;
            $row->NEXT_OFFICE_CD = $op?->NEXT_OFFICE_CD;
            $row->OFFICE_NM = $offices->get($row->OP_OFFICE_CD)?->OFFICE_NM;
            $row->NEXT_OFFICE_NM = $offices->get($row->NEXT_OFFICE_CD)?->OFFICE_NM;
            $row->EXCHANGE_OFFICE_CDS = $exchangeOfficeCds;
            $row->TERMINAL = $history->contains(fn ($e) => in_array((int) $e->EVENT_TYPE_CD, [37, 76], true));
            $row->DELIVERY_AGENT_CD = $latestAssignment?->DELIVERY_AGENT_CD !== null
                ? trim((string) $latestAssignment->DELIVERY_AGENT_CD)
                : null;
        }

        foreach ($rows as $row) {
            $row->RECEIPT_OFFICE_CDS = (int) $row->OP_EVENT_CD === 13 && $row->NEXT_OFFICE_CD !== null
                ? [(int) $row->NEXT_OFFICE_CD]
                : [];
        }
    }

    public function presentForOffice(object $item, ?int $office): array
    {
        $package = $this->present($item);
        $package['allowed_actions'] = $office !== null ? app(IpsStagePolicy::class)->actions($package, $office) : [];

        return $package;
    }

    public function present(object $item): array
    {
        $stage = app(IpsStagePolicy::class)->describe((int) ($item->OP_EVENT_CD ?? $item->EVT_TYPE_CD), isset($item->STATE_IND_CD) ? (int) $item->STATE_IND_CD : null, (bool) ($item->TERMINAL ?? false));
        if (in_array((int) ($item->POSTAL_STATUS_CD ?? 0), [22, 23], true)) {
            $stage = ['key' => 'returned', 'label' => 'Devuelto al remitente', 'tone' => 'secondary'];
        } elseif (in_array((int) ($item->POSTAL_STATUS_CD ?? 0), [6, 7], true)) {
            $stage = ['key' => 'returning', 'label' => 'Devolución en curso', 'tone' => 'warning'];
        }

        return [
            'stage' => $stage,
            'operational_event_cd' => (int) ($item->OP_EVENT_CD ?? $item->EVT_TYPE_CD),
            'operational_office_cd' => (int) ($item->OP_OFFICE_CD ?? $item->EVT_OFFICE_CD),
            'next_office_cd' => isset($item->NEXT_OFFICE_CD) ? (int) $item->NEXT_OFFICE_CD : null,
            'next_office_name' => trim($item->NEXT_OFFICE_NM ?? ''),
            'receipt_office_cds' => array_values(array_map('intval', $item->RECEIPT_OFFICE_CDS ?? [])),
            'exchange_office_cds' => array_values(array_map('intval', $item->EXCHANGE_OFFICE_CDS ?? [])),
            'terminal' => (bool) ($item->TERMINAL ?? false),
            'delivery_agent_cd' => isset($item->DELIVERY_AGENT_CD) ? trim((string) $item->DELIVERY_AGENT_CD) : null,
            'address' => trim($item->RECIPIENT_ADDRESS ?? ''),
            'mail_class' => trim($item->MAIL_CLASS_CD ?? ''),
            'product_type' => trim($item->PRODUCT_TYPE_CD ?? ''),
            'dutiable_ind' => $item->DUTIABLE_IND ?? null,
            'postal_status_cd' => isset($item->POSTAL_STATUS_CD) ? (int) $item->POSTAL_STATUS_CD : null,
            'id' => $item->MAILITM_PID,
            'codigo' => trim($item->MAILITM_FID),
            'local_id' => trim($item->MAILITM_LOCAL_ID ?? ''),
            'weight_kg' => isset($item->MAILITM_WEIGHT) ? (float) $item->MAILITM_WEIGHT : null,
            'origin_country' => trim($item->ORIG_COUNTRY_CD ?? ''),
            'destination_country' => trim($item->DEST_COUNTRY_CD ?? ''),
            'state_cd' => isset($item->STATE_IND_CD) ? (int) $item->STATE_IND_CD : null,
            'event_cd' => isset($item->EVT_TYPE_CD) ? (int) $item->EVT_TYPE_CD : null,
            'event_at' => Carbon::parse($item->EVT_GMT_DT, 'UTC')->format('Y-m-d\TH:i:s.vP'),
            ...IpsEventTime::present($item->EVT_GMT_DT, $item->EVT_LOCAL_OFFSET ?? null),
            'office_cd' => isset($item->EVT_OFFICE_CD) ? (int) $item->EVT_OFFICE_CD : null,
            'office_name' => trim($item->OFFICE_NM ?? ''),
            'recipient' => trim((string) ($item->RECIPIENT_NAME ?? '')),
            'phone' => trim((string) ($item->RECIPIENT_PHONE ?? '')),
            'city' => trim((string) ($item->RECIPIENT_CITY ?? '')),
            'event_name' => trim($item->EVENT_TYPE_NM ?? ''),
        ];
    }

    public function assertReady(): void
    {
        if (! config('ips.writes_enabled')) {
            throw new IpsOperationException('Escrituras IPS deshabilitadas. Revise ips:diagnose y la configuración de integración.', 503);
        }
        $db = $this->connection();
        $name = $db->selectOne('SELECT DB_NAME() AS name')->name;
        if ($name !== config('ips.expected_database')) {
            throw new IpsOperationException('La conexión no apunta a la base IPS esperada.', 503);
        }
        foreach ([
            ['L_USERS', 'USER_PID', config('ips.user_pid')],
            ['L_WORKSTATIONS', 'WORKSTATION_PID', config('ips.workstation_pid')],
        ] as [$table, $column, $value]) {
            if (! $value || ! $db->table('dbo.'.$table)->where($column, $value)->whereIn('VALID_IND', ['1', '3'])->exists()) {
                throw new IpsOperationException('Configure un usuario técnico y una estación IPS activos.', 503);
            }
        }
    }

    public function lockCode(string $code): void
    {
        $row = $this->connection()->selectOne(
            "DECLARE @result int; EXEC @result = sys.sp_getapplock @Resource = ?, @LockMode = 'Exclusive', @LockOwner = 'Transaction', @LockTimeout = 10000; SELECT @result AS result;",
            ['sitra:package:'.hash('sha256', $code)]
        );
        if ((int) $row->result < 0) {
            throw new IpsOperationException('Otro proceso está operando este paquete. Reintente consultando el estado.');
        }
    }

    public function eventExists(string $pid, array $codes): bool
    {
        return $this->connection()->table('dbo.L_MAILITM_EVENTS')->where('MAILITM_PID', $pid)->whereIn('EVENT_TYPE_CD', $codes)->exists();
    }

    public function reference(string $table, string $column, mixed $value, bool $active = true): bool
    {
        $query = $this->connection()->table('dbo.'.$table)->where($column, $value);
        if ($active) {
            $query->whereIn('VALID_IND', ['1', '3']);
        }

        return $query->exists();
    }

    public function compatible(int $state, int $event): bool
    {
        return $this->connection()->table('dbo.C_STATEINDS_EVTTYPES')->where('STATE_IND_CD', $state)->where('EVENT_TYPE_CD', $event)->exists();
    }

    public function event(int $id): ?object
    {
        return $this->connection()->table('dbo.C_EVENT_TYPES')->where('EVENT_TYPE_CD', $id)->whereIn('VALID_IND', ['1', '3'])->where('MAILUNIT_TYPE_CD', 'MI')->first();
    }

    public function ownOffice(int $id): ?object
    {
        return $this->connection()->table('dbo.N_OWN_OFFICES')
            ->where('OWN_OFFICE_CD', $id)->whereIn('VALID_IND', ['1', '3'])->first();
    }

    public function workstation(int $id): ?object
    {
        return $this->connection()->table('dbo.L_WORKSTATIONS')
            ->where('WORKSTATION_PID', $id)->whereIn('VALID_IND', ['1', '3'])
            ->first(['WORKSTATION_PID', 'WORKSTATION_FID']);
    }

    public function callProcedure(string $procedure, array $parameters): void
    {
        $expected = config('ips-procedures.'.$procedure);
        if (! $expected || array_keys($parameters) !== $expected) {
            throw new IpsOperationException('Contrato de escritura IPS incompatible.', 503);
        }
        $actual = $this->connection()->select(
            'SELECT p.name FROM sys.parameters p WHERE p.object_id = OBJECT_ID(?) ORDER BY p.parameter_id',
            ['dbo.'.$procedure]
        );
        if (array_map(fn ($p) => ltrim($p->name, '@'), $actual) !== $expected) {
            throw new IpsOperationException('La firma del procedimiento IPS cambió; revise la integración.', 503);
        }
        $assignments = implode(', ', array_map(fn ($name) => '@'.$name.' = ?', $expected));
        // Fixed allowlisted procedure and parameter names; all values are bound.
        $statement = $this->connection()->getPdo()->prepare('EXEC dbo.'.$procedure.' '.$assignments);
        $statement->execute(array_values($parameters));
        do {
            if ($statement->columnCount() > 0) {
                $statement->fetchAll();
            }
        } while ($statement->nextRowset());
        $statement->closeCursor();
    }

    public function verifyWrite(
        string $pid,
        int $event,
        string $date,
        ?string $signatory,
        ?int $expectedNextOffice = null,
        ?string $expectedReceptaclePid = null,
        ?int $expectedConditionCd = null
    ): void
    {
        $db = $this->connection();
        $row = $db->table('dbo.L_MAILITMS')->where('MAILITM_PID', $pid)->first();
        $exists = $db->table('dbo.L_MAILITM_EVENTS')->where('MAILITM_PID', $pid)
            ->where('EVENT_TYPE_CD', $event)->where('EVENT_GMT_DT', $date)->exists();
        if (! $exists || ! $row || (int) $row->EVT_TYPE_CD !== $event || Carbon::parse($row->EVT_GMT_DT, 'UTC')->toDateTimeString() !== $date) {
            throw new IpsOperationException('IPS no confirmó el evento y su estado actual. Operación revertida.', 502);
        }
        $expectedState = $db->table('dbo.C_EVENT_TYPES')->where('EVENT_TYPE_CD', $event)->value('RESULT_STATE_IND_CD');
        if ($expectedState !== null && (int) $row->STATE_IND_CD !== (int) $expectedState) {
            throw new IpsOperationException('IPS no confirmó el estado resultante del evento. Operación revertida.', 502);
        }
        if ($expectedNextOffice !== null) {
            $eventRow = $db->table('dbo.L_MAILITM_EVENTS')->where('MAILITM_PID', $pid)
                ->where('EVENT_TYPE_CD', $event)->where('EVENT_GMT_DT', $date)->first();
            if (! $eventRow || (int) $eventRow->NEXT_OFFICE_CD !== $expectedNextOffice) {
                throw new IpsOperationException('IPS no confirmó la oficina de ruta del evento. Operación revertida.', 502);
            }
        }
        if ($expectedReceptaclePid !== null && (string) ($row->EVT_RECPTCL_PID ?? '') !== $expectedReceptaclePid) {
            throw new IpsOperationException('IPS no confirmó que el paquete quedó asociado al envase recibido.', 502);
        }
        if ($expectedConditionCd !== null) {
            $eventRow = $db->table('dbo.L_MAILITM_EVENTS')->where('MAILITM_PID', $pid)
                ->where('EVENT_TYPE_CD', $event)->where('EVENT_GMT_DT', $date)->first();
            if (! $eventRow || (int) ($eventRow->CONDITION_CD ?? 0) !== $expectedConditionCd) {
                throw new IpsOperationException('IPS no confirmó la condición registrada para el paquete.', 502);
            }
        }
        if ($event === 37 && (int) $row->STATE_IND_CD !== 5) {
            throw new IpsOperationException('IPS no confirmó la entrega y el receptor. Operación revertida.', 502);
        }
        $delivery = $db->table('dbo.L_MAILITM_DELIV_INFOS')
            ->where('MAILITM_PID', $pid)->where('EVENT_TYPE_CD', 37)->where('EVENT_GMT_DT', $date)
            ->when($signatory !== null && $signatory !== '', fn ($q) => $q->where('SIGNATORY_NM', $signatory));
        if ($event === 37 && ! $delivery->exists()) {
            throw new IpsOperationException('IPS no confirmó la entrega y el receptor. Operación revertida.', 502);
        }
    }
}
