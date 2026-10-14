<?php

namespace App\Services\Postal;

use Illuminate\Support\Collection;

/** Shapes read-only customs API responses without exposing duplicated IPS query rows. */
final class CustomsApiPresenter
{
    public function dispatch(array $result): array
    {
        $sacks = $this->rows($result['receptacles'] ?? []);
        $dispatchNumber = $result['code_analysis']['dispatch_sequence'] ?? null;
        $dispatches = $this->rows($result['dispatches'] ?? [])->map(function (array $dispatch) use ($sacks) {
            $dispatchSacks = $sacks->where('DESPTCH_PID', $dispatch['DESPTCH_PID'] ?? null)->values();
            $declaredSacks = $this->integer($dispatch['DESPTCH_RECPTCLS_NO'] ?? null);
            $linkedSacks = $this->integer($dispatch['LINKED_RECPTCLS_NO'] ?? $dispatchSacks->count()) ?? $dispatchSacks->count();

            return [
                'identifier' => $dispatch['DESPTCH_FID'] ?? null,
                'origin' => $dispatch['ORIG_OFFICE_FCD'] ?? null,
                'destination' => $dispatch['DEST_OFFICE_FCD'] ?? null,
                'departure_at' => $dispatch['DESPTCH_DEPARTURE_DT'] ?? null,
                'weight_kg' => $this->number($dispatch['DESPTCH_WEIGHT'] ?? null),
                'transport' => $dispatch['CONVEYANCE_TYPE_CD'] ?? null,
                'sacks_declared' => $declaredSacks,
                'sacks_linked' => $linkedSacks,
            ];
        })->values();

        return [
            'identifier' => $result['identifier'] ?? $result['code_analysis']['code'] ?? ($dispatches->first()['DESPTCH_FID'] ?? null),
            'dispatch_number' => $dispatchNumber,
            'dispatches' => $dispatches,
            'truncated' => (bool) ($result['truncated'] ?? false),
        ];
    }

    public function receptacle(array $result): array
    {
        $rawSack = $this->row($result['receptacle'] ?? []);
        $analysis = $result['code_analysis'] ?? [];
        $summary = $result['summary'] ?? [];
        $reconciliation = $summary['reconciliation'] ?? [];
        $cds = $result['cds'] ?? [];
        $packageCodes = $this->rows($result['packages'] ?? [])->pluck('code')->filter()->unique()->values();

        $dispatch = $this->row($result['dispatch'] ?? []);
        $events = $this->rows($result['receptacle_events'] ?? []);

        return [
            'identifier' => $result['identifier'] ?? $rawSack['RECPTCL_FID'] ?? null,
            'sack' => [
                'number' => $analysis['receptacle_sequence'] ?? null,
                'weight_kg' => $this->number($rawSack['RECPTCL_WEIGHT'] ?? null),
                'packages_declared' => $reconciliation['declared_count'] ?? null,
                'packages_linked' => $reconciliation['linked_count'] ?? $packageCodes->count(),
                'counts_match' => $reconciliation['count_matches'] ?? null,
            ],
            'related_identifiers' => [
                'dispatch' => $dispatch['code'] ?? $analysis['dispatch_code'] ?? null,
                'packages' => $packageCodes,
            ],
            'summary' => [
                'events_count' => $events->count(),
                'declarations_count' => (int) ($cds['declaration_count'] ?? 0),
                'declarations_status' => !($cds['enabled'] ?? false)
                    ? 'disabled' : (($cds['available'] ?? false) ? 'available' : 'unavailable'),
            ],
            'truncated' => (bool) ($result['truncated'] ?? false),
        ];
    }

