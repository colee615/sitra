<?php

namespace App\Support;

class IpsDeliveryReceipt
{
    public static function confirmed(array $package, int $office): bool
    {
        $event = (int) ($package['operational_event_cd'] ?? $package['event_cd'] ?? 0);
        $currentOffice = (int) ($package['operational_office_cd'] ?? $package['office_cd'] ?? -1);

        return $currentOffice === $office
            && ($package['destination_country'] ?? '') === 'BO'
            && empty($package['terminal'])
            && in_array((int) ($package['state_cd'] ?? -1), [0, 8], true)
            && in_array($event, [32, 36, 39, 74, 75], true)
            && ($event === 32 || ($package['destination_receipt_confirmed'] ?? false) === true);
    }

    /** History must be ordered from the most recent event to the oldest. */
    public static function fromHistory(iterable $events, int $office): bool
    {
        foreach ($events as $event) {
            $code = (int) data_get($event, 'EVENT_TYPE_CD', 0);
            $eventOffice = (int) data_get($event, 'EVENT_OFFICE_CD', -1);
            if ($code === 32) {
                return $eventOffice === $office;
            }
            if (in_array($code, [13, 30, 31, 34, 35, 37, 38, 72, 76, 1251], true)
                || (in_array($code, [36, 39, 74, 75], true) && $eventOffice !== $office)) {
                return false;
            }
        }

        return false;
    }
}
