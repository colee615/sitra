<?php

namespace App\Services;

use App\Exceptions\IpsOperationException;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class IpsRepository
{
    public function connection(): Connection
    {
        return DB::connection(config('ips.connection'));
    }

    public function catalog(): array
    {
        $db = $this->connection();

        return [
            'events' => $db->table('dbo.C_EVENT_TYPES as e')
                ->leftJoin('dbo.CT_EVENT_TYPES as t', fn ($join) => $join->on('t.EVENT_TYPE_CD', '=', 'e.EVENT_TYPE_CD')->where('t.LANGUAGE_CD', 'ES'))
                ->where('e.MAILUNIT_TYPE_CD', 'MI')->whereIn('e.VALID_IND', ['1', '3'])
                ->orderBy('e.EVENT_TYPE_CD')->get(['e.EVENT_TYPE_CD', 'e.EVENT_TYPE_NM', 't.LOCAL_EVENT_TYPE_NM', 'e.RESULT_STATE_IND_CD']),
            'operations' => config('ips.events'),
            'offices' => $db->table('dbo.N_OWN_OFFICES')->whereIn('VALID_IND', ['1', '3'])->orderBy('OFFICE_NM')->get(['OWN_OFFICE_CD', 'OFFICE_FCD', 'OFFICE_NM']),
            'mail_classes' => $db->table('dbo.C_MAIL_CLASSES')->orderBy('MAIL_CLASS_CD')->get(['MAIL_CLASS_CD', 'MAIL_CLASS_NM']),
            'countries' => $db->table('dbo.C_COUNTRIES')->orderBy('COUNTRY_CD')->get(['COUNTRY_CD', 'COUNTRY_NM']),
            'non_delivery_reasons' => $db->table('dbo.C_NON_DELIVERY_REASONS')->whereIn('VALID_IND', ['1', '3'])->get(['NON_DELIVERY_REASON_CD', 'NON_DELIVERY_REASON_NM']),
            'non_delivery_measures' => $db->table('dbo.C_NON_DELIVERY_MEASURES')->whereIn('VALID_IND', ['1', '3'])->get(['NON_DELIVERY_MEASURE_CD', 'NON_DELIVERY_MEASURE_NM']),
            'writes_enabled' => config('ips.writes_enabled'),
        ];
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
            $query->whereIn(DB::raw('COALESCE(op.EVENT_TYPE_CD, m.EVT_TYPE_CD)'), $status === 'pending'
                    ? config('ips.delivery_candidate_events') : config('ips.reception_candidate_events'))
                ->whereIn('m.STATE_IND_CD', $status === 'pending' ? [0, 8] : [0, 2, 3, 6])
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
                if ($status !== 'pending') {
                    $q->orWhere('op.NEXT_OFFICE_CD', $office);
                }
            });
            if ($status === 'reception') {
                $query->where(function ($q) use ($office) {
                    $q->whereNotIn(DB::raw('COALESCE(op.EVENT_TYPE_CD, m.EVT_TYPE_CD)'), [35, 72])
                        ->orWhere('op.NEXT_OFFICE_CD', $office);
                });
            }
        }
        if (! empty($filters['q'])) {
            $code = strtoupper(trim($filters['q']));
            $query->where(fn ($q) => $q->where('m.MAILITM_FID', $code)->orWhere('m.MAILITM_LOCAL_ID', $code));
        }
        foreach (['event_cd' => 'm.EVT_TYPE_CD'] as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
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

    public function detail(string $code): array
    {
        $item = $this->find($code);
        if (! $item) {
            throw new IpsOperationException('Paquete no encontrado en IPS.', 404);
        }

        $this->enrich(collect([$item]));

        return [
            'package' => $this->present($item),
            'events' => $this->connection()->table('dbo.L_MAILITM_EVENTS as e')
                ->leftJoin('dbo.C_EVENT_TYPES as t', 't.EVENT_TYPE_CD', '=', 'e.EVENT_TYPE_CD')
                ->where('e.MAILITM_PID', $item->MAILITM_PID)->orderByDesc('e.EVENT_GMT_DT')
                ->get(['e.EVENT_TYPE_CD', 'e.EVENT_GMT_DT', 'e.EVENT_LOCAL_OFFSET', 'e.EVENT_OFFICE_CD', 't.EVENT_TYPE_NM']),
        ];
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
        $offices = $this->connection()->table('dbo.N_OWN_OFFICES')->get()->keyBy('OWN_OFFICE_CD');
        foreach ($rows as $row) {
            $customer = $customers->get($row->MAILITM_PID);
            $row->RECIPIENT_NAME = $customer ? trim(($customer->CUSTOMER_FORENAME ?? '').' '.($customer->CUSTOMER_NAME ?? '')) : null;
            $row->RECIPIENT_PHONE = $customer?->CUSTOMER_PHONE_NO;
            $row->RECIPIENT_CITY = $customer?->CUSTOMER_CITY;
            $row->RECIPIENT_ADDRESS = $customer?->CUSTOMER_ADDRESS;
            $history = $events->get($row->MAILITM_PID, collect());
            $op = $history->first(fn ($e) => ! in_array((int) $e->EVENT_TYPE_CD, IpsStagePolicy::TECHNICAL_EVENTS, true));
            $row->OP_EVENT_CD = $op?->EVENT_TYPE_CD ?? $row->EVT_TYPE_CD;
            $row->OP_OFFICE_CD = $op?->EVENT_OFFICE_CD ?? $row->EVT_OFFICE_CD;
            $row->NEXT_OFFICE_CD = $op?->NEXT_OFFICE_CD;
            $row->OFFICE_NM = $offices->get($row->OP_OFFICE_CD)?->OFFICE_NM;
            $row->NEXT_OFFICE_NM = $offices->get($row->NEXT_OFFICE_CD)?->OFFICE_NM;
            $row->TERMINAL = $history->contains(fn ($e) => in_array((int) $e->EVENT_TYPE_CD, [37, 76], true));
        }
    }

    public function presentForOffice(object $item, ?int $office): array
    {
        $package = $this->present($item);
        $package['allowed_actions'] = $office ? app(IpsStagePolicy::class)->actions($package, $office) : [];

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
            'terminal' => (bool) ($item->TERMINAL ?? false),
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

    public function verifyWrite(string $pid, int $event, string $date, ?string $signatory): void
    {
        $db = $this->connection();
        $row = $db->table('dbo.L_MAILITMS')->where('MAILITM_PID', $pid)->first();
        $exists = $db->table('dbo.L_MAILITM_EVENTS')->where('MAILITM_PID', $pid)
            ->where('EVENT_TYPE_CD', $event)->where('EVENT_GMT_DT', $date)->exists();
        if (! $exists || ! $row || (int) $row->EVT_TYPE_CD !== $event || Carbon::parse($row->EVT_GMT_DT, 'UTC')->toDateTimeString() !== $date) {
            throw new IpsOperationException('IPS no confirmó el evento y su estado actual. Operación revertida.', 502);
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
