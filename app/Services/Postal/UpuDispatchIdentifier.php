<?php

namespace App\Services\Postal;

/** Decodes UPU S8 dispatch and S9 receptacle identifiers without changing their stored value. */
final class UpuDispatchIdentifier
{
    public static function parse(string $identifier): ?array
    {
        $code = strtoupper((string) preg_replace('/[\s-]+/', '', trim($identifier)));

        if (preg_match('/^[A-Z]{15}[0-9]{5}$/D', $code) === 1) {
            return self::dispatch($code);
        }

        if (preg_match('/^[A-Z]{15}[0-9]{14}$/D', $code) === 1) {
            $dispatch = self::dispatch(substr($code, 0, 20));
            $highest = $code[23];
            $certified = $code[24];
            $weight = (int) substr($code, 25, 4);

            return array_replace($dispatch, [
                'type' => 's9',
                'code' => $code,
                'dispatch_code' => substr($code, 0, 20),
                'receptacle_sequence' => substr($code, 20, 3),
                'highest_receptacle_indicator' => $highest,
                'highest_receptacle_label' => self::indicatorLabel($highest, 'Es la saca con el número más alto del despacho', 'No es la saca con el número más alto del despacho'),
                'certified_indicator' => $certified,
                'certified_label' => self::indicatorLabel($certified, 'Contiene envíos certificados o con valor declarado', 'No contiene envíos certificados o con valor declarado'),
                'weight_code' => substr($code, 25, 4),
                'encoded_gross_weight_kg' => $weight === 9999 ? null : $weight / 10,
                'encoded_gross_weight_label' => $weight === 9999 ? '9999 (peso superior a 999,8 kg)' : number_format($weight / 10, 1, ',', '.') . ' kg',
            ]);
        }

        return null;
    }

    private static function dispatch(string $code): array
    {
        return [
            'type' => 's8',
            'code' => $code,
            'origin_ctci' => substr($code, 0, 6),
            'destination_ctci' => substr($code, 6, 6),
            'mail_category' => $code[12],
            'mail_class' => $code[13],
            'mail_subclass' => $code[14],
            'year_digit' => $code[15],
            'dispatch_sequence' => substr($code, 16, 4),
        ];
    }

    private static function indicatorLabel(string $value, string $yes, string $no): string
    {
        return match ($value) {
            '0' => $no,
            '1' => $yes,
            '9' => 'El código no contiene este dato',
            default => 'Indicador no reconocido (' . $value . ')',
        };
    }
}
