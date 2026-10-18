<?php

namespace App\Services;

/** Operational labels are distinct from IPS container/customs state codes. */
class IpsStagePolicy
{
    public const TECHNICAL_EVENTS = [41, 60, 61, 62, 63, 64, 65, 66, 67, 79];

    public function describe(int $event, ?int $state, bool $terminal = false): array
    {
        if ($state === 5 || $event === 37) {
            return ['key' => 'delivered', 'label' => 'Entregado', 'tone' => 'success'];
        }
        if ($event === 76 || $state === 4 || $terminal) {
            return ['key' => 'closed', 'label' => 'Operación cerrada', 'tone' => 'secondary'];
        }
        if ($state === 1 || in_array($event, [31, 34], true)) {
            return ['key' => 'customs', 'label' => 'En aduana', 'tone' => 'warning'];
        }
        [$key, $label, $tone] = match ($event) {
            30, 42, 43, 44 => ['exchange', 'En oficina de intercambio', 'info'],
            35, 72 => ['transport', 'Despachado a destino', 'info'],
            31 => ['customs', 'Enviado a Aduana', 'warning'],
            34 => ['customs', 'Información aduanera registrada', 'warning'],
            38 => ['customs_return', 'Retornado de Aduana', 'info'],
            32 => ['received', 'Recibido en oficina de entrega', 'primary'],
            75 => ['pickup', 'Disponible para retiro', 'primary'],
            74 => ['delivery', 'En reparto', 'primary'],
            39 => ['agent', 'Transferido al cartero', 'info'],
            36 => ['attempt', 'Intento de entrega fallido', 'warning'],
            71, 33 => ['sorting', 'En clasificación / ubicación interna', 'info'],
            13 => ['outbound_bag', 'En saca nacional (salida)', 'secondary'],
            70, 73 => ['held', 'Retenido en oficina', 'warning'],
            default => ['review', 'Consultar historial', 'secondary'],
        };

        return compact('key', 'label', 'tone');
    }

    public function actions(array $package, int $office): array
    {
        $event = (int) ($package['operational_event_cd'] ?? $package['event_cd']);
        $state = $package['state_cd'];
        $currentOffice = (int) ($package['operational_office_cd'] ?? $package['office_cd']);
        $next = $package['next_office_cd'] ?? null;
        if (($package['destination_country'] ?? '') === 'BO' && empty($package['terminal'])) {
            if (($package['origin_country'] ?? '') !== 'BO'
                && in_array($event, config('ips.exchange_receipt_candidate_events', []), true)
                && in_array($state, [0, 2, 3], true)
                && in_array($office, $package['exchange_office_cds'] ?? [], true)) {
                return ['EMR'];
            }
            if ($event === 30 && $state === 0 && $currentOffice === $office
                && in_array($office, $package['exchange_office_cds'] ?? [], true)) {
                return ['EDN', 'CUSTOMS_PRESENT'];
            }
            if ($event === 31 && $state === 1 && $currentOffice === $office) {
                return ['CUSTOMS_INFO', 'CUSTOMS_RETURN'];
            }
            if ($event === 34 && $state === 1 && $currentOffice === $office) {
                return ['CUSTOMS_RETURN'];
            }
            if ($event === 38 && $state === 0 && $currentOffice === $office) {
                // Customs return is already a physical receipt at this office. From here the
                // operator can route onward, hold for pickup, hand to a carrier, or deliver.
                return ['EDN', 'EDH', 'HANDOVER_AGENT', 'EMI'];
            }
            if ($event === 35 && $state === 0 && $currentOffice === $office && $next !== null) {
                return ['EIB'];
            }
            if ($event === 13 && $state === 6
                && in_array($office, $package['receipt_office_cds'] ?? [], true)) {
                return ['EMG'];
            }
        }
        if (($package['destination_country'] ?? '') !== 'BO' || ! empty($package['terminal']) || in_array($state, [1, 4, 5, 7], true)) {
            return [];
        }
        // A dispatch belongs to its destination for receipt, never to its sending office.
        $receiptOffice = in_array($event, [35, 72], true) ? $next : $currentOffice;
        $actions = [];
        if (in_array($event, [30, 33, 35, 38, 42, 43, 44, 71, 72], true)
            && $receiptOffice !== null && (int) $receiptOffice === $office
            && in_array($state, [0, 2, 3, 6], true)) {
            $actions[] = 'EMG';
        }
        if ($currentOffice === $office && $state === 0 && in_array($event, [32, 36], true)) {
            if ($event === 32) {
                $actions[] = 'EDH';
                $actions[] = 'CUSTOMS_PRESENT';
            }
            $actions[] = 'HANDOVER_AGENT';
        }
        if ($currentOffice === $office && $state === 0 && $event === 39) {
            $actions[] = 'EDG';
        }
        if ($currentOffice === $office && $state === 0 && $event === 75) {
            $actions[] = 'EMI';
        }
        if ($currentOffice === $office && in_array($state, [0, 8], true)
            && in_array($event, [32, 74, 75], true)) {
            $actions[] = 'EMI';
        }
        if ($currentOffice === $office && in_array($state, [0, 8], true) && $event === 74) {
            $actions[] = 'EMH';
        }

        return $actions;
    }

    public function inferDeliveryMode(array $package): ?string
    {
        $event = (int) ($package['operational_event_cd'] ?? $package['event_cd'] ?? 0);
        $state = isset($package['state_cd']) ? (int) $package['state_cd'] : null;

        if ($state === 1 && in_array($event, [31, 34], true)) {
            return 'NO_DOMICILIARIA';
        }

        return match ($event) {
            36, 39, 67, 74 => 'DOMICILIARIA',
            73, 75 => 'NO_DOMICILIARIA',
            default => null,
        };
    }
}