    public function package(array $result): array
    {
        $ips = $result['ips'] ?? [];
        $cds = $result['cds'] ?? [];
        $package = $this->rows($ips['packageRows'] ?? [])->first() ?? [];
        $allEvents = $this->rows($ips['trackingRows'] ?? [])->map(fn (array $event) => [
            'occurred_at' => $event['EVENT_GMT_DT'] ?? $event['EVT_GMT_DT'] ?? $event['EVENT_LOCAL_DT'] ?? null,
            'code' => $event['EVENT_TYPE_CD'] ?? $event['EVT_TYPE_CD'] ?? null,
            'name' => $event['EVENT_TYPE_NM_ES'] ?? $event['EVENT_NAME_ES'] ?? $event['EVT_TYPE_NM_ES'] ?? $event['EVENT_NAME'] ?? null,
            'office' => $event['OFFICE_FCD'] ?? $event['EVT_OFFICE_FCD'] ?? null,
        ])->values();
        $events = $allEvents->take(10)->values();
        $cdsPackages = $this->workspaceCdsGroups($cds);
        $logistics = $this->rows($ips['logisticRows'] ?? []);
        $declarations = collect($cdsPackages)->flatMap(fn (array $group) => collect($group['declarations'] ?? [])->map(fn (array $declaration) => [
            'number' => $declaration['number'] ?? null,
            'state' => $declaration['state'] ?? null,
        ]))->unique(fn (array $declaration) => (string) ($declaration['number'] ?? json_encode($declaration)))->values();
        $packageData = $this->ipsPackage($package);
        unset($packageData['code']);

        return [
            'identifier' => $result['code'] ?? $package['MAILITM_FID'] ?? null,
            'sources' => $result['sources'] ?? [],
            'summary' => [
                'movement_count' => $allEvents->count(),
                'delivery_count' => count($ips['deliveryRows'] ?? []),
                'sack_count' => $logistics->pluck('RECPTCL_PID')->filter()->unique()->count(),
                'declaration_count' => $declarations->count(),
                'content_item_count' => count($ips['contentPieceRows'] ?? []),
            ],
            'package' => $packageData,
            'movements' => $events,
            'related_identifiers' => [
                'sacks' => $logistics->pluck('RECPTCL_FID')->filter()->unique()->values(),
                'dispatches' => $logistics->pluck('DESPTCH_FID')->filter()->unique()->values(),
                'declarations' => $declarations->pluck('number')->filter()->unique()->values(),
            ],
            'truncated' => (bool) ($result['truncated'] ?? false)
                || (bool) ($ips['truncated'] ?? false)
                || (bool) ($cds['truncated'] ?? false)
                || (bool) ($cds['declarations_truncated'] ?? false)
                || $allEvents->count() > $events->count(),
        ];
    }

    public function declarations(array $result): array
    {
        $cds = $result['cds'] ?? [];

        return [
            'identifier' => $result['code'] ?? $result['identifier'] ?? null,
            'source_status' => $result['sources']['CDS'] ?? 'unavailable',
            'packages' => $this->workspaceCdsGroups($cds),
            'truncated' => (bool) ($cds['truncated'] ?? false),
            'packages_truncated' => (bool) ($cds['packages_truncated'] ?? false),
            'declarations_truncated' => (bool) ($cds['declarations_truncated'] ?? false),
        ];
    }

    /** Full, type-specific record for the general Aduana lookup endpoint. */
    public function general(string $type, array $result): array
    {
        $identifier = in_array($type, ['S8', 'S9'], true)
            ? ($result['identifier'] ?? null)
            : ($result['code'] ?? null);
        $details = match ($type) {
            'S8' => $this->fullDispatch($result),
            'S9' => $this->fullReceptacle($result),
            default => $this->fullPackage($result),
        };

        return [
            'type' => $type,
            'identifier' => $identifier,
            'identifier_analysis' => in_array($type, ['S8', 'S9'], true)
                ? ($result['code_analysis'] ?? null)
                : ($result['identifier'] ?? null),
            'details' => $details,
        ];
    }

