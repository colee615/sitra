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

        if ($displayName === '') {
            $displayName = $baseRawEventType;
        }

        return trim($displayName);
    }

    public function syncFromSqlServerCatalog(): array
    {
        $connection = (string) config('tracking.sqlserver.connection', 'sqlsrv');

        $rows = DB::connection($connection)->select("
            SELECT
                EVENT_TYPE_CD,
                RTRIM(LTRIM(LOCAL_EVENT_TYPE_NM)) AS RAW_NAME
            FROM dbo.CT_EVENT_TYPES
            WHERE LANGUAGE_CD = 'ES'
            ORDER BY EVENT_TYPE_CD
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
