<?php

namespace App\Services\Postal;

final class ShipmentCode
{
    public static function normalize(string $value): string
    {
        return strtoupper(trim($value));
    }

    public static function describe(string $value): array
    {
        $code = self::normalize($value);
        if (!preg_match('/^[A-Z]{2}[0-9]{9}[A-Z]{2}$/D', $code)) {
            return ['type' => 'Código local', 'valid' => null, 'message' => 'Se buscará el identificador exacto en los registros disponibles.'];
        }
        $sum = 0;
        foreach ([8, 6, 4, 2, 3, 5, 9, 7] as $i => $weight) {
            $sum += (int) $code[$i + 2] * $weight;
        }
        $digit = 11 - ($sum % 11);
        $digit = $digit === 10 ? 0 : ($digit === 11 ? 5 : $digit);
        $valid = $digit === (int) $code[10];

        return [
            'type' => 'S10', 'valid' => $valid,
            'message' => $valid ? 'Dígito de control correcto.' : 'El dígito de control no coincide. Comprueba el código; la búsqueda se mantiene sin modificarlo.',
            'issuer' => substr($code, -2),
        ];
    }
}