    private function fullDispatch(array $result): array
    {
        $sacks = $this->rows($result['receptacles'] ?? []);
        $dispatches = $this->rows($result['dispatches'] ?? [])->map(function (array $dispatch) use ($sacks) {
            $dispatchSacks = $sacks->where('DESPTCH_PID', $dispatch['DESPTCH_PID'] ?? null)->values();

            return [
                'identifier' => $dispatch['DESPTCH_FID'] ?? null,
                'origin' => $dispatch['ORIG_OFFICE_FCD'] ?? null,
                'destination' => $dispatch['DEST_OFFICE_FCD'] ?? null,
                'departure_at' => $dispatch['DESPTCH_DEPARTURE_DT'] ?? null,
                'weight_kg' => $this->number($dispatch['DESPTCH_WEIGHT'] ?? null),
                'transport' => $dispatch['CONVEYANCE_TYPE_CD'] ?? null,
                'sacks_declared' => $this->integer($dispatch['DESPTCH_RECPTCLS_NO'] ?? null),
                'sacks_linked' => $this->integer($dispatch['LINKED_RECPTCLS_NO'] ?? null),
                'sacks' => $dispatchSacks->map(fn (array $sack) => [
                    'identifier' => $sack['RECPTCL_FID'] ?? null,
                    'registration_number' => $sack['RECPTCL_REG_NO'] ?? null,
                    'insurance_number' => $sack['RECPTCL_INS_NO'] ?? null,
                    'weight_kg' => $this->number($sack['RECPTCL_WEIGHT'] ?? null),
                    'packages_declared' => $this->integer($sack['RECPTCL_MAILITMS_NO'] ?? null),
                    'packages_linked' => $this->integer($sack['LINKED_MAILITMS_NO'] ?? null),
                    'seal_number' => $sack['RECPTCL_SEAL_NUMBER'] ?? null,
                    'subclass' => $sack['MAIL_SUBCLASS_FCD'] ?? null,
                    'characteristic' => $sack['RECPTCL_CHARACTERISTIC_CD'] ?? null,
                    'content_format' => $sack['RECPTCL_CONTENT_FORMAT_CD'] ?? null,
                    'documentation_enclosed' => $sack['DOCUMENTATION_ENCLOSED'] ?? null,
                    'comments' => $sack['COMMENTS'] ?? null,
                    'status_code' => $this->integer($sack['STATE_IND_CD'] ?? null),
                    'postal_status_code' => $this->integer($sack['POSTAL_STATUS_CD'] ?? null),
                    'last_event' => [
                        'code' => $sack['EVT_TYPE_CD'] ?? null,
                        'occurred_at' => $sack['EVT_GMT_DT'] ?? null,
                    ],
                ])->values(),
            ];
        })->values();

        return [
            'dispatch_number' => $result['code_analysis']['dispatch_sequence'] ?? null,
            'dispatches' => $dispatches,
            'truncated' => (bool) ($result['truncated'] ?? false),
        ];
    }

