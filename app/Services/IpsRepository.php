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
        $query = $this->connection()->table('dbo.L_MAILITMS as m')
            ->leftJoin('dbo.C_EVENT_TYPES as e', 'e.EVENT_TYPE_CD', '=', 'm.EVT_TYPE_CD')
            ->leftJoin('dbo.N_OWN_OFFICES as o', 'o.OWN_OFFICE_CD', '=', 'm.EVT_OFFICE_CD');

        if (($filters['status'] ?? 'all') === 'pending') {
            $query->where('m.DEST_COUNTRY_CD', config('ips.destination_country'))
                ->whereIn('m.EVT_TYPE_CD', config('ips.delivery_candidate_events'))
                ->whereIn('m.STATE_IND_CD', [0, 8])
                ->whereNotExists(function ($q) {
                    $q->selectRaw('1')->from('dbo.L_MAILITM_EVENTS as delivered')
                        ->whereColumn('delivered.MAILITM_PID', 'm.MAILITM_PID')
                        ->whereIn('delivered.EVENT_TYPE_CD', [37, 76]);
                });
        } elseif (($filters['status'] ?? '') === 'delivered') {
            $query->where('m.STATE_IND_CD', 5);
        }
        if (! empty($filters['q'])) {
            $code = strtoupper(trim($filters['q']));
            $query->where(fn ($q) => $q->where('m.MAILITM_FID', $code)->orWhere('m.MAILITM_LOCAL_ID', $code));
        }
        foreach (['office_cd' => 'm.EVT_OFFICE_CD', 'event_cd' => 'm.EVT_TYPE_CD'] as $filter => $column) {
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
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 25)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $rows = $query->orderByDesc('m.EVT_GMT_DT')->orderBy('m.MAILITM_PID')
            ->offset(($page - 1) * $perPage)->limit($perPage + 1)
            ->get(['m.MAILITM_PID', 'm.MAILITM_FID', 'm.MAILITM_LOCAL_ID', 'm.MAILITM_WEIGHT', 'm.ORIG_COUNTRY_CD', 'm.DEST_COUNTRY_CD', 'm.STATE_IND_CD', 'm.EVT_TYPE_CD', 'm.EVT_GMT_DT', 'm.EVT_OFFICE_CD', 'e.EVENT_TYPE_NM', 'o.OFFICE_NM']);

        return [
            'data' => $rows->take($perPage)->map(fn ($row) => $this->present($row))->values()->all(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'has_more' => $rows->count() > $perPage, 'status' => $filters['status'] ?? 'all'],
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

        return [
            'package' => $this->present($item),
            'events' => $this->connection()->table('dbo.L_MAILITM_EVENTS as e')
                ->leftJoin('dbo.C_EVENT_TYPES as t', 't.EVENT_TYPE_CD', '=', 'e.EVENT_TYPE_CD')
                ->where('e.MAILITM_PID', $item->MAILITM_PID)->orderByDesc('e.EVENT_GMT_DT')
                ->get(['e.EVENT_TYPE_CD', 'e.EVENT_GMT_DT', 'e.EVENT_LOCAL_OFFSET', 'e.EVENT_OFFICE_CD', 't.EVENT_TYPE_NM']),
        ];
    }

    public function present(object $item): array
    {
        return [
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
        if ($event === 37 && ((int) $row->STATE_IND_CD !== 5 || ! $db->table('dbo.L_MAILITM_DELIV_INFOS')
            ->where('MAILITM_PID', $pid)->where('EVENT_TYPE_CD', 37)->where('EVENT_GMT_DT', $date)
            ->where('SIGNATORY_NM', $signatory)->exists())) {
            throw new IpsOperationException('IPS no confirmó la entrega y el receptor. Operación revertida.', 502);
        }
    }
}
