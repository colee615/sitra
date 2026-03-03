<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Models\User;

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

    if (!$user) {
        $this->error("No existe usuario con email: {$email}");
        return self::FAILURE;
    }

    if (!$user->hasRole('admin')) {
        $this->error("El usuario {$email} debe tener rol admin para emitir token de integracion.");
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
        if (!is_numeric($years) || (int) $years < 1) {
            $this->error('El valor de --years debe ser un entero mayor o igual a 1.');
            return self::FAILURE;
        }
        $expiresAt = now()->addYears((int) $years);
    } elseif ($days !== null && $days !== '') {
        if (!is_numeric($days) || (int) $days < 1) {
            $this->error('El valor de --days debe ser un entero mayor o igual a 1.');
            return self::FAILURE;
        }
        $expiresAt = now()->addDays((int) $days);
    }

    $token = $user->createToken($name, [$ability], $expiresAt);

    $this->info('Token creado. Guardalo ahora, luego no podras volver a verlo:');
    $this->line($token->plainTextToken);
    $this->line('Expira en: '.($expiresAt ? $expiresAt->toDateTimeString() : 'sin expiracion'));

    return self::SUCCESS;
})->purpose('Crea un Bearer token tecnico para consumir la API SQL Server');

Artisan::command('token:revoke {email} {--name=}', function () {
    $email = (string) $this->argument('email');
    $name = (string) $this->option('name');

    $user = User::query()->where('email', $email)->first();

    if (!$user) {
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
