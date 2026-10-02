<?php

namespace App\Services\Postal;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DashboardFilters
{
    public static function fromRequest(Request $request): array
    {
        $input = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'office' => ['nullable', 'integer', 'min:0', 'max:32767'],
            'service' => ['nullable', 'string', 'max:3'],
            'state' => ['nullable', Rule::in(array_keys(OperationalDashboard::STATES))],
            'origin' => ['nullable', 'regex:/^[A-Z]{2}$/'],
            'destination' => ['nullable', 'regex:/^[A-Z]{2}$/'],
            'type' => ['nullable', Rule::in(['national', 'international', 'unknown'])],
        ]);
        $today = CarbonImmutable::now(config('postal.timezone'));
        $from = CarbonImmutable::parse($input['from'] ?? $today->startOfMonth()->toDateString(), config('postal.timezone'))->startOfDay();
        $to = CarbonImmutable::parse($input['to'] ?? $today->toDateString(), config('postal.timezone'))->startOfDay();
        if ($to->lessThan($from) || $from->diffInDays($to) > 365) {
            throw ValidationException::withMessages(['to' => 'Selecciona un periodo de 1 a 366 días, con la fecha final posterior o igual a la inicial.']);
        }

        return array_merge(array_fill_keys(['office', 'service', 'state', 'origin', 'destination', 'type'], null), $input, [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
        ]);
    }

    public static function bounds(array $filters): array
    {
        return [
            CarbonImmutable::parse($filters['from'], config('postal.timezone'))->startOfDay()->utc(),
            CarbonImmutable::parse($filters['to'], config('postal.timezone'))->addDay()->startOfDay()->utc(),
        ];
    }
}
