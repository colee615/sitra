<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('token:issue {email} {--name=integration-sitra} {--ability=sqlserver.read} {--days=} {--years=} {--expires-at=}', function () {
    $email = (string) $this->argument('email');
    $name = (string) $this->option('name');
    $ability = (string) $this->option('ability');
    $days = $this->option('days');
    $years = $this->option('years');
    $expiresAtInput = $this->option('expires-at');

    $user = User::query()->where('email', $email)->first();

    if (! $user) {
        $this->error("No existe usuario con email: {$email}");

        return self::FAILURE;
    }

    $expiresAt = null;

    if (is_string($expiresAtInput) && trim($expiresAtInput) !== '') {
        try {
            $expiresAt = now()->parse($expiresAtInput);
        } catch (\Throwable $e) {
            $this->error('Formato invalido en --expires-at. Usa por ejemplo: 2029-03-03 23:59:59');

            return self::FAILURE;
        }
    } elseif ($years !== null && $years !== '') {
        if (! is_numeric($years) || (int) $years < 1) {
            $this->error('El valor de --years debe ser un entero mayor o igual a 1.');

            return self::FAILURE;
        }
        $expiresAt = now()->addYears((int) $years);
    } elseif ($days !== null && $days !== '') {
        if (! is_numeric($days) || (int) $days < 1) {
            $this->error('El valor de --days debe ser un entero mayor o igual a 1.');

            return self::FAILURE;
        }
        $expiresAt = now()->addDays((int) $days);
    }

    $abilities = array_values(array_unique(array_filter(array_map('trim', explode(',', $ability)))));
    $allowed = ['sqlserver.read', 'cds.read', 'ips.read', 'ips.write', 'ips.create', 'ips.events', 'ips.deliver', 'ips.operations', 'customs.read', 'customs.prealerts.read'];
    if ($abilities === [] || array_diff($abilities, $allowed)) {
        $this->error('Permisos admitidos: '.implode(',', $allowed));

        return self::FAILURE;
    }
    $isAdmin = $user->hasRole('admin');
    $customsAbilities = array_intersect($abilities, ['customs.read', 'customs.prealerts.read']);
    if (!$isAdmin && array_diff($abilities, $customsAbilities)) {
        $this->error("El usuario {$email} no es administrador; solo se emiten tokens aduaneros según sus permisos SITRA.");
        return self::FAILURE;
    }
    if (!$isAdmin) {
        foreach ($customsAbilities as $customsAbility) {
            $hasReadPermission = $user->checkPermissionTo('customs.read', 'web')
                || $user->checkPermissionTo('customs.process', 'web')
                || ($customsAbility === 'customs.prealerts.read'
                    && ($user->checkPermissionTo('customs.prealerts.read', 'web') || $user->can('postal.cds')));
            if (!$hasReadPermission) {
                $this->error("El usuario {$email} no tiene permiso SITRA {$customsAbility}.");
                return self::FAILURE;
            }
        }
    }
    $token = $user->createToken($name, $abilities, $expiresAt);

    $this->info('Token creado. Guardalo ahora, luego no podras volver a verlo:');
    $this->line($token->plainTextToken);
    $this->line('Expira en: '.($expiresAt ? $expiresAt->toDateTimeString() : 'sin expiracion'));

    return self::SUCCESS;
})->purpose('Crea un Bearer token tecnico para consumir la API SQL Server');

Artisan::command('token:revoke {email} {--name=}', function () {
    $email = (string) $this->argument('email');
    $name = (string) $this->option('name');

    $user = User::query()->where('email', $email)->first();

    if (! $user) {
        $this->error("No existe usuario con email: {$email}");

        return self::FAILURE;
    }

    $tokens = $user->tokens();

    if ($name !== '') {
        $tokens->where('name', $name);
    }

    $deleted = $tokens->delete();
    $this->info("Tokens revocados: {$deleted}");

    return self::SUCCESS;
})->purpose('Revoca tokens de integracion de un usuario');