    private function fullReceptacle(array $result): array
    {
        $rawSack = $this->row($result['receptacle'] ?? []);
        $analysis = $result['code_analysis'] ?? [];
        $dispatch = $this->row($result['dispatch'] ?? []);
        $summary = $result['summary'] ?? [];
        $cds = $result['cds'] ?? [];

        return [
            'sack' => [
                'number' => $analysis['receptacle_sequence'] ?? null,
                'weight_kg' => $this->number($rawSack['RECPTCL_WEIGHT'] ?? null),
                'registration_number' => $rawSack['RECPTCL_REG_NO'] ?? null,
                'insurance_number' => $rawSack['RECPTCL_INS_NO'] ?? null,
                'seal_number' => $rawSack['RECPTCL_SEAL_NUMBER'] ?? null,
                'declared_package_count' => $this->integer($rawSack['RECPTCL_MAILITMS_NO'] ?? null),
                'subclass' => $rawSack['MAIL_SUBCLASS_FCD'] ?? null,
                'characteristic' => $rawSack['RECPTCL_CHARACTERISTIC_CD'] ?? null,
                'content_format' => $rawSack['RECPTCL_CONTENT_FORMAT_CD'] ?? null,
                'documentation_enclosed' => $rawSack['DOCUMENTATION_ENCLOSED'] ?? null,
                'comments' => $rawSack['COMMENTS'] ?? null,
                'status_code' => $this->integer($rawSack['STATE_IND_CD'] ?? null),
                'postal_status_code' => $this->integer($rawSack['POSTAL_STATUS_CD'] ?? null),
            ],
            'dispatch' => $dispatch ? [
                'identifier' => $dispatch['code'] ?? null,
                'origin' => $dispatch['origin'] ?? null,
                'destination' => $dispatch['destination'] ?? null,
                'departure_at' => $dispatch['departure_at'] ?? null,
                'sacks_declared' => $dispatch['sacks_declared'] ?? null,
                'weight_kg' => $this->number($dispatch['weight_kg'] ?? null),
                'transport' => $dispatch['transport'] ?? null,
            ] : null,
            'summary' => $summary,
            'events' => $this->rows($result['receptacle_events'] ?? [])->map(fn (array $event) => $this->event($event))->values(),
            'packages' => $this->rows($result['packages'] ?? [])->map(function (array $package) {
                $cdsGroups = $this->receptacleCdsGroups([$package['declaration_group'] ?? []]);

                return [
                    'identifier' => $package['code'] ?? null,
                    'local_identifier' => $package['local_code'] ?? null,
                    'weight_kg' => $this->number($package['weight_kg'] ?? null),
                    'class' => ['code' => $package['class_code'] ?? null, 'name' => $package['class_name'] ?? null],
                    'origin' => ['code' => $package['origin_country_code'] ?? null, 'name' => $package['origin_country'] ?? null],
                    'destination' => ['code' => $package['destination_country_code'] ?? null, 'name' => $package['destination_country'] ?? null],
                    'postal_status' => ['code' => $package['postal_status_code'] ?? null, 'name' => $package['postal_status'] ?? null],
                    'last_movement' => [
                        'code' => $package['event_cd'] ?? null,
                        'name' => $package['event_name'] ?? null,
                        'occurred_at' => $package['event_at'] ?? null,
                        'office_code' => $package['office_cd'] ?? null,
                    ],
                    'scan_status' => $package['scan_status'] ?? null,
                    'scanned_at' => $package['scanned_at'] ?? null,
                    'allowed_actions' => $package['allowed_actions'] ?? [],
                    'cds' => $cdsGroups[0] ?? null,
                ];
            })->values(),
            'manifests' => $this->rows($result['manifests'] ?? [])->map(fn (array $manifest) => $this->manifest($manifest))->values(),
            'cds' => [
                'status' => !($cds['enabled'] ?? false) ? 'disabled' : (($cds['available'] ?? false) ? 'available' : 'unavailable'),
                'declaration_count' => (int) ($cds['declaration_count'] ?? 0),
                'missing_codes' => $cds['missing_codes'] ?? [],
                'without_declaration_codes' => $cds['without_declaration_codes'] ?? [],
                'deleted_declaration_codes' => $cds['deleted_declaration_codes'] ?? [],
                'unconfirmed_codes' => $cds['unconfirmed_codes'] ?? [],
                'complete' => (bool) ($cds['complete'] ?? false),
                'truncated' => (bool) ($cds['truncated'] ?? false),
            ],
            'office' => ($office = $this->row($result['office'] ?? [])) ? [
                'code' => $office['office_cd'] ?? null,
                'name' => $office['office_name'] ?? null,
                'event_code' => $office['event_cd'] ?? null,
                'event_at' => $office['event_at'] ?? null,
                'weight_kg' => $office['weight_kg'] ?? null,
                'package_count' => $office['items_count'] ?? null,
            ] : null,
            'truncated' => (bool) ($result['truncated'] ?? false),
        ];
    }

