<?php

namespace App\Console\Commands;

use App\Services\IpsRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class IpsDiagnose extends Command
{
    protected $signature = 'ips:diagnose {--identities : Show technical identity candidates without passwords}';

    protected $description = 'Diagnóstico de solo lectura: conexiones, contratos IPS y configuración de escritura';

    public function handle(IpsRepository $ips): int
    {
        $failed = false;
        try {
            DB::connection()->select('SELECT 1');
            $this->info('Base principal: '.config('database.default').' conectada.');
            if (! Schema::hasTable('ips_operations')) {
                $this->warn('Falta migrar ips_operations en la base principal.');
                $failed = true;
            }
        } catch (Throwable $e) {
            $this->error('Base principal no disponible ('.$e::class.').');
            $failed = true;
        }
        try {
            $db = $ips->connection();
            $name = $db->selectOne('SELECT DB_NAME() AS name')->name;
            $this->info('Conexión IPS: '.$name);
            if ($name !== config('ips.expected_database')) {
                $this->error('Base IPS inesperada.');
                $failed = true;
            }
            foreach (config('ips-procedures') as $procedure => $expected) {
                $actual = $db->select('SELECT name FROM sys.parameters WHERE object_id = OBJECT_ID(?) ORDER BY parameter_id', ['dbo.'.$procedure]);
                $valid = array_map(fn ($p) => ltrim($p->name, '@'), $actual) === $expected;
                $execute = $db->selectOne("SELECT HAS_PERMS_BY_NAME(?, 'OBJECT', 'EXECUTE') AS allowed", ['dbo.'.$procedure])->allowed;
                $this->line($procedure.': contrato '.($valid ? 'compatible' : 'incompatible').', EXECUTE '.($execute ? 'sí' : 'no'));
                $failed = $failed || ! $valid || ! $execute;
            }
            foreach (config('ips.events') as $code => $definition) {
                $event = $ips->event($definition['id']);
                $this->line($code.' => '.$definition['id'].' · '.($event->EVENT_TYPE_NM ?? 'NO ENCONTRADO'));
                $failed = $failed || ! $event;
            }
            if ($this->option('identities')) {
                $users = $db->table('dbo.L_USERS')->whereIn('VALID_IND', ['1', '3'])
                    ->where(fn ($q) => $q->where('USER_TYPE', '2')->orWhere('USER_FID', 'like', '%sitra%')->orWhere('USER_FID', 'like', '%integration%'))
                    ->get(['USER_PID', 'USER_FID', 'USER_TYPE']);
                $stations = $db->table('dbo.L_WORKSTATIONS')->whereIn('VALID_IND', ['1', '3'])
                    ->where(fn ($q) => $q->where('WORKSTATION_FID', 'like', '%Virtual%')->orWhere('WORKSTATION_FID', 'like', '%sitra%'))
                    ->get(['WORKSTATION_PID', 'WORKSTATION_FID']);
                $this->table(['USER_PID', 'Identificador', 'Tipo'], $users->map(fn ($r) => (array) $r)->all());
                $this->table(['WORKSTATION_PID', 'Identificador'], $stations->map(fn ($r) => (array) $r)->all());
                $this->warn('Candidatos existentes; sus nombres no prueban que estén asignados a SITRA.');
            }
            if (! config('ips.user_pid') || ! config('ips.workstation_pid')) {
                $this->warn('Faltan IPS_USER_PID / IPS_WORKSTATION_PID.');
                $failed = true;
            }
            $this->line('Escrituras: '.(config('ips.writes_enabled') ? 'habilitadas' : 'deshabilitadas'));
            $this->line('Este diagnóstico no registra paquetes ni ejecuta procedimientos de escritura.');
        } catch (Throwable $e) {
            $this->error('Diagnóstico IPS incompleto ('.$e::class.').');
            $failed = true;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
