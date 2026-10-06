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
            'operator' => ['nullable', Rule::in(['BOA'])],
            'office' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,10}$/'],
            'cds_office' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,10}$/'],
            'service' => ['nullable', 'string', 'max:3'],
            'mail_category' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,5}$/'],
            'mail_product' => ['nullable', 'regex:/^[A-Z]A-[A-Z]Z$/'],
            'cds_origin_operator' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,10}$/'],
            'cds_destination_operator' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,10}$/'],
            'cds_state' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,10}$/'],
            'state' => ['nullable', Rule::in(array_keys(OperationalDashboard::STATES))],
            'origin' => ['nullable', 'regex:/^[A-Z]{2}$/'],
            'destination' => ['nullable', 'regex:/^[A-Z]{2}$/'],
            'type' => ['nullable', Rule::in(['national', 'international', 'unknown'])],
            'comparison' => ['nullable', Rule::in(['period', 'year'])],
        ]);
        $today = CarbonImmutable::now(config('postal.timezone'));
        $from = CarbonImmutable::parse($input['from'] ?? $today->startOfMonth()->toDateString(), config('postal.timezone'))->startOfDay();
        $to = CarbonImmutable::parse($input['to'] ?? $today->toDateString(), config('postal.timezone'))->startOfDay();
        if ($to->lessThan($from) || $from->diffInDays($to) > 365) {
            throw ValidationException::withMessages(['to' => 'Selecciona un periodo de 1 a 366 días, con la fecha final posterior o igual a la inicial.']);
        }

        return array_merge(array_fill_keys(['operator', 'office', 'cds_office', 'service', 'mail_category', 'mail_product', 'cds_origin_operator', 'cds_destination_operator', 'cds_state', 'state', 'origin', 'destination', 'type', 'comparison'], null), $input, [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'operator' => $input['operator'] ?? 'BOA',
            'comparison' => $input['comparison'] ?? 'period',
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
