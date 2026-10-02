<?php

namespace App\Http\Controllers;

use App\Services\Postal\PostalWorkspace;
use App\Services\Postal\PostalActivityReport;
use App\Services\Postal\CustomsRemittanceBuilder;
use App\Services\Postal\ReceptacleSearchService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class PostalIntelligenceController extends Controller
{
    public function index(Request $request, PostalWorkspace $workspace)
    {
        return $this->renderSection($request, $workspace, 'conjunto');
    }

    public function ips(Request $request, PostalWorkspace $workspace)
    {
        return $this->renderSection($request, $workspace, 'ips');
    }

    public function cds(Request $request, PostalWorkspace $workspace)
    {
        return $this->renderSection($request, $workspace, 'cds');
    }

    public function customsDeclarationPrint(Request $request, PostalWorkspace $workspace)
    {
        Gate::authorize('postal.cds');
        $input = $request->validate([
            'codigo' => ['required', 'string', 'max:35'],
            'declaracion' => ['required', 'string', 'max:64'],
        ]);
        $code = strtoupper(trim($input['codigo']));
        $data = $workspace->search($code, Gate::allows('postal.ips'), true);
        $declaration = collect($data['cds']['declarations'] ?? [])->first(
            fn ($row) => (string) ($row['id'] ?? '') === (string) $input['declaracion']
        );
        abort_if(!$declaration, 404, 'No se encontró esa declaración CDS para el código indicado.');

        $cdsPackage = collect($data['cds']['packages'] ?? [])->first(
            fn ($row) => (string) ($row->MAIL_OBJECT_PID ?? '') === (string) ($declaration['package_id'] ?? '')
        );
        $ipsPackage = collect($data['ips']['packageRows'] ?? [])->first(function ($row) use ($code) {
            return strtoupper(trim((string) ($row->MAILITM_FID ?? ''))) === $code
                || strtoupper(trim((string) ($row->MAILITM_LOCAL_ID ?? ''))) === $code;
        });
        // The printed form must follow the CDS object that owns this declaration.
        $mailClass = $cdsPackage->MAIL_CLASS_CD ?? $ipsPackage->MAIL_CLASS_CD ?? null;
        $postalAliases = collect([
            $code,
            $ipsPackage->MAILITM_FID ?? null,
            $ipsPackage->MAILITM_LOCAL_ID ?? null,
        ])->filter()->map(fn ($value) => strtoupper(trim((string) $value)))->values();
        $matchesSelectedPackage = static function ($row) use ($postalAliases): bool {
            $rowAliases = collect([$row->MAILITM_FID ?? null, $row->MAILITM_LOCAL_ID ?? null])
                ->filter()->map(fn ($value) => strtoupper(trim((string) $value)))->values();
            // EDI rows returned by IPS may not carry an S10/local ID in the projection;
            // their query was already scoped to the selected postal identifier.
            return $rowAliases->isEmpty() || $rowAliases->intersect($postalAliases)->isNotEmpty();
        };
        $acceptanceEvent = collect($data['ips']['trackingRows'] ?? [])->filter($matchesSelectedPackage)->first(
            fn ($event) => (string) ($event->EVENT_TYPE_CD ?? '') === '1'
                && ($event->SOURCE_DB ?? 'IPS5Db') === 'IPS5Db'
        );
        $delivery = collect($data['ips']['deliveryRows'] ?? [])->filter($matchesSelectedPackage)->first();
        $declarationEvent = collect($data['cds']['events'] ?? [])
            ->filter(fn ($event) => ($event['kind'] ?? null) === 'declarations'
                && (string) ($event['record_id'] ?? '') === (string) $declaration['id'])
            ->sortByDesc('occurred_at')->first();

        $fields = $declaration['data']['fields'] ?? [];
        $countries = $declaration['countries'] ?? [];
        $present = static fn ($value) => $value !== null && trim((string) $value) !== '';
        $countryName = static fn ($code) => $present($code) ? ($countries[$code] ?? $code) : null;
        $address = static function (array $values) use ($present) {
            return collect($values)->map(fn ($value) => trim((string) ($value ?? '')))
                ->filter($present)->implode(', ') ?: null;
        };
        $postalAddress = static function (array $values) use ($present) {
            return collect($values)->map(fn ($value) => trim((string) ($value ?? '')))
                ->filter($present)->implode(' ') ?: null;
        };
        $money = static function ($value, $currency) use ($present) {
            if (!$present($value)) return null;
            return trim((string) $value.($present($currency) ? ' '.$currency : ''));
        };
        $sender = [
            'name' => $fields['SNm'] ?? null,
            'address' => $postalAddress([$fields['SAdL1'] ?? null, $fields['SAdL2'] ?? null]),
            'postcode' => $fields['SZip'] ?? null,
            'city' => $fields['SCty'] ?? null,
            'state' => $fields['SSta'] ?? null,
            'country_code' => $fields['SCtr'] ?? null,
            'country' => $countryName($fields['SCtr'] ?? null),
            'phone' => $fields['STel'] ?? null,
            'email' => $fields['SEml'] ?? null,
            'customs_reference' => $fields['SIdR'] ?? null,
        ];
        $recipient = [
            'name' => $fields['RNm'] ?? null,
            'address' => $postalAddress([$fields['RAdL1'] ?? null, $fields['RAdL2'] ?? null]),
            'postcode' => $fields['RZip'] ?? null,
            'city' => $fields['RCty'] ?? null,
            'state' => $fields['RSta'] ?? null,
            'country_code' => $fields['RCtr'] ?? null,
            'country' => $countryName($fields['RCtr'] ?? null),
            'phone' => $fields['RTel'] ?? null,
            'email' => $fields['REml'] ?? null,
            'customs_reference' => $fields['RIdR'] ?? null,
        ];
        $pieces = collect($declaration['data']['pieces'] ?? [])->map(fn ($piece) => [
            'description' => $piece['Desc'] ?? $piece['RDesc'] ?? null,
            'quantity' => $piece['No'] ?? null,
            'net_weight' => $piece['NWgt'] ?? null,
            'value' => $money($piece['Amt'] ?? null, $piece['Cur'] ?? null),
            'tariff' => $piece['HS'] ?? $piece['RHS'] ?? null,
            'origin_code' => $piece['OCtr'] ?? null,
            'origin' => $countryName($piece['OCtr'] ?? null),
        ])->all();
        $natureCode = (string) ($fields['NTyp'] ?? '');
        $category = match ($natureCode) {
            '21' => 'Returned goods',
            '31' => 'Regalo',
            '32' => 'Muestra comercial',
            '91' => 'Documentos',
            default => $present($natureCode) ? 'Otro' : null,
        };
        $natureDescription = $fields['NTypDesc'] ?? $declaration['nature'] ?? null;
        $natureSearch = mb_strtolower((string) $natureDescription, 'UTF-8');
        if ($category === 'Otro' && (str_contains($natureSearch, 'venta') || str_contains($natureSearch, 'sale of goods'))) {
            $category = 'Sales of goods';
        }
        $declaredDocuments = collect($declaration['data']['documents'] ?? [])->map(function ($document) {
            $details = collect($document['fields'] ?? [])->map(fn ($value, $key) => $key.': '.$value)->implode(' · ');
            return trim(($document['type'] ?? 'Documento').($details !== '' ? ' · '.$details : ''));
        });
        $officeParts = [
            $acceptanceEvent->OFFICE_FCD ?? null,
            $acceptanceEvent->OFFICE_NM ?? null,
        ];
        $acceptanceOffice = $address($officeParts);
        $formatUtc = static fn ($value) => $present($value)
            ? CarbonImmutable::parse($value, 'UTC')->format('d/m/Y H:i')
            : null;
        $timezone = config('postal.timezone', 'America/La_Paz');
        $formatLocalDate = static fn ($value) => $present($value)
            ? CarbonImmutable::parse($value, 'UTC')->setTimezone($timezone)->format('m/d/Y')
            : null;
        $formatLocalTime = static fn ($value) => $present($value)
            ? CarbonImmutable::parse($value, 'UTC')->setTimezone($timezone)->format('H:i')
            : null;
        // CDS POSTING_DATE is already stored as local postal-office time, unlike
        // IPS EVENT_GMT_DT. Use it when IPS has no acceptance event for the item.
        $formatCdsLocalDate = static fn ($value) => $present($value)
            ? CarbonImmutable::parse($value, $timezone)->format('m/d/Y')
            : null;
        $formatCdsLocalTime = static fn ($value) => $present($value)
            ? CarbonImmutable::parse($value, $timezone)->format('H:i')
            : null;
        $documentGroups = [
            'licenses' => $declaredDocuments->filter(fn ($document) => str_contains(mb_strtolower($document, 'UTF-8'), 'licen'))->values()->all(),
            'certificates' => $declaredDocuments->filter(fn ($document) => str_contains(mb_strtolower($document, 'UTF-8'), 'cert'))->values()->all(),
            'invoices' => $declaredDocuments->filter(fn ($document) => str_contains(mb_strtolower($document, 'UTF-8'), 'invoice') || str_contains(mb_strtolower($document, 'UTF-8'), 'factura'))->values()->all(),
        ];
        $knownDocuments = collect($documentGroups)->flatten()->all();
        $otherDocuments = $declaredDocuments->reject(fn ($document) => in_array($document, $knownDocuments, true))->values()->all();

        return response()->view('postal.cn23-print', [
            'code' => $code,
            'formTitle' => $mailClass === 'E' ? 'CN 23 EMS' : 'CN 23',
            'mailClass' => $mailClass,
            'serviceLabel' => match ($mailClass) {
                'E' => 'EMS',
                'C' => 'Encomienda / paquetería (CP)',
                'U' => 'Correspondencia (LC/AO)',
                default => null,
            },
            'operatorCode' => config('postal.operator_code', 'AGBC'),
            'cdsPackage' => $cdsPackage,
            'ipsPackage' => $ipsPackage,
            'declaration' => $declaration,
            'fields' => $fields,
            'sender' => $sender,
            'recipient' => $recipient,
            'pieces' => $pieces,
            'category' => $category,
            'natureDescription' => $natureDescription,
            'documentGroups' => $documentGroups,
            'otherDocuments' => $otherDocuments,
            'grossWeight' => $fields['GWgt'] ?? null,
            'postalWeight' => $ipsPackage->MAILITM_WEIGHT ?? null,
            'totalNetWeight' => $fields['TotNWgt'] ?? null,
            'declaredQuantity' => $fields['TotCPNo'] ?? null,
            'declaredValue' => $money($fields['TotCPVal'] ?? null, $fields['TotalCPValCur'] ?? null),
            'postalCharges' => $money($fields['Ptg'] ?? null, $fields['PtgCur'] ?? null),
            'insurance' => $money($fields['IV'] ?? null, $fields['IvC'] ?? null),
            'comments' => $fields['Obs'] ?? null,
            'declarationNumber' => $declaration['declaration_number'] ?? null,
            'acceptanceOffice' => $acceptanceOffice,
            'acceptanceAt' => $formatUtc($acceptanceEvent->EVENT_GMT_DT ?? null),
            'acceptanceDate' => $formatLocalDate($acceptanceEvent->EVENT_GMT_DT ?? null)
                ?: $formatCdsLocalDate($cdsPackage->POSTING_DATE ?? null),
            'acceptanceTime' => $formatLocalTime($acceptanceEvent->EVENT_GMT_DT ?? null)
                ?: $formatCdsLocalTime($cdsPackage->POSTING_DATE ?? null),
            'acceptanceEvent' => $acceptanceEvent,
            'delivery' => $delivery,
            'deliveryAt' => $formatUtc($delivery->EVENT_GMT_DT ?? null),
            'deliveryDate' => $formatLocalDate($delivery->EVENT_GMT_DT ?? null),
            'deliveryTime' => $formatLocalTime($delivery->EVENT_GMT_DT ?? null),
            'declarationEvent' => $declarationEvent,
            'declarationAt' => $formatUtc($declarationEvent['occurred_at'] ?? null),
            'declarationDate' => $formatLocalDate($declarationEvent['occurred_at'] ?? null),
            'declarationTime' => $formatLocalTime($declarationEvent['occurred_at'] ?? null),
        ])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function customsRemittance(Request $request, CustomsRemittanceBuilder $builder, ReceptacleSearchService $receptacles)
    {
        return response()->view('postal.customs-remittance', $this->customsRemittanceData($request, $builder, $receptacles))
            ->header('Cache-Control', 'private, no-store');
    }

    public function customsRemittancePrint(Request $request, CustomsRemittanceBuilder $builder, ReceptacleSearchService $receptacles)
    {
        $data = $this->customsRemittanceData($request, $builder, $receptacles);
        abort_unless($data['manifest'] && $data['manifest']['codes'], 422, 'Ingresa códigos y consulta CDS antes de preparar la impresión.');

        return response()->view('postal.customs-remittance-print', $data)->header('Cache-Control', 'private, no-store');
    }

    public function customsRemittanceCsv(Request $request, CustomsRemittanceBuilder $builder, ReceptacleSearchService $receptacles)
    {
        $data = $this->customsRemittanceData($request, $builder, $receptacles);
        abort_unless($data['manifest'] && $data['manifest']['codes'], 422, 'Ingresa códigos y consulta CDS antes de descargar el listado.');
        $manifest = $data['manifest'];
        $sourceBag = $data['marbete'];
        $filename = 'remision-aduana-'.now(config('postal.timezone'))->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($manifest, $sourceBag) {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, [
                'Marbete / saca de origen', 'Código consultado', 'Código postal CDS', 'Identificador local', 'ID paquete CDS', 'ID declaración',
                'Estado CDS', 'Estado declaración', 'Respuesta Aduana', 'Remitente', 'País de origen',
                'Destinatario', 'País de destino', 'Naturaleza', 'Peso bruto declarado', 'Descripción artículo',
                'Unidades', 'Valor artículo', 'Moneda', 'Peso neto', 'País de fabricación', 'Documentos declarados',
            ]);

            $writeRow = static function (array $row) use ($stream, $sourceBag): void {
                array_unshift($row, $sourceBag);
                $safe = array_map(static function ($cell) {
                    $value = (string) ($cell ?? '');
                    return preg_match('/^[\s]*[=+@\-\t\r]/', $value) ? "'".$value : $value;
                }, $row);
                fputcsv($stream, $safe);
            };

            foreach ($manifest['packages'] as $group) {
                foreach ($group['records'] ?? [[
                    'package' => $group['package'], 'declarations' => $group['declarations'], 'responses' => $group['responses'],
                ]] as $record) {
                    $package = $record['package'];
                    $packageCode = $package->MAIL_OBJECT_ID ?? '';
                    $localCode = $package->MAIL_OBJECT_LOCAL_ID ?? $package->MAIL_OBJECT_LOCAL_ID2 ?? '';
                    $matchedCode = implode(' / ', $group['matched_codes']);
                    $responseText = collect($record['responses'])->map(fn ($response) => implode(': ', array_filter([
                        $response['decision'] ?? null,
                        $response['state'] ?? null,
                    ])))->filter()->implode(' | ');

                    if (!$record['declarations']) {
                        $writeRow([$matchedCode, $packageCode, $localCode, $package->MAIL_OBJECT_PID ?? '', '',
                            $package->MAIL_STATE_NM ?? '', 'Registro CDS sin declaración', $responseText, '', '', '', '', '', '', '', '', '', '', '', '', '']);
                        continue;
                    }

                    foreach ($record['declarations'] as $declaration) {
                        $fields = $declaration['data']['fields'] ?? [];
                        $pieces = $declaration['data']['pieces'] ?? [];
                        $documents = collect($declaration['data']['documents'] ?? [])->map(function ($document) {
                            $values = collect($document['fields'] ?? [])->map(fn ($value, $key) => $key.'='.$value)->implode(' ');
                            return trim(($document['type'] ?? 'Documento').($values ? ' '.$values : ''));
                        })->implode(' | ');
                        if (!$pieces) $pieces = [[]];

                        foreach ($pieces as $piece) {
                            $writeRow([
                                $matchedCode, $packageCode, $localCode, $package->MAIL_OBJECT_PID ?? '', $declaration['id'] ?? '',
                                $package->MAIL_STATE_NM ?? '', $declaration['state'] ?? 'Estado no informado', $responseText,
                                $fields['SNm'] ?? '', $declaration['countries'][$fields['SCtr'] ?? ''] ?? $fields['SCtr'] ?? '',
                                $fields['RNm'] ?? '', $declaration['countries'][$fields['RCtr'] ?? ''] ?? $fields['RCtr'] ?? '',
                                $declaration['nature'] ?? $fields['NTypDesc'] ?? $fields['NTyp'] ?? '', $fields['GWgt'] ?? '',
                                $piece['Desc'] ?? '', $piece['No'] ?? '', $piece['Amt'] ?? '', $piece['Cur'] ?? '', $piece['NWgt'] ?? '',
                                $declaration['countries'][$piece['OCtr'] ?? ''] ?? $piece['OCtr'] ?? '', $documents,
                            ]);
                        }
                    }
                }
            }

            foreach ($manifest['missing_codes'] as $code) {
                $writeRow([$code, '', '', '', '', '', 'No encontrado en CDS', '', '', '', '', '', '', '', '', '', '', '', '', '', '']);
            }
            foreach ($manifest['unconfirmed_codes'] ?? [] as $code) {
                $writeRow([$code, '', '', '', '', '', 'No confirmado: límite de resultados CDS', '', '', '', '', '', '', '', '', '', '', '', '', '', '']);
            }
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function customsRemittanceData(Request $request, CustomsRemittanceBuilder $builder, ReceptacleSearchService $receptacles): array
    {
        $validated = $request->validate([
            'codigos' => ['nullable', 'string', 'max:3000'],
            'marbete' => ['nullable', 'string', 'max:80'],
            'seleccionados' => ['sometimes', 'array', 'max:50'],
            'seleccionados.*' => ['required', 'string', 'max:35'],
        ]);
        $selectedCodes = $validated['seleccionados'] ?? [];
        $input = $selectedCodes
            ? implode("\n", $selectedCodes)
            : trim((string) ($validated['codigos'] ?? ''));
        $manifest = null;
        $error = null;
        $bagIdentifier = trim((string) ($validated['marbete'] ?? ''));
        $bagResult = null;
        $bagRows = [];
        $bagError = null;
        $bagIndexTruncated = false;

        if ($input !== '') {
            if (!config('postal.cds_enabled')) {
                $error = 'La conexión CDS está deshabilitada. Contacta al administrador para habilitar la consulta.';
            } else {
                try {
                    $manifest = $builder->build($input);
                    $manifest['generated_at'] = now(config('postal.timezone'));
                } catch (ValidationException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    Log::warning('Customs remittance unavailable', ['exception' => get_class($exception)]);
                    $error = 'No se pudo consultar CDS. No se generó el listado; revisa la conexión e intenta de nuevo.';
                }
            }
        }

        if ($bagIdentifier !== '') {
            if (!Gate::allows('postal.ips')) {
                $bagError = 'Para consultar el contenido de un marbete necesitas permiso de lectura IPS. Puedes usar la búsqueda manual por códigos.';
            } else {
                try {
                    $bagResult = $receptacles->search($bagIdentifier);
                    if (count($bagResult['receptacles'] ?? []) === 1) {
                        $items = collect($bagResult['items'] ?? []);
                        $identifiers = $items->flatMap(fn ($item) => array_filter([
                            $item->MAILITM_FID ?? null,
                            $item->MAILITM_LOCAL_ID ?? null,
                        ]))->unique()->values()->all();

                        if ($identifiers && config('postal.cds_enabled')) {
                            try {
                                $index = $builder->indexForPackages($identifiers);
                                $bagIndexTruncated = $index['truncated'];
                                foreach ($items as $item) {
                                    $itemCodes = array_values(array_filter([
                                        strtoupper(trim((string) ($item->MAILITM_FID ?? ''))),
                                        strtoupper(trim((string) ($item->MAILITM_LOCAL_ID ?? ''))),
                                    ]));
                                    // An S10 can have several CDS objects. The builder returns one
                                    // consolidated group with every matching object and declaration.
                                    $group = collect($index['packages'])->first(fn ($candidate) => array_intersect($itemCodes, $candidate['matched_codes']) !== []);
                                    $hasDeclaration = $group && ($group['has_active_declaration'] ?? count($group['declarations']) > 0);
                                    $package = $group['package'] ?? null;
                                    $selectCode = $package?->MAIL_OBJECT_ID ?: $package?->MAIL_OBJECT_LOCAL_ID ?: $package?->MAIL_OBJECT_LOCAL_ID2;
                                    $status = $hasDeclaration
                                        ? 'Con declaración CDS'
                                        : ($group
                                            ? (($index['declarations_truncated'] ?? false) ? 'No confirmado por límite de consulta' : (($group['deleted_declaration_count'] ?? 0) > 0 ? 'Solo declaración eliminada' : 'Registro CDS sin declaración'))
                                            : ($bagIndexTruncated ? 'No confirmado por límite de consulta' : 'No encontrado en CDS'));
                                    $bagRows[] = [
                                        'item' => $item,
                                        'code' => $itemCodes[0] ?? '',
                                        'group' => $group,
                                        'has_declaration' => (bool) $hasDeclaration,
                                        'has_any_declaration' => (bool) ($group && count($group['declarations']) > 0),
                                        'cds_match' => $hasDeclaration ? 'declared' : ($group
                                            ? (($group['deleted_declaration_count'] ?? 0) > 0 ? 'deleted' : 'record_only')
                                            : (($bagIndexTruncated || ($index['declarations_truncated'] ?? false)) ? 'unknown' : 'missing')),
                                        'declaration_count' => count($group['declarations'] ?? []),
                                        'declaration_states' => collect($group['declarations'] ?? [])->map(function ($declaration) {
                                            $stage = $declaration['workflow_stage'] ?? $declaration['state'] ?? 'Estado no informado';
                                            return $stage.(!empty($declaration['content_summary']) ? ' · '.$declaration['content_summary'] : '');
                                        })->unique()->implode('; '),
                                        'select_code' => $selectCode,
                                        'status' => $status,
                                    ];
                                }
                            } catch (ValidationException $exception) {
                                throw $exception;
                            } catch (Throwable $exception) {
                                Log::warning('Customs receptacle declaration index unavailable', ['exception' => get_class($exception)]);
                                $bagError = 'Se encontró el marbete, pero no se pudo cruzar con CDS. No se marcaron paquetes como disponibles para remisión.';
                            }
                        } elseif (!config('postal.cds_enabled')) {
                            $bagError = 'Se encontró el marbete, pero la conexión CDS no está habilitada para comprobar sus declaraciones.';
                        }
                    }
                } catch (ValidationException $exception) {
                    $bagError = collect($exception->errors())->flatten()->first() ?? 'La saca contiene identificadores que no se pueden consultar en CDS.';
                } catch (Throwable $exception) {
                    Log::warning('Customs receptacle lookup unavailable', ['exception' => get_class($exception)]);
                    $bagError = 'No se pudo consultar el marbete en IPS. Revisa la conexión e intenta de nuevo.';
                }
            }
        }

        if ($bagResult && count($bagResult['receptacles'] ?? []) === 1 && !$bagRows) {
            foreach ($bagResult['items'] ?? [] as $item) {
                $bagRows[] = [
                    'item' => $item,
                    'code' => strtoupper(trim((string) ($item->MAILITM_FID ?: $item->MAILITM_LOCAL_ID ?: ''))),
                    'group' => null,
                    'has_declaration' => false,
                    'has_any_declaration' => false,
                    'cds_match' => 'unknown',
                    'declaration_count' => 0,
                    'declaration_states' => '',
                    'select_code' => null,
                    'status' => $bagError ? 'CDS no consultable' : 'Sin identificador para CDS',
                ];
            }
        }

        return [
            'input' => $input,
            'selectedCodes' => $selectedCodes,
            'manifest' => $manifest,
            'error' => $error,
            'marbete' => $bagIdentifier,
            'bagResult' => $bagResult,
            'bag' => count($bagResult['receptacles'] ?? []) === 1 ? $bagResult['receptacles'][0] : null,
            'bagRows' => $bagRows,
            'bagDeclarationCount' => count(array_filter($bagRows, fn ($row) => $row['has_declaration'])),
            'bagError' => $bagError,
            'bagIndexTruncated' => $bagIndexTruncated,
            'canUseBag' => Gate::allows('postal.ips'),
        ];
    }

    private function renderSection(Request $request, PostalWorkspace $workspace, string $section)
    {
        $request->validate(['codigo' => ['nullable', 'string', 'max:35']]);
        $allowIps = $section !== 'cds' && Gate::allows('postal.ips');
        $allowCds = $section !== 'ips' && Gate::allows('postal.cds');
        $data = $workspace->search((string) $request->input('codigo', ''), $allowIps, $allowCds);
        $data['section'] = $section;
        $data['showIps'] = $allowIps;
        $data['showCds'] = $allowCds;

        // Keep view data explicit. In this project the Blade compiler can leave
        // @php blocks as literal text, which means their variables never exist.
        $sectionDetails = [
            'ips' => [
                'route' => 'postal.ips',
                'target' => 'paquetes IPS',
                'title' => 'Paquetes y movimientos IPS',
                'description' => 'Busca envíos, movimientos, operadores y despachos registrados en IPS.',
            ],
            'cds' => [
                'route' => 'postal.cds',
                'target' => 'Aduana CDS',
                'title' => 'Declaraciones y trámites CDS',
                'description' => 'Consulta declaraciones, contenido, decisiones y usuarios que registraron trámites en CDS.',
            ],
            'conjunto' => [
                'route' => 'postal.combined',
                'target' => 'IPS y CDS',
                'title' => 'Consulta IPS + CDS',
                'description' => 'Busca una vez y compara el recorrido postal de IPS con la información aduanera de CDS.',
            ],
        ][$section];

        $data['searchRoute'] = route($sectionDetails['route']);
        $data['searchTarget'] = $sectionDetails['target'];
        $data['sectionTitle'] = $sectionDetails['title'];
        $data['sectionDescription'] = $sectionDetails['description'];
        $data['sourceLabels'] = [
            'idle' => 'Listo para consultar',
            'ok' => 'Datos encontrados',
            'empty' => 'Sin registros para este código',
            'unavailable' => 'No disponible. Vuelve a consultar.',
            'disabled' => 'Conexión pendiente de configurar',
            'forbidden' => 'Sin permiso de consulta',
            'not_queried' => 'No se consulta en esta vista',
        ];
        if ($section === 'ips') {
            $data['sources']['CDS'] = 'not_queried';
        } elseif ($section === 'cds') {
            $data['sources']['IPS'] = 'not_queried';
        }

        $data['ipsPackage'] = collect($data['ips']['packageRows'] ?? [])->first();
        $data['cdsPackageGroups'] = collect($data['cds']['packages'] ?? [])->map(function ($package) use ($data) {
            $packageId = (string) ($package->MAIL_OBJECT_PID ?? '');
            return [
                'package' => $package,
                'declarations' => collect($data['cds']['declarations'] ?? [])->where('package_id', $packageId)->values(),
                'responses' => collect($data['cds']['responses'] ?? [])->where('package_id', $packageId)->values(),
            ];
        })->values();
        $cdsObjectIds = $data['cdsPackageGroups']->pluck('package.MAIL_OBJECT_PID')->filter(fn ($id) => $id !== null)->map(fn ($id) => (string) $id);
        // Keep documents visible if the source returns them without a matching
        // object in the result. Never attach them to an unrelated CDS object.
        $data['unassignedDeclarations'] = collect($data['cds']['declarations'] ?? [])->whereNotIn('package_id', $cdsObjectIds)->values();
        $data['unassignedResponses'] = collect($data['cds']['responses'] ?? [])->whereNotIn('package_id', $cdsObjectIds)->values();
        // Do not make the newest CDS object look like the only one when an S10
        // has duplicate/inbound/outbound records. The view below lists them all.
        $data['cdsPackage'] = $data['cdsPackageGroups']->count() === 1
            ? $data['cdsPackageGroups']->first()['package']
            : null;
        $data['found'] = in_array('ok', $data['sources'], true);
        $data['events'] = collect($data['ips']['trackingRows'] ?? []);
        $data['declarations'] = $data['cds']['declarations'] ?? [];
        $data['responses'] = $data['cds']['responses'] ?? [];
        $data['deliveryRows'] = collect($data['ips']['deliveryRows'] ?? []);
        $data['ediRows'] = collect($data['ips']['ediRows'] ?? []);
        $data['customsRows'] = collect($data['ips']['customsRows'] ?? []);
        $data['contentPieceRows'] = collect($data['ips']['contentPieceRows'] ?? []);
        $data['operationsMetrics'] = [
            'movement_count' => $data['events']->count(),
            'delivery_count' => $data['deliveryRows']->count(),
            'international_count' => $data['ediRows']->count(),
            'bag_count' => collect($data['ips']['logisticRows'] ?? [])->pluck('RECPTCL_PID')->filter()->unique()->count(),
            'manifest_count' => collect($data['ips']['manifestRows'] ?? [])->count(),
            'content_count' => $data['contentPieceRows']->count() + collect($data['declarations'])->sum(fn ($declaration) => count($declaration['data']['pieces'] ?? [])),
        ];
        $latestEvent = $data['events']->first();
        $ageDays = null;
        if ($latestEvent && !empty($latestEvent->EVENT_GMT_DT)) {
            try {
                $eventTimestamp = CarbonImmutable::parse($latestEvent->EVENT_GMT_DT, 'UTC')->getTimestamp();
                $ageDays = max(0, (int) floor((CarbonImmutable::now('UTC')->getTimestamp() - $eventTimestamp) / 86400));
            } catch (Throwable) {
                $ageDays = null;
            }
        }
        $staleAfterDays = max(1, (int) config('postal.stale_after_days', 7));
        $data['operationalStatus'] = [
            'latest_event' => $latestEvent,
            'age_days' => $ageDays,
            'stale_after_days' => $staleAfterDays,
            'stale' => $ageDays !== null && $ageDays >= $staleAfterDays,
            'has_non_delivery_reason' => $data['deliveryRows']->contains(fn ($row) => !empty($row->NON_DELIVERY_REASON_CD)),
        ];

        return response()->view('postal.index', $data)->header('Cache-Control', 'private, no-store');
    }

    public function activityReport(Request $request, PostalActivityReport $report)
    {
        $filters = $this->activityFilters($request);
        $result = null;
        $error = null;
        try {
            $result = $report->search($filters['from_utc'], $filters['until_utc'], $filters['query'], 1000);
        } catch (Throwable $exception) {
            Log::warning('Postal activity report unavailable', ['exception' => get_class($exception)]);
            $error = 'No se pudo consultar la actividad de IPS. Intenta de nuevo o contacta al administrador.';
        }

        return response()->view('postal.activity-report', [
            'filters' => $filters,
            'result' => $result,
            'error' => $error,
            'exportUrl' => route('postal.operations.csv', $request->query()),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function activityReportCsv(Request $request, PostalActivityReport $report)
    {
        $filters = $this->activityFilters($request);
        try {
            $result = $report->search($filters['from_utc'], $filters['until_utc'], $filters['query'], 5000);
        } catch (Throwable $exception) {
            Log::warning('Postal activity CSV unavailable', ['exception' => get_class($exception)]);
            abort(503, 'No se pudo preparar el reporte de actividad. Intenta nuevamente.');
        }

        $filename = 'actividad-ips-'.$filters['from']->format('Ymd').'-'.$filters['to']->format('Ymd').'.csv';
        return response()->streamDownload(function () use ($result) {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Código del paquete','ID local','Fecha y hora del evento (GMT)','Movimiento registrado','Estado actual IPS','Oficina del evento','Siguiente oficina','Registrado por','Clase postal','Origen','Destino','Peso (kg)','Saca / marbete','Motivo de retención (código)','Condición registrada','Lugar de intento de entrega']);
            foreach ($result['rows'] as $row) {
                $cells = [
                    $row->MAILITM_FID, $row->MAILITM_LOCAL_ID, $row->EVENT_GMT_DT, $row->EVENT_NAME ?: 'Movimiento sin descripción',
                    $row->POSTAL_STATUS_NM, trim(($row->OFFICE_FCD ?? '').' '.($row->OFFICE_NM ?? '')),
                    trim(($row->NEXT_OFFICE_FCD ?? '').' '.($row->NEXT_OFFICE_NM ?? '')),
                    $row->USER_NM ?: $row->USER_FID, $row->MAIL_CLASS_NM ?: $row->MAIL_CLASS_CD,
                    $row->ORIGIN_COUNTRY, $row->DESTINATION_COUNTRY, $row->MAILITM_WEIGHT, $row->RECPTCL_FID,
                    $row->RETENTION_REASON_CD, $row->ITEM_CONDITION_NM ?: $row->CONDITION_CD, $row->ATTEMPTED_DELIVERY_LOCATION,
                ];
                fputcsv($stream, array_map([$this, 'safeCsvCell'], $cells));
            }
            if ($result['truncated']) {
                fputcsv($stream, ['AVISO: el archivo llegó al límite de 5.000 paquetes. Reduce las fechas o aplica más filtros para descargar el resto.']);
            }
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function packageCsv(Request $request, PostalWorkspace $workspace)
    {
        $request->validate(['codigo' => ['required', 'string', 'max:35']]);
        $code = $request->string('codigo')->toString();
        $data = $workspace->search($code, Gate::allows('postal.ips'), Gate::allows('postal.cds'));
        abort_unless(in_array('ok', $data['sources'], true), 404, 'No hay datos disponibles para este código.');

        $rows = [];
        $append = static function (array $record) use (&$rows): void {
            $rows[] = array_pad($record, 16, null);
        };
        $codeFound = $data['code'] ?: $code;
        foreach ($data['ips']['packageRows'] ?? [] as $package) {
            $append(['Ficha del paquete','IPS',$package->MAILITM_FID,$package->EVT_GMT_DT,$package->POSTAL_STATUS_NM,
                $package->EVT_TYPE_NM_ES,trim(($package->EVT_OFFICE_FCD ?? '').' '.($package->EVT_OFFICE_NM ?? '')),null,null,
                $package->MAIL_CLASS_NM ?: $package->MAIL_CLASS_CD,$package->ORIG_COUNTRY_NM,$package->DEST_COUNTRY_NM,
                $package->MAILITM_WEIGHT,$package->MAILITM_VALUE,$package->CURRENCY_NM,$package->DUTIES_AMOUNT]);
        }
        foreach ($data['ips']['trackingRows'] ?? [] as $event) {
            $append(['Movimiento','IPS',$event->MAILITM_FID ?: $event->MAILITM_LOCAL_ID,$event->EVENT_GMT_DT,$event->EVENT_TYPE_CD,
                $event->EVENT_TYPE_NM_ES,trim(($event->OFFICE_FCD ?? '').' '.($event->OFFICE_NM ?? '')),
                trim(($event->NEXT_OFFICE_FCD ?? '').' '.($event->NEXT_OFFICE_NM ?? '')),$event->USER_NM ?: $event->USER_FID,
                null,null,null,null,null,null,trim(implode(' · ',array_filter([$event->CONDITION_TXT ?? null,
                    !empty($event->RETENTION_REASON_CD) ? 'Retención '.$event->RETENTION_REASON_CD : null,
                    $event->ATTEMPTED_DELIVERY_LOCATION ?? null])))]);
        }
        foreach ($data['ips']['deliveryRows'] ?? [] as $delivery) {
            $append(['Intento o constancia de entrega','IPS',$codeFound,$delivery->EVENT_GMT_DT,$delivery->EVENT_TYPE_CD,
                $delivery->EVENT_TYPE_NM_ES,trim(($delivery->DELIV_LOCATION ?? '').' '.($delivery->DELIV_POSTCODE ?? '')),
                null,$delivery->SIGNATORY_NM,null,null,null,null,null,null,
                trim(implode(' · ',array_filter([!empty($delivery->NON_DELIVERY_REASON_CD) ? 'Motivo no entrega '.$delivery->NON_DELIVERY_REASON_CD : null,
                    !empty($delivery->NON_DELIVERY_MEASURE_CD) ? 'Acción '.$delivery->NON_DELIVERY_MEASURE_CD : null])))]);
        }
        foreach ($data['ips']['ediRows'] ?? [] as $edi) {
            $append(['Mensaje internacional EDI','IPS',$edi->MAILITM_FID ?: $codeFound,$edi->CAPTURE_GMT_DT ?: $edi->EVENT_LOCAL_DT,
                $edi->EVENT_TYPE_CD,$edi->EVENT_TYPE_NM_ES,$edi->LOCATION_ID,$edi->NEXT_POINT_ID ?? null,$edi->SENDER_ID,
                null,null,null,null,$edi->DESPATCH_NUMBER,null,null]);
        }
        foreach ($data['ips']['logisticRows'] ?? [] as $logistic) {
            $append(['Saca y despacho','IPS',$codeFound,$logistic->DESPTCH_DEPARTURE_DT,null,'Vínculo logístico',
                $logistic->ORIG_OFFICE_FCD,$logistic->DEST_OFFICE_FCD,null,null,null,null,$logistic->RECPTCL_WEIGHT,
                $logistic->RECPTCL_FID,$logistic->DESPTCH_FID,$logistic->DESPTCH_FID]);
        }
        foreach ($data['ips']['manifestRows'] ?? [] as $manifest) {
            $append(['Manifiesto / formulario','IPS',$codeFound,$manifest->CREATION_LCL_DT,$manifest->MANIF_TYPE_ID,
                $manifest->FORM_NM,$manifest->OFFICE_FCD ?: $manifest->OFFICE_NM,null,$manifest->USER_NM ?: $manifest->USER_FID,
                null,null,null,null,$manifest->MANIFEST_LIST_ID,null,$manifest->OBSERVATION]);
        }
        foreach ($data['ips']['customsRows'] ?? [] as $customs) {
            $append(['Ficha aduanera IPS','IPS',$codeFound,null,null,'Datos aduaneros declarados',null,null,null,null,null,null,
                $customs->DECLARED_GROSS_WEIGHT,null,null,
                trim(implode(' · ',array_filter([$customs->SENDER_CUSTOMS_REFERENCE_NO,$customs->RECIPIENT_CUSTOMS_REFERENCE_NO])))]);
        }
        foreach ($data['ips']['contentPieceRows'] ?? [] as $piece) {
            $append(['Artículo declarado IPS','IPS',$codeFound,null,null,$piece->DESCRIPTION ?: $piece->IDENTIFIER,
                $piece->ORIGIN_LOCATION,null,null,$piece->TARIFF_HEADING,null,null,$piece->NET_WEIGHT,
                $piece->NUMBER_OF_UNITS,$piece->DECLARED_VALUE_CURRENCY_CD,$piece->DECLARED_VALUE]);
        }
        foreach ($data['cds']['packages'] ?? [] as $package) {
            $append(['Registro CDS','CDS',$package->MAIL_OBJECT_ID,$package->POSTING_DATE,$package->MAIL_STATE_NM,
                $package->MAIL_OBJECT_TYPE_CD,null,null,null,null,null,null,null,$package->MAIL_OBJECT_LOCAL_ID,null,null]);
        }
        foreach ($data['cds']['declarations'] ?? [] as $declaration) {
            $fields = $declaration['data']['fields'] ?? [];
            $append(['Declaración aduanera','CDS',$fields['MailNo'] ?? $codeFound,null,$declaration['state'] ?? null,
                $declaration['nature'] ?? $fields['NTypDesc'] ?? $fields['NTyp'] ?? 'Declaración registrada',
                trim(($fields['SCtr'] ?? '').' → '.($fields['RCtr'] ?? '')),null,null,null,
                $fields['SCtr'] ?? null,$fields['RCtr'] ?? null,$fields['GWgt'] ?? null,$fields['TotCPVal'] ?? null,
                $fields['TotalCPValCur'] ?? null,$declaration['id']]);
            foreach ($declaration['data']['pieces'] ?? [] as $piece) {
                $append(['Artículo declarado CDS','CDS',$fields['MailNo'] ?? $codeFound,null,$declaration['state'] ?? null,
                    $piece['Desc'] ?? 'Artículo sin descripción',$piece['OCtr'] ?? null,null,null,null,$piece['OCtr'] ?? null,
                    null,$piece['NWgt'] ?? null,$piece['No'] ?? null,$piece['Cur'] ?? null,$piece['Amt'] ?? null]);
            }
        }
        foreach ($data['cds']['responses'] ?? [] as $response) {
            $fields = $response['data']['fields'] ?? [];
            $append(['Respuesta aduanera','CDS',$codeFound,null,$response['state'] ?? null,
                trim(($response['decision'] ?? '').' '.($fields['DecisReasNm'] ?? '')) ?: 'Respuesta registrada',null,null,null,
                null,null,null,null,$response['id'] ?? null,null,$response['package_id'] ?? null]);
        }

        $safeCode = preg_replace('/[^A-Z0-9_-]/', '_', strtoupper($codeFound));
        return response()->streamDownload(function () use ($rows) {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Tipo de registro','Fuente','Código del paquete','Fecha del registro','Estado / código','Movimiento o dato','Oficina / ubicación','Siguiente oficina','Responsable registrado','Clase postal','País de origen','País de destino','Peso','Cantidad / referencia','Moneda / número','Valor / observación']);
            foreach ($rows as $row) fputcsv($stream, array_map([$this, 'safeCsvCell'], $row));
            fclose($stream);
        }, "expediente-postal-{$safeCode}.csv", ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function activityFilters(Request $request): array
    {
        $validated = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
            'oficina' => ['nullable', 'string', 'max:20'],
            'evento' => ['nullable', 'string', 'max:15'],
            'buscar' => ['nullable', 'string', 'max:60'],
        ]);
        $timezone = config('postal.timezone', 'America/La_Paz');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $from = isset($validated['desde'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['desde'], $timezone)
            : (isset($validated['hasta']) ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['hasta'], $timezone)->subDays(6) : $today->subDays(6));
        $to = isset($validated['hasta']) ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['hasta'], $timezone) : $today;
        if ($to->lessThan($from)) {
            throw ValidationException::withMessages(['hasta' => 'La fecha final debe ser igual o posterior a la fecha inicial.']);
        }
        $maxDays = max(1, (int) config('postal.report_max_days', 31));
        if ($from->diffInDays($to) >= $maxDays) {
            throw ValidationException::withMessages(['hasta' => "El periodo máximo de consulta es de {$maxDays} días."]);
        }

        return [
            'from' => $from,
            'to' => $to,
            'from_utc' => $from->startOfDay()->utc(),
            'until_utc' => $to->addDay()->startOfDay()->utc(),
            'query' => ['office' => $validated['oficina'] ?? null, 'event' => $validated['evento'] ?? null, 'search' => $validated['buscar'] ?? null],
            'office' => $validated['oficina'] ?? '',
            'event' => $validated['evento'] ?? '',
            'search' => $validated['buscar'] ?? '',
        ];
    }

    private function safeCsvCell(mixed $cell): string
    {
        $value = (string) ($cell ?? '');
        return preg_match('/^[\s]*[=+@\-\t\r]/', $value) ? "'".$value : $value;
    }

    public function dispatches(Request $request, ReceptacleSearchService $service)
    {
        $validated = $request->validate([
            'buscar' => ['nullable', 'string', 'max:80'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'por_pagina' => ['nullable', 'integer', 'in:25,50,100'],
        ]);
        $filters = [
            'buscar' => trim((string) ($validated['buscar'] ?? '')),
            'desde' => $validated['desde'] ?? null,
            'hasta' => $validated['hasta'] ?? null,
            'por_pagina' => (int) ($validated['por_pagina'] ?? 25),
        ];
        $dispatches = null;
        $error = null;

        try {
            $dispatches = $service->listDispatches($filters);
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('IPS dispatch register unavailable', ['exception' => get_class($exception)]);
            $error = 'No se pudo consultar el registro de despachos IPS en este momento. Vuelve a intentarlo.';
        }

        return response()->view('postal.dispatches', compact('dispatches', 'filters', 'error'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function receptacles(Request $request, ReceptacleSearchService $receptacles)
    {
        $request->validate(['marbete'=>['nullable','string','max:80']]);
        $result = null;
        $error = null;
        if ($request->filled('marbete')) {
            try {
                $result = $receptacles->search($request->string('marbete')->toString());
            } catch (\Throwable $exception) {
                \Illuminate\Support\Facades\Log::warning('IPS receptacle lookup unavailable',['exception'=>get_class($exception)]);
                $error = 'No se pudo consultar IPS en este momento. Vuelve a intentarlo.';
            }
        }
        $reconciliation = null;
        if ($result && ($result['search_type'] ?? null) !== 's8' && count($result['receptacles']) === 1) {
            $bag = $result['receptacles'][0];
            $items = collect($result['items']);
            $knownWeightItems = $items->filter(fn ($item) => is_numeric($item->MAILITM_WEIGHT ?? null));
            $declaredCount = $bag->RECPTCL_MAILITMS_NO !== null ? (int) $bag->RECPTCL_MAILITMS_NO : null;
            $linkedCount = $items->count();
            $reconciliation = [
                'declared_count' => $declaredCount,
                'linked_count' => $linkedCount,
                'count_matches' => $declaredCount === null || $result['truncated'] ? null : $declaredCount === $linkedCount,
                'item_weight_total' => $knownWeightItems->sum(fn ($item) => (float) $item->MAILITM_WEIGHT),
                'items_without_weight' => $items->count() - $knownWeightItems->count(),
                'bag_weight' => is_numeric($bag->RECPTCL_WEIGHT ?? null) ? (float) $bag->RECPTCL_WEIGHT : null,
                'can_compare' => !$result['truncated'],
            ];
        }
        return response()->view('postal.receptacles',['identifier'=>(string)$request->input('marbete',''),'result'=>$result,'error'=>$error,'reconciliation'=>$reconciliation])
            ->header('Cache-Control','private, no-store');
    }

    public function receptacleDocument(Request $request, ReceptacleSearchService $service, string $type)
    {
        abort_unless(in_array($type,['etiqueta','csv'],true),404);
        $request->validate(['marbete'=>['required','string','max:80']]);
        $result=$service->search($request->string('marbete')->toString());
        abort_if(($result['search_type'] ?? null) === 's8' || count($result['receptacles']) !== 1,404,'Busca un marbete único antes de crear el documento.');
        if($type==='etiqueta') {
            return response()->view('postal.receptacle-label',['result'=>$result,'bag'=>$result['receptacles'][0]])
                ->header('Cache-Control','private, no-store');
        }
        $bag=$result['receptacles'][0];
        $safeCode=preg_replace('/[^A-Z0-9_-]/','_',strtoupper((string)($bag->RECPTCL_FID ?: $request->input('marbete'))));
        return response()->streamDownload(function() use($result,$bag) {
            $stream=fopen('php://output','w');
            fwrite($stream,"\xEF\xBB\xBF");
            fputcsv($stream,['Marbete','Peso saca (kg)','Paquetes declarados','Código del paquete','Identificador local','Clase','Origen','Destino','Último movimiento registrado','Fecha IPS']);
            foreach($result['items'] as $item) {
                $row=[$bag->RECPTCL_FID,$bag->RECPTCL_WEIGHT,$bag->RECPTCL_MAILITMS_NO,$item->MAILITM_FID,$item->MAILITM_LOCAL_ID,
                    $item->MAIL_CLASS_NAME ?: $item->MAIL_CLASS_CD,$item->ORIGIN_COUNTRY,$item->DEST_COUNTRY,$item->EVENT_NAME_ES ?: $item->EVENT_NAME,$item->EVT_GMT_DT];
                fputcsv($stream,array_map(static function($cell) {
                    $value=(string)($cell ?? '');
                    return preg_match('/^[\s]*[=+@\-\t\r]/',$value) ? "'".$value : $value;
                },$row));
            }
            fclose($stream);
        },"saca-{$safeCode}.csv",['Content-Type'=>'text/csv; charset=UTF-8','Cache-Control'=>'private, no-store']);
    }

    public function document(Request $request, PostalWorkspace $workspace, string $kind)
    {
        abort_unless(in_array($kind, ['marbete', 'aduana'], true), 404);
        $request->validate(['codigo' => ['required', 'string', 'max:35']]);
        if ($kind === 'aduana') Gate::authorize('postal.cds');
        $data = $workspace->search($request->string('codigo')->toString(), Gate::allows('postal.ips'), Gate::allows('postal.cds'));
        abort_unless(in_array('ok', $data['sources'], true), 404, 'No hay datos disponibles para este documento.');
        $codes = collect($data['cds']['packages'] ?? [])->pluck('MAIL_OBJECT_ID')
            ->merge(collect($data['ips']['packageRows'] ?? [])->pluck('MAILITM_FID'))
            ->filter()->map(fn ($value) => strtoupper(trim($value)))->unique();
        abort_if($codes->count() > 1, 409, 'Este identificador corresponde a varios paquetes. Consulta el código postal exacto antes de imprimir.');
        if ($kind === 'aduana') abort_if(empty($data['cds']['declarations']), 404, 'No hay una declaración disponible.');
        if ($kind === 'aduana') {
            $declarations = collect($data['cds']['declarations'] ?? []);

            if ($declarations->count() === 1) {
                return redirect()->route('postal.cds.declaration.print', [
                    'codigo' => $request->string('codigo')->toString(),
                    'declaracion' => $declarations->first()['id'],
                ]);
            }

            return response()->view('postal.declaration-picker', [
                'code' => $request->string('codigo')->toString(),
                'declarations' => $declarations,
            ])->header('Cache-Control', 'private, no-store');
        }

        return response()->view('postal.print', $data + ['kind' => $kind])->header('Cache-Control', 'private, no-store');
    }
}
