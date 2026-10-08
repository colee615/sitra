<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Throwable;

final class UtcTimestamp
{
    public static function iso8601(mixed $value): string
    {
        if (empty($value)) {
            return '';
        }

        try {
            return Carbon::parse($value, 'UTC')->utc()->format('Y-m-d\\TH:i:s.v\\Z');
        } catch (Throwable) {
            return trim((string) $value);
        }
    }
}