    private function fullPackage(array $result): array
    {
        $ips = $result['ips'] ?? [];
        $cds = $result['cds'] ?? [];
        $package = $this->rows($ips['packageRows'] ?? [])->first() ?? [];
        $events = $this->rows($ips['trackingRows'] ?? [])->map(fn (array $event) => $this->event($event))->values();
        $deliveries = $this->rows($ips['deliveryRows'] ?? [])->map(fn (array $row) => [
            'occurred_at' => $row['EVENT_GMT_DT'] ?? null,
            'event_code' => $row['EVENT_TYPE_CD'] ?? null,
            'event' => $row['EVENT_TYPE_NM_ES'] ?? null,
            'local_time_offset' => $row['EVENT_LOCAL_OFFSET'] ?? null,
            'non_delivery_reason_code' => $row['NON_DELIVERY_REASON_CD'] ?? null,
            'non_delivery_measure_code' => $row['NON_DELIVERY_MEASURE_CD'] ?? null,
            'signatory' => $row['SIGNATORY_NM'] ?? null,
            'location' => $row['DELIV_LOCATION'] ?? null,
            'postal_code' => $row['DELIV_POSTCODE'] ?? null,
        ])->values();
        $packageData = $this->ipsPackage($package);

        return [
            'sources' => $result['sources'] ?? [],
            'summary' => [
                'movement_count' => $events->count(),
                'delivery_count' => $deliveries->count(),
                'sack_count' => collect($ips['logisticRows'] ?? [])->pluck('RECPTCL_PID')->filter()->unique()->count(),
                'manifest_count' => count($ips['manifestRows'] ?? []),
                'content_item_count' => count($ips['contentPieceRows'] ?? []),
                'declaration_count' => collect($this->workspaceCdsGroups($cds))->sum(fn (array $group) => count($group['declarations'])),
            ],
            'package' => $packageData,
            'events' => $events,
            'customers' => $this->rows($ips['customerRows'] ?? [])->map(fn (array $row) => [
                'role' => match ((string) ($row['SENDER_PAYEE_IND'] ?? '')) {
                    'S' => 'sender', 'P', 'R', 'A' => 'recipient', default => 'contact',
                },
                'name' => trim(implode(' ', array_filter([$row['CUSTOMER_FORENAME'] ?? null, $row['CUSTOMER_NAME'] ?? null]))) ?: null,
                'address' => $row['CUSTOMER_ADDRESS'] ?? null,
                'city' => $row['CUSTOMER_CITY'] ?? null,
                'postal_code' => $row['CUSTOMER_POST_CODE'] ?? null,
                'country' => ['code' => $row['COUNTRY_CD'] ?? null, 'name' => $row['COUNTRY_NM'] ?? null],
                'phone' => $row['CUSTOMER_PHONE_NO'] ?? null,
                'email' => $row['CUSTOMER_EMAIL_ADDRESS'] ?? null,
            ])->values(),
            'delivery_records' => $deliveries,
            'logistics' => [
                'sacks' => $this->rows($ips['logisticRows'] ?? [])->map(fn (array $row) => [
                    'identifier' => $row['RECPTCL_FID'] ?? null,
                    'weight_kg' => $this->number($row['RECPTCL_WEIGHT'] ?? null),
                    'packages_declared' => $this->integer($row['RECPTCL_MAILITMS_NO'] ?? null),
                    'dispatch' => [
                        'identifier' => $row['DESPTCH_FID'] ?? null,
                        'origin' => $row['ORIG_OFFICE_FCD'] ?? null,
                        'destination' => $row['DEST_OFFICE_FCD'] ?? null,
                        'departure_at' => $row['DESPTCH_DEPARTURE_DT'] ?? null,
                    ],
                ])->values(),
                'manifests' => $this->rows($ips['manifestRows'] ?? [])->map(fn (array $row) => $this->manifest($row))->values(),
                'international_events' => $this->rows($ips['ediRows'] ?? [])->map(fn (array $row) => [
                    'event_code' => $row['EVENT_TYPE_CD'] ?? null,
                    'event' => $row['EVENT_TYPE_NM_ES'] ?? null,
                    'occurred_at' => $row['EVENT_LOCAL_DT'] ?? null,
                    'captured_at' => $row['CAPTURE_GMT_DT'] ?? null,
                    'location' => $row['LOCATION_ID'] ?? null,
                    'sender_id' => $row['SENDER_ID'] ?? null,
                    'dispatch_number' => $row['DESPATCH_NUMBER'] ?? null,
                ])->values(),
            ],
            'customs' => [
                'records' => $this->rows($ips['customsRows'] ?? [])->map(fn (array $row) => [
                    'declared_gross_weight' => $row['DECLARED_GROSS_WEIGHT'] ?? null,
                    'sender_reference' => $row['SENDER_CUSTOMS_REFERENCE_NO'] ?? null,
                    'recipient_reference' => $row['RECIPIENT_CUSTOMS_REFERENCE_NO'] ?? null,
                ])->values(),
                'declared_content' => $this->rows($ips['contentPieceRows'] ?? [])->map(fn (array $row) => [
                    'identifier' => $row['IDENTIFIER'] ?? null,
                    'quantity' => $row['NUMBER_OF_UNITS'] ?? null,
                    'description' => $row['DESCRIPTION'] ?? null,
                    'declared_value' => $row['DECLARED_VALUE'] ?? null,
                    'currency' => $row['DECLARED_VALUE_CURRENCY_CD'] ?? null,
                    'net_weight_kg' => $row['NET_WEIGHT'] ?? null,
                    'origin' => $row['ORIGIN_LOCATION'] ?? null,
                    'tariff_heading' => $row['TARIFF_HEADING'] ?? null,
                ])->values(),
                'cds' => [
                    'status' => $result['sources']['CDS'] ?? 'unavailable',
                    'packages' => $this->workspaceCdsGroups($cds),
                    'truncated' => (bool) ($cds['truncated'] ?? false),
                    'declarations_truncated' => (bool) ($cds['declarations_truncated'] ?? false),
                ],
            ],
            'truncated' => (bool) ($ips['truncated'] ?? false)
                || (bool) ($cds['truncated'] ?? false)
                || (bool) ($cds['declarations_truncated'] ?? false),
        ];
    }

