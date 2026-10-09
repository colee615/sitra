<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Throwable;

final class IpsEventTime
{
    public static function present(mixed $utcValue, mixed $offsetHours = null): array
    {
        if (empty($utcValue)) {
            return ['event_at_utc' => null, 'event_at_local' => null, 'event_timezone_label' => 'Zona horaria no informada'];
        }

        try {
            $utc = Carbon::parse((string) $utcValue, 'UTC')->utc();
            $offsetMinutes = is_numeric($offsetHours) ? (int) round((float) $offsetHours * 60) : null;
            $timezone = $offsetMinutes === null ? 'UTC' : self::offsetLabel($offsetMinutes);

            return [
                'event_at_utc' => $utc->format('Y-m-d\TH:i:s\Z'),
                'event_at_local' => $utc->copy()->setTimezone($timezone)->format('Y-m-d\TH:i:sP'),
                'event_offset_minutes' => $offsetMinutes,
                'event_timezone_label' => $offsetMinutes === null
                    ? 'UTC · zona local no informada'
                    : 'Hora local · UTC'.self::offsetLabel($offsetMinutes),
            ];
        } catch (Throwable) {
            return ['event_at_utc' => (string) $utcValue, 'event_at_local' => null, 'event_timezone_label' => 'Hora de origen no disponible'];
        }
    }

    private static function offsetLabel(int $minutes): string
    {
        $sign = $minutes < 0 ? '-' : '+';
        $absolute = abs($minutes);

        return sprintf('%s%02d:%02d', $sign, intdiv($absolute, 60), $absolute % 60);
    }
}
