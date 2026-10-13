<?php

namespace App\Services\Postal;

use App\Exceptions\IpsOperationException;
use App\Services\IpsRepository;

/** Resolves missing IPS destination country only from one complete, active CDS declaration. */
final class CustomsDestinationResolver
{
    public function __construct(private readonly CdsRepository $cds) {}

    public function forIpsItem(object $item): string
    {
        $ipsCountry = strtoupper(trim((string) ($item->DEST_COUNTRY_CD ?? '')));
        if ($ipsCountry !== '') return $ipsCountry;
        if (!config('postal.cds_enabled')) {
            throw new IpsOperationException('IPS no indica país de destino y CDS no está habilitado para verificarlo. No se envió el evento.', 503);
        }

        $codes = array_values(array_unique(array_filter([
            strtoupper(trim((string) ($item->MAILITM_FID ?? ''))),
            strtoupper(trim((string) ($item->MAILITM_LOCAL_ID ?? ''))),
        ])));
        try {
            $index = $this->cds->declarationIndex($codes, 1000, true);
        } catch (\Throwable $exception) {
            report($exception);
            throw new IpsOperationException('IPS no indica país de destino y CDS no respondió. No se envió el evento.', 503);
        }
        if (!empty($index['truncated']) || !empty($index['packages_truncated']) || !empty($index['declarations_truncated'])) {
            throw new IpsOperationException('La consulta CDS quedó incompleta y no permite verificar el país de destino.', 409);
        }
        $countries = collect($index['declarations'] ?? [])
            ->reject(function (array $declaration) {
                $stage = mb_strtolower((string) ($declaration['workflow_stage'] ?? $declaration['state'] ?? ''), 'UTF-8');
                return str_contains($stage, 'deleted') || str_contains($stage, 'eliminad');
            })
            ->map(fn (array $declaration) => strtoupper(trim((string) ($declaration['data']['fields']['RCtr'] ?? ''))))
            ->filter(fn (string $country) => preg_match('/^[A-Z]{2}$/D', $country) === 1)
            ->unique()->values();
        if ($countries->count() !== 1) {
            throw new IpsOperationException('No se pudo confirmar un único país de destino desde una declaración CDS activa.', 409);
        }

        return $countries->first();
    }
}
