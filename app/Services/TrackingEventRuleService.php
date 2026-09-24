<?php

namespace App\Services;

use App\Models\TrackingEventRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TrackingEventRuleService
{
    private const CACHE_KEY = 'tracking:event_rules:v1';

    public function present(?string $rawEventType, ?string $sourceDb, int|string|null $eventTypeCd): ?string
    {
        $sourceDb = $this->normalizeSourceDb($sourceDb);
        $rawEventType = $this->normalizeText($rawEventType ?? '');
        $baseRawEventType = $this->stripContextSuffix($rawEventType);
        $eventTypeCd = is_numeric($eventTypeCd) ? (int) $eventTypeCd : null;

        $rule = $this->resolveRule($sourceDb, $eventTypeCd, $baseRawEventType);

        if ($rule && !$rule->is_visible) {
            return null;
        }

        $displayName = $this->stripContextSuffix(trim((string) ($rule?->display_name ?? '')));

        // Las reglas sincronizadas conservan el nombre técnico de IPS como
        // display_name. Para el rastreo público usamos una etiqueta clara y
        // consistente; las reglas siguen controlando visibilidad y los eventos
        // que no tienen una etiqueta pública conservan su traducción configurada.
        if ($displayName === '') {
            $displayName = $baseRawEventType;
        }

        return trim($displayName);
    }

    /**
     * Convierte descripciones de IPS/EDI que no tienen código conocido en
     * mensajes públicos. Así ningún proveedor puede exponer su texto técnico.
     */
    private function friendlyNameFromRaw(string $raw): string
    {
        $text = mb_strtolower($this->normalizeText($raw), 'UTF-8');

        return match (true) {
            str_contains($text, 'send item abroad') || str_contains($text, 'enviar envío al extranjero')
                => 'Tu paquete salió del país',
            str_contains($text, 'receive item at office of exchange (otb)')
                || str_contains($text, 'recibir envío en oficina de cambio (salida)')
                => 'Recibimos tu paquete antes de viajar',
            str_contains($text, 'receive item at office of exchange (inb)')
                || str_contains($text, 'recibir envío en oficina de cambio (entrada)')
                => 'Paquete recibido en oficina de cambio',
            str_contains($text, 'attempted delivery') || str_contains($text, 'intento de entrega')
                => 'El cartero intentó entregar tu paquete',
            str_contains($text, 'delivered') || str_contains($text, 'entregado')
                => 'Tu paquete fue entregado',
            str_contains($text, 'customs') || str_contains($text, 'aduana')
                => 'Estamos revisando tu paquete en aduana',
            str_contains($text, 'dispatch') || str_contains($text, 'despacho')
                => 'Tu paquete salió hacia su siguiente lugar',
            default => '',
        };
    }

    public function customerEventName(?int $eventTypeCd): string
    {
        return match ($eventTypeCd) {
            1 => 'Recibimos tu paquete',
            2 => 'Tu paquete salió hacia su siguiente lugar',
            3 => 'Paquete recibido en el país de origen',
            4 => 'Tu paquete está siendo revisado',
            5 => 'Tu paquete llegó a otro lugar',
            6 => 'Aduana registró un motivo de retención de tu paquete',
            7 => 'Aduana devolvió el paquete',
            8 => 'Tu paquete está preparado para ser enviado',
            9 => 'Tu paquete salió de la bolsa de transporte',
            10, 11, 79 => 'Actualizamos los datos de tu paquete',
            12 => 'Paquete enviado al país de destino',
            13 => 'Tu paquete fue preparado para viajar',
            14 => 'Tu paquete continuó su viaje',
            15 => 'El viaje de tu paquete fue cancelado',
            30 => 'Paquete recibido en oficina de cambio',
            31 => 'Estamos revisando tu paquete en aduana',
            32 => 'Tu paquete llegó a la oficina donde lo recogerás',
            33 => 'Tu paquete llegó a un nuevo lugar',
            34 => 'Aduana terminó de revisar los datos',
            35 => 'Tu paquete salió hacia la oficina de entrega',
            36 => 'El cartero intentó entregar tu paquete',
            37, 1250 => 'Tu paquete fue entregado',
            38 => 'Aduana devolvió el paquete',
            39, 67 => 'El cartero recibió tu paquete',
            40, 130 => 'El paquete llegó al país de destino',
            41 => 'Registramos los datos de quien recibirá el paquete',
            42, 43, 44 => 'Confirmamos que tu paquete llegó a Correos',
            45 => 'Registramos tu paquete automáticamente',
            60, 61, 62, 63 => 'Actualizamos la información de tu paquete',
            64, 65, 66 => 'Registramos el viaje de tu paquete',
            68 => 'Registramos que tu paquete llegó',
            69, 70 => 'Tu paquete está esperando en Correos',
            71 => 'Tu paquete está siendo ordenado',
            72 => 'Tu paquete salió del centro de ordenamiento',
            73 => 'Tu paquete está esperando en la oficina de entrega',
            74 => 'El cartero lleva tu paquete',
            75 => 'Ya puedes recoger tu paquete',
            76 => 'La importación de tu paquete fue detenida',
            194 => 'La saca que contiene tu envío quedó bajo custodia de Aduana o seguridad',
            77 => 'Tu paquete está preparado para ser transportado',
            78 => 'Registramos que recogimos tu paquete',
            81 => 'Tu paquete fue detenido para devolverlo',
            default => '',
        };
    }

    public function syncFromSqlServerCatalog(): array
    {
        $connection = (string) config('tracking.sqlserver.connection', 'sqlsrv');

        $rows = DB::connection($connection)->select("
            SELECT
                translations.EVENT_TYPE_CD,
                RTRIM(LTRIM(LOCAL_EVENT_TYPE_NM)) AS RAW_NAME
            FROM dbo.CT_EVENT_TYPES AS translations
            INNER JOIN dbo.C_EVENT_TYPES AS event_types
                ON event_types.EVENT_TYPE_CD = translations.EVENT_TYPE_CD
            WHERE translations.LANGUAGE_CD = 'ES'
                AND event_types.MAILUNIT_TYPE_CD = 'MI'
            ORDER BY translations.EVENT_TYPE_CD
        ");

        $created = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $eventTypeCd = isset($row->EVENT_TYPE_CD) ? (int) $row->EVENT_TYPE_CD : null;
            $rawName = $this->normalizeText((string) ($row->RAW_NAME ?? ''));

            $rule = TrackingEventRule::query()
                ->where('source_db', '*')
                ->where('event_type_cd', $eventTypeCd)
                ->where('raw_name', $rawName)
                ->first();

            if ($rule) {
                if ($rule->source_db === '*') {
                    $rule->display_name = $rawName;
                    $rule->append_source_context = false;
                }

                if ($rule->isDirty()) {
                    $rule->save();
                    $updated++;
                }

                continue;
            }

            TrackingEventRule::create([
                'source_db' => '*',
                'event_type_cd' => $eventTypeCd,
                'raw_name' => $rawName,
                'display_name' => $rawName,
                'is_visible' => true,
                'append_source_context' => false,
            ]);

            $created++;
        }

        $this->clearCache();

        return [
            'total' => count($rows),
            'created' => $created,
            'updated' => $updated,
        ];
    }

    public function commonSourceOptions(): array
    {
        return [
            '*' => 'General',
            'IPS5Db' => 'IPS5Db - Paquete directo',
            'IPS5Db-EDI' => 'IPS5Db - EDI',
            'IPS5Db-DOM-RECPTCL' => 'IPS5Db - Envase nacional indirecto',
            'IPS5Db-DOM-DESPTCH' => 'IPS5Db - Despacho nacional indirecto',
            'IPS5Db-RECPTCL' => 'IPS5Db - Receptaculo indirecto',
        ];
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function normalizeText(string $value): string
    {
        $value = str_replace(
            ['EnvÃƒÂ­o', 'envÃƒÂ­o', 'ubicaciÃƒÂ³n', 'trÃƒÂ¡nsito', 'devoluciÃƒÂ³n', 'informaciÃƒÂ³n', 'PaÃƒÂ­s'],
            ['Envío', 'envío', 'ubicación', 'tránsito', 'devolución', 'información', 'País'],
            $value
        );

        $value = str_replace(
            ['EnvÃƒÆ’Ã‚Â­o', 'envÃƒÆ’Ã‚Â­o', 'ubicaciÃƒÆ’Ã‚Â³n', 'trÃƒÆ’Ã‚Â¡nsito', 'devoluciÃƒÆ’Ã‚Â³n', 'informaciÃƒÆ’Ã‚Â³n', 'PaÃƒÆ’Ã‚Â­s'],
            ['Envío', 'envío', 'ubicación', 'tránsito', 'devolución', 'información', 'País'],
            $value
        );

        $value = str_replace(
            ['Ã¡', 'Ã©', 'Ã­', 'Ã³', 'Ãº', 'Ã±', 'Ã', 'Ã‰', 'Ã', 'Ã“', 'Ãš', 'Ã‘', 'PaÃ­s', 'trÃ¡nsito', 'ubicaciÃ³n', 'devoluciÃ³n', 'informaciÃ³n'],
            ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'Á', 'É', 'Í', 'Ó', 'Ú', 'Ñ', 'País', 'tránsito', 'ubicación', 'devolución', 'información'],
            $value
        );

        return trim($value);
    }

    private function resolveRule(string $sourceDb, ?int $eventTypeCd, string $rawEventType): ?TrackingEventRule
    {
        $rules = $this->rules();

        $candidates = [
            fn (TrackingEventRule $rule) => $rule->source_db === $sourceDb && $eventTypeCd !== null && $rule->event_type_cd === $eventTypeCd,
            fn (TrackingEventRule $rule) => $rule->source_db === $sourceDb && $rawEventType !== '' && $rule->raw_name === $rawEventType,
            fn (TrackingEventRule $rule) => $rule->source_db === '*' && $eventTypeCd !== null && $rule->event_type_cd === $eventTypeCd,
            fn (TrackingEventRule $rule) => $rule->source_db === '*' && $rawEventType !== '' && $rule->raw_name === $rawEventType,
        ];

        foreach ($candidates as $candidate) {
            $match = $rules->first($candidate);

            if ($match) {
                return $match;
            }
        }

        return null;
    }

    private function rules(): Collection
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            return TrackingEventRule::query()
                ->orderByRaw("CASE WHEN source_db = '*' THEN 1 ELSE 0 END")
                ->orderBy('sort_order')
                ->orderBy('source_db')
                ->orderBy('event_type_cd')
                ->orderBy('raw_name')
                ->get();
        });
    }

    private function normalizeSourceDb(?string $sourceDb): string
    {
        $sourceDb = trim((string) $sourceDb);

        return $sourceDb !== '' ? $sourceDb : '*';
    }

    private function stripContextSuffix(string $eventType): string
    {
        return trim((string) preg_replace('/\s+\[Indirecto: [^\]]+\]$/u', '', $eventType));
    }
}
