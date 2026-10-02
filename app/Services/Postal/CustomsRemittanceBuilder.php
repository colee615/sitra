<?php

namespace App\Services\Postal;

use Illuminate\Validation\ValidationException;

final class CustomsRemittanceBuilder
{
    private const MAX_CODES = 50;

    public function __construct(private CdsRepository $cds) {}

    /** Build a read-only customs handoff grouped by postal identifier, not CDS row. */
    public function build(string $input): array
    {
        $tokens = preg_split('/[\s,;]+/u', strtoupper(trim($input)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $codes = array_values(array_unique($tokens));

        if (count($codes) > self::MAX_CODES) {
            throw ValidationException::withMessages(['codigos' => 'Puedes consultar hasta 50 paquetes por remisión.']);
        }

        foreach ($codes as $code) {
            if (strlen($code) > 35 || !preg_match('/^[A-Z0-9][A-Z0-9._\/-]*$/D', $code)) {
                throw ValidationException::withMessages(['codigos' => "El identificador «{$code}» contiene caracteres no permitidos o supera 35 caracteres."]);
            }
        }

        if (!$codes) {
            return [
                'codes' => [], 'packages' => [], 'missing_codes' => [], 'without_declaration_codes' => [],
                'deleted_declaration_codes' => [], 'unconfirmed_codes' => [],
                'duplicate_count' => 0, 'declaration_count' => 0, 'truncated' => false,
            ];
        }

        $result = $this->cds->search($codes);
        $legacyTruncated = (bool) ($result['truncated'] ?? false)
            && !array_key_exists('packages_truncated', $result)
            && !array_key_exists('declarations_truncated', $result);
        $groups = $this->aggregate(
            $codes,
            $result['packages'] ?? [],
            $result['declarations'] ?? [],
            $result['responses'] ?? [],
            (bool) ($result['packages_truncated'] ?? $legacyTruncated),
            (bool) ($result['declarations_truncated'] ?? $legacyTruncated),
        );

        return [
            'codes' => $codes,
            'packages' => $groups['packages'],
            'missing_codes' => $groups['missing_codes'],
            'without_declaration_codes' => $groups['without_declaration_codes'],
            'deleted_declaration_codes' => $groups['deleted_declaration_codes'],
            'unconfirmed_codes' => $groups['unconfirmed_codes'],
            'duplicate_count' => count($tokens) - count($codes),
            'declaration_count' => $groups['declaration_count'],
            'truncated' => (bool) ($result['truncated'] ?? false),
        ];
    }

    /** Find and consolidate every CDS record matching the identifiers in one IPS receptacle. */
    public function indexForPackages(array $packageCodes): array
    {
        $codes = array_values(array_unique(array_filter(array_map(
            fn ($value) => ShipmentCode::normalize((string) $value),
            $packageCodes,
        ))));

        if (count($codes) > 2000) {
            throw ValidationException::withMessages(['marbete' => 'La saca supera el límite visible de 1.000 paquetes. Consulta a la oficina responsable antes de preparar la remisión.']);
        }
        foreach ($codes as $code) {
            if (strlen($code) > 35 || !preg_match('/^[A-Z0-9][A-Z0-9._\/-]*$/D', $code)) {
                throw ValidationException::withMessages(['marbete' => 'Uno de los paquetes de la saca tiene un identificador que CDS no puede consultar automáticamente.']);
            }
        }
        if (!$codes) return ['packages'=>[], 'missing_codes'=>[], 'without_declaration_codes'=>[], 'unconfirmed_codes'=>[], 'declaration_count'=>0, 'truncated'=>false];

        $result = $this->cds->declarationIndex($codes, 1000);
        $groups = $this->aggregate(
            $codes,
            $result['packages'] ?? [],
            $result['declarations'] ?? [],
            [],
            (bool) ($result['packages_truncated'] ?? false),
            (bool) ($result['declarations_truncated'] ?? false),
        );

        return [
            'packages' => $groups['packages'],
            'missing_codes' => $groups['missing_codes'],
            'without_declaration_codes' => $groups['without_declaration_codes'],
            'deleted_declaration_codes' => $groups['deleted_declaration_codes'],
            'unconfirmed_codes' => $groups['unconfirmed_codes'],
            'declaration_count' => $groups['declaration_count'],
            'truncated' => (bool) ($result['truncated'] ?? false),
            'declarations_truncated' => (bool) ($result['declarations_truncated'] ?? false),
        ];
    }

    /**
     * Merge requested aliases that point to the same CDS object, then merge all
     * object rows for each S10. This avoids deciding from the newest row alone.
     */
    private function aggregate(
        array $codes,
        iterable $packageRows,
        iterable $declarationRows,
        iterable $responseRows = [],
        bool $packagesTruncated = false,
        bool $declarationsTruncated = false,
    ): array
    {
        $parent = array_combine($codes, $codes) ?: [];
        $find = function (string $code) use (&$find, &$parent): string {
            if ($parent[$code] !== $code) $parent[$code] = $find($parent[$code]);
            return $parent[$code];
        };
        $union = function (string $left, string $right) use (&$parent, &$find): void {
            $leftRoot = $find($left);
            $rightRoot = $find($right);
            if ($leftRoot !== $rightRoot) $parent[$rightRoot] = $leftRoot;
        };

        $packageRows = collect($packageRows)->unique(fn ($row) => (string) ($row->MAIL_OBJECT_PID ?? ''))->values();
        $declarationsByPid = collect($declarationRows)->groupBy(fn ($row) => (string) ($row['package_id'] ?? ''));
        $responsesByPid = collect($responseRows)->groupBy(fn ($row) => (string) ($row['package_id'] ?? ''));
        $matchedByPid = [];

        foreach ($packageRows as $package) {
            $aliases = $this->aliases($package);
            $matched = $aliases->intersect($codes)->values()->all();
            if (!$matched) continue;
            foreach (array_slice($matched, 1) as $other) $union($matched[0], $other);
            $matchedByPid[(string) ($package->MAIL_OBJECT_PID ?? '')] = $matched;
        }

        $components = [];
        foreach ($codes as $code) $components[$find($code)][] = $code;
        $groups = [];
        $matchedCodes = [];
        $withoutDeclarationCodes = [];
        $deletedDeclarationCodes = [];
        $unconfirmedCodes = [];
        $declarationIds = [];

        foreach ($components as $componentCodes) {
            $componentSet = collect($componentCodes);
            $records = [];
            $allDeclarations = [];
            $allResponses = [];
            $groupMatched = [];

            foreach ($packageRows as $package) {
                $packageId = (string) ($package->MAIL_OBJECT_PID ?? '');
                $matched = $matchedByPid[$packageId] ?? [];
                if (!$matched || !array_intersect($matched, $componentCodes)) continue;

                $packageDeclarations = $declarationsByPid->get($packageId, collect())->values()->all();
                $packageResponses = $responsesByPid->get($packageId, collect())->values()->all();
                $records[] = [
                    'package' => $package,
                    'matched_codes' => $matched,
                    'declarations' => $packageDeclarations,
                    'responses' => $packageResponses,
                ];
                $groupMatched = array_merge($groupMatched, $matched);
                $allDeclarations = array_merge($allDeclarations, $packageDeclarations);
                $allResponses = array_merge($allResponses, $packageResponses);
            }

            $allDeclarations = collect($allDeclarations)->unique(fn ($row) => (string) ($row['id'] ?? $row['package_id'].':'.serialize($row)))->values()->all();
            $allResponses = collect($allResponses)->unique(fn ($row) => (string) ($row['id'] ?? $row['package_id'].':'.serialize($row)))->values()->all();
            $activeDeclarations = collect($allDeclarations)->reject(fn ($row) => $this->isDeleted($row))->values();
            $groupMatched = array_values(array_unique($groupMatched));
            $matchedCodes = array_merge($matchedCodes, $groupMatched);

            if (!$records && $packagesTruncated) $unconfirmedCodes = array_merge($unconfirmedCodes, $componentCodes);
            elseif ($records && !$allDeclarations && $declarationsTruncated) $unconfirmedCodes = array_merge($unconfirmedCodes, $componentCodes);
            elseif ($records && !$allDeclarations) $withoutDeclarationCodes = array_merge($withoutDeclarationCodes, $componentCodes);
            if ($records && !$activeDeclarations->isEmpty() && $activeDeclarations->count() !== count($allDeclarations)) {
                $deletedDeclarationCodes = array_merge($deletedDeclarationCodes, $componentCodes);
            } elseif ($records && $allDeclarations && $activeDeclarations->isEmpty()) {
                $deletedDeclarationCodes = array_merge($deletedDeclarationCodes, $componentCodes);
            }
            foreach ($activeDeclarations as $declaration) $declarationIds[(string) ($declaration['id'] ?? serialize($declaration))] = true;

            if (!$records) continue;

            $groups[] = [
                // Keep these aggregate aliases for existing consumers. `records`
                // preserves the exact object-to-declaration relationship.
                'package' => $records[0]['package'] ?? null,
                'records' => $records,
                'matched_codes' => $groupMatched,
                'declarations' => $allDeclarations,
                'responses' => $allResponses,
                'declaration_count' => count($allDeclarations),
                'active_declaration_count' => $activeDeclarations->count(),
                'deleted_declaration_count' => count($allDeclarations) - $activeDeclarations->count(),
                'has_active_declaration' => $activeDeclarations->isNotEmpty(),
                'status' => !$records ? 'No encontrado en CDS' : ($activeDeclarations->isNotEmpty()
                    ? 'Con declaración CDS'
                    : ($allDeclarations ? 'Solo declaración eliminada' : ($declarationsTruncated ? 'No confirmado: límite de CDS' : 'Registro CDS sin declaración'))),
            ];
        }

        $matchedCodes = array_values(array_unique($matchedCodes));
        return [
            'packages' => $groups,
            'missing_codes' => array_values(array_diff($codes, $matchedCodes, $unconfirmedCodes)),
            'without_declaration_codes' => array_values(array_unique($withoutDeclarationCodes)),
            'deleted_declaration_codes' => array_values(array_unique($deletedDeclarationCodes)),
            'unconfirmed_codes' => array_values(array_unique($unconfirmedCodes)),
            'declaration_count' => count($declarationIds),
        ];
    }

    private function isDeleted(array $declaration): bool
    {
        $stage = mb_strtolower((string) ($declaration['workflow_stage'] ?? $declaration['state'] ?? ''), 'UTF-8');
        return str_contains($stage, 'deleted') || str_contains($stage, 'eliminad');
    }

    private function aliases(object $package)
    {
        return collect([
            $package->MAIL_OBJECT_ID ?? null,
            $package->MAIL_OBJECT_LOCAL_ID ?? null,
            $package->MAIL_OBJECT_LOCAL_ID2 ?? null,
        ])->filter()->map(fn ($value) => ShipmentCode::normalize((string) $value))->filter()->values();
    }
}