    private function ipsPackage(array $row): array
    {
        return [
            'code' => trim((string) ($row['MAILITM_FID'] ?? '')) ?: null,
            'local_code' => $row['MAILITM_LOCAL_ID'] ?? null,
            'weight_kg' => $this->number($row['MAILITM_WEIGHT'] ?? null),
            'value' => $row['MAILITM_VALUE'] ?? null,
            'duties_amount' => $row['DUTIES_AMOUNT'] ?? null,
            'customs_number' => $row['CUSTOMS_NO'] ?? null,
            'class' => ['code' => $row['MAIL_CLASS_CD'] ?? null, 'name' => $row['MAIL_CLASS_NM'] ?? null],
            'content' => ['code' => $row['MAILITM_CONTENT_CD'] ?? null, 'name' => $row['MAILITM_CONTENT_NM'] ?? null],
            'product_type' => ['code' => $row['PRODUCT_TYPE_CD'] ?? null, 'name' => $row['PRODUCT_TYPE_NM'] ?? null],
            'postal_status' => ['code' => $row['POSTAL_STATUS_CD'] ?? null, 'name' => $row['POSTAL_STATUS_NM'] ?? null],
            'origin' => ['code' => $row['ORIG_COUNTRY_CD'] ?? null, 'name' => $row['ORIG_COUNTRY_NM'] ?? null],
            'destination' => ['code' => $row['DEST_COUNTRY_CD'] ?? null, 'name' => $row['DEST_COUNTRY_NM'] ?? null],
            'currency' => ['code' => $row['CURRENCY_CD'] ?? null, 'name' => $row['CURRENCY_NM'] ?? null],
        ];
    }

    private function workspaceCdsGroups(array $cds): array
    {
        $declarations = collect($cds['declarations'] ?? [])->groupBy(fn ($row) => (string) ($row['package_id'] ?? ''));
        $responses = collect($cds['responses'] ?? [])->groupBy(fn ($row) => (string) ($row['package_id'] ?? ''));
        $events = collect($cds['events'] ?? [])->groupBy(fn ($row) => (string) ($row['record_id'] ?? ''));

        return $this->rows($cds['packages'] ?? [])->map(function (array $package) use ($declarations, $responses, $events) {
            $pid = (string) ($package['MAIL_OBJECT_PID'] ?? '');
            $packageDeclarations = $declarations->get($pid, collect())->map(fn (array $row) => $this->declaration(
                $row,
                $events->get((string) ($row['id'] ?? ''), collect())->all(),
            ))->values();
            $packageResponses = $responses->get($pid, collect())->map(fn (array $row) => $this->response(
                $row,
                $events->get((string) ($row['id'] ?? ''), collect())->all(),
            ))->values();

            return [
                'code' => $package['MAIL_OBJECT_ID'] ?? null,
                'local_codes' => array_values(array_filter([
                    $package['MAIL_OBJECT_LOCAL_ID'] ?? null,
                    $package['MAIL_OBJECT_LOCAL_ID2'] ?? null,
                ])),
                'type' => $package['MAIL_OBJECT_TYPE_CD'] ?? null,
                'class' => $package['MAIL_CLASS_CD'] ?? null,
                'category' => $package['MAIL_CATEGORY_CD'] ?? null,
                'flow' => $package['MAIL_FLOW_CD'] ?? null,
                'origin' => $package['ORIG_POST_ORGANIZATION_CD'] ?? null,
                'destination' => $package['DEST_POST_ORGANIZATION_CD'] ?? null,
                'posted_at' => $package['POSTING_DATE'] ?? null,
                'state' => $package['MAIL_STATE_NM'] ?? null,
                'declarations' => $packageDeclarations,
                'responses' => $packageResponses,
            ];
        })->values()->all();
    }

    private function receptacleCdsGroups(array $groups): array
    {
        $packages = [];
        foreach ($groups as $group) {
            $group = $this->row($group);
            if (!$group) continue;
            $records = $group['records'] ?? [[
                'package' => $group['package'] ?? [],
                'matched_codes' => $group['matched_codes'] ?? [],
                'declarations' => $group['declarations'] ?? [],
                'responses' => $group['responses'] ?? [],
                'status' => $group['status'] ?? null,
            ]];

            foreach ($records as $record) {
                $record = $this->row($record);
                $package = $this->row($record['package'] ?? []);
                $pid = (string) ($package['MAIL_OBJECT_PID'] ?? ($package['MAIL_OBJECT_ID'] ?? count($packages)));
                $packages[$pid] ??= [
                    'identifier' => $package['MAIL_OBJECT_ID'] ?? collect($record['matched_codes'] ?? [])->first(),
                    'local_identifiers' => array_values(array_filter([
                        $package['MAIL_OBJECT_LOCAL_ID'] ?? null,
                        $package['MAIL_OBJECT_LOCAL_ID2'] ?? null,
                    ])),
                    'type' => $package['MAIL_OBJECT_TYPE_CD'] ?? null,
                    'class' => $package['MAIL_CLASS_CD'] ?? null,
                    'flow' => $package['MAIL_FLOW_CD'] ?? null,
                    'origin' => $package['ORIG_POST_ORGANIZATION_CD'] ?? null,
                    'destination' => $package['DEST_POST_ORGANIZATION_CD'] ?? null,
                    'posted_at' => $package['POSTING_DATE'] ?? null,
                    'declarations' => [],
                    'responses' => [],
                    'status' => $record['status'] ?? $group['status'] ?? null,
                ];

                foreach ($record['declarations'] ?? [] as $declaration) {
                    $row = $this->row($declaration);
                    $normalized = $this->declaration($row);
                    $packages[$pid]['declarations'][(string) ($row['id'] ?? json_encode($normalized))] = $normalized;
                }
                foreach ($record['responses'] ?? [] as $response) {
                    $row = $this->row($response);
                    $normalized = $this->response($row);
                    $packages[$pid]['responses'][(string) ($row['id'] ?? json_encode($normalized))] = $normalized;
                }
            }
        }

        foreach ($packages as &$package) {
            $package['declarations'] = array_values($package['declarations']);
            $package['responses'] = array_values($package['responses']);
        }
        unset($package);

        return array_values($packages);
    }

    private function declaration(array $record, array $events = []): array
    {
        $data = $this->row($record['data'] ?? []);

        return [
            'number' => $record['declaration_number'] ?? null,
            'state' => $record['state'] ?? null,
            'workflow_stage' => $record['workflow_stage'] ?? null,
            'content_summary' => $record['content_summary'] ?? null,
            'data_status' => $record['data_status'] ?? $data['status'] ?? null,
            'data' => [
                'fields' => $data['fields'] ?? [],
                'pieces' => $data['pieces'] ?? [],
                'documents' => $data['documents'] ?? [],
            ],
            'countries' => $record['countries'] ?? [],
            'nature' => $record['nature'] ?? null,
            'events' => collect($events)->map(fn ($event) => $this->cdsEvent($this->row($event)))->values(),
        ];
    }

    private function response(array $record, array $events = []): array
    {
        $data = $this->row($record['data'] ?? []);

        return [
            'state' => $record['state'] ?? null,
            'decision' => $record['decision'] ?? null,
            'decision_code' => $record['decision_code'] ?? $data['fields']['Decis'] ?? null,
            'data_status' => $record['data_status'] ?? $data['status'] ?? null,
            'data' => [
                'fields' => $data['fields'] ?? [],
                'pieces' => $data['pieces'] ?? [],
                'documents' => $data['documents'] ?? [],
            ],
            'events' => collect($events)->map(fn ($event) => $this->cdsEvent($this->row($event)))->values(),
        ];
    }

    private function cdsEvent(array $row): array
    {
        return [
            'occurred_at' => $row['occurred_at'] ?? null,
            'code' => $row['code'] ?? null,
            'name' => $row['name'] ?? null,
            'user_code' => $row['user_code'] ?? null,
            'user_name' => $row['user_name'] ?? null,
            'office' => $row['office'] ?? null,
            'source' => $row['source'] ?? 'CDS',
            'kind' => $row['kind'] ?? null,
        ];
    }

    private function event(array $row): array
    {
        return [
            'occurred_at' => $row['EVENT_GMT_DT'] ?? $row['EVT_GMT_DT'] ?? $row['EVENT_LOCAL_DT'] ?? null,
            'local_time_offset' => $row['EVENT_LOCAL_OFFSET'] ?? $row['EVT_LOCAL_OFFSET'] ?? null,
            'code' => $row['EVENT_TYPE_CD'] ?? $row['EVT_TYPE_CD'] ?? null,
            'name' => $row['EVENT_TYPE_NM_ES'] ?? $row['EVENT_NAME_ES'] ?? $row['EVT_TYPE_NM_ES'] ?? $row['EVENT_NAME'] ?? null,
            'office_code' => $row['EVENT_OFFICE_CD'] ?? $row['EVT_OFFICE_CD'] ?? null,
            'office' => $row['OFFICE_FCD'] ?? $row['EVT_OFFICE_FCD'] ?? null,
            'office_name' => $row['OFFICE_NM'] ?? $row['EVT_OFFICE_NM'] ?? null,
            'next_office' => $row['NEXT_OFFICE_FCD'] ?? null,
            'user' => $row['USER_FID'] ?? $row['USER_NM'] ?? null,
            'user_name' => $row['USER_NM'] ?? null,
            'detail' => $row['DETAIL_TXT'] ?? null,
            'source' => $row['SOURCE_DB'] ?? null,
        ];
    }

    private function manifest(array $row): array
    {
        return [
            'id' => $row['MANIFEST_LIST_ID'] ?? null,
            'name' => $row['FORM_NM'] ?? null,
            'type' => $row['MANIF_TYPE_ID'] ?? null,
            'created_at' => $row['CREATION_LCL_DT'] ?? null,
            'office' => $row['OFFICE_FCD'] ?? null,
            'office_name' => $row['OFFICE_NM'] ?? null,
            'user' => $row['USER_FID'] ?? null,
            'user_name' => $row['USER_NM'] ?? null,
            'observation' => $row['OBSERVATION'] ?? null,
        ];
    }

    private function rows(mixed $rows): Collection
    {
        return collect($rows ?? [])->map(fn ($row) => $this->row($row));
    }

    private function row(mixed $row): array
    {
        return is_array($row) ? $row : (is_object($row) ? get_object_vars($row) : []);
    }

    private function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
