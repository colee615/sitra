<?php

namespace App\Services;

use App\Exceptions\IpsOperationException;
use App\Services\Postal\CustomsDestinationResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class IpsWorkflowService
{
    public function __construct(private readonly IpsRepository $ips) {}

    public function execute(string $action, array $input): array
    {
        $this->ips->assertReady();

        return $this->ips->connection()->transaction(function () use ($action, $input) {
            $this->ips->lockCode($input['codigo']);
            $item = $this->ips->find($input['codigo'], true);
            $input = $this->autofillDeliveryInput($action, $input, $item);
            $definition = config('ips.events.'.$input['event']);
            if (! $definition) {
                throw new IpsOperationException('Evento operativo no permitido.', 422);
            }
            $event = $this->ips->event($definition['id']);
            if (! $event || trim($event->INB_OTB_IND) !== $definition['direction']) {
                throw new IpsOperationException('El catálogo IPS no coincide con la configuración.', 503);
            }
            if ($action !== 'create' && $item && in_array($input['event'], ['EMR', 'CUSTOMS_PRESENT', 'CUSTOMS_INFO', 'EDN', 'EIB', 'EMG', 'CUSTOMS_RETURN', 'HANDOVER_AGENT', 'EDH', 'EDG', 'EMH', 'EMI'], true)) {
                $this->ips->enrich(collect([$item]));
            }
            $this->validateTransition($action, $input, $item, $event, $definition);
            $domesticReceptacle = null;
            if ($input['event'] === 'EIB') {
                $domesticReceptacle = $this->ips->lockOpenDomesticReceptacle(
                    $input['domestic_receptacle_pid'],
                    (int) $input['office_cd'],
                    (int) $item->NEXT_OFFICE_CD,
                    trim((string) $item->MAIL_CLASS_CD)
                );
                if (! $domesticReceptacle || $this->ips->hasActiveDomesticReceptacleLink((string) $item->MAILITM_PID)) {
                    throw new IpsOperationException('La saca ya no estÃ¡ abierta, no coincide con la ruta o el envÃ­o ya pertenece a otra saca.', 409);
                }
            }
            $date = Carbon::parse($input['occurred_at']);
            $parameters = $this->mailParameters($item, $input, $event, $date);
            if ($domesticReceptacle) {
                $this->ips->linkDomesticReceptacle((string) $domesticReceptacle->DOM_REC_PID, (string) $item->MAILITM_PID);
            }
            $this->ips->callProcedure('SP_SET_MAILITM', $parameters);
            if ($action === 'create') {
                foreach (['sender' => 'S', 'recipient' => 'A'] as $field => $indicator) {
                    $this->ips->callProcedure('SP_SET_MAILITM_CUSTOMERS', $this->customerParameters($parameters['PId'], $indicator, $input[$field]));
                }
            }
            $expectedNextOffice = in_array($input['event'], ['EDN', 'EIB'], true)
                ? (int) ($input['next_office_cd'] ?? $item?->NEXT_OFFICE_CD)
                : null;
            $this->ips->verifyWrite(
                $parameters['PId'],
                (int) $event->EVENT_TYPE_CD,
                $date->copy()->utc()->toDateTimeString(),
                $input['signatory'] ?? null,
                $expectedNextOffice,
                in_array($input['event'], ['EMR', 'EMD'], true) ? (string) $input['receptacle_pid'] : null,
                in_array($input['event'], ['EMR', 'EMD'], true) ? (int) $input['condition_cd'] : null
            );
            if ($domesticReceptacle && ! $this->ips->domesticReceptacleLinkExists((string) $domesticReceptacle->DOM_REC_PID, (string) $item->MAILITM_PID)) {
                throw new IpsOperationException('IPS no confirmÃ³ que el envÃ­o quedÃ³ en la saca nacional. OperaciÃ³n revertida.', 502);
            }

            $result = [
                'codigo' => trim($parameters['FId']),
                'local_id' => trim($parameters['LocalId'] ?? ''),
                'mailitm_pid' => $parameters['PId'],
                'event' => $input['event'],
                'event_cd' => (int) $event->EVENT_TYPE_CD,
                'event_at' => $date->copy()->utc()->format('Y-m-d\TH:i:sP'),
                'office_cd' => (int) $input['office_cd'],
                'actor_user_pid' => (int) $parameters['User'],
                'workstation_pid' => (int) $parameters['Workstation'],
                'external_actor_id' => $input['external_actor_id'] ?? null,
            ];
            if (in_array($input['event'], ['EDN', 'EIB'], true)) {
                $result['next_office_cd'] = $expectedNextOffice;
            }
            if ($domesticReceptacle) {
                $result['domestic_receptacle'] = [
                    'pid' => (string) $domesticReceptacle->DOM_REC_PID,
                    'fid' => trim((string) $domesticReceptacle->DOM_REC_FID),
                ];
            }
            return $result;
        }, 1); // No automatic transaction retry across IPS procedures.
    }

    private function autofillDeliveryInput(string $action, array $input, ?object $item): array
    {
        if ($action === 'create' || ($input['event'] ?? null) !== 'EMI' || ! $item) {
            return $input;
        }

        $office = isset($item->EVT_OFFICE_CD) ? $this->ips->ownOffice((int) $item->EVT_OFFICE_CD) : null;
        $deliveryMode = app(IpsStagePolicy::class)->inferDeliveryMode($this->ips->present($item));

        return array_replace([
            'occurred_at' => Carbon::now(config('ips.operation_timezone', 'America/La_Paz'))->format('Y-m-d\TH:i:sP'),
            'office_cd' => isset($item->EVT_OFFICE_CD) ? (int) $item->EVT_OFFICE_CD : null,
            'expected_event_cd' => isset($item->EVT_TYPE_CD) ? (int) $item->EVT_TYPE_CD : null,
            'expected_event_at' => isset($item->EVT_GMT_DT) ? Carbon::parse($item->EVT_GMT_DT, 'UTC')->format('Y-m-d\TH:i:s.vP') : null,
            'delivery_location' => $office ? Str::limit(trim($office->OFFICE_NM), 25, '') : null,
            'delivery_mode' => $deliveryMode,
        ], array_filter($input, fn ($value) => $value !== null && $value !== ''));
    }

    private function validateTransition(string $action, array $input, ?object $item, object $event, array $definition): void
    {
        $this->validatePostalExtras($input);
        if ($action === 'create') {
            if ($input['event'] === 'EMD' && empty($input['physical_receipt_confirmed'])) {
                throw new IpsOperationException('Confirme que el paquete llegó físicamente dentro de la saca recibida.', 422);
            }
            if (! $this->ips->reference('N_OWN_OFFICES', 'OWN_OFFICE_CD', $input['office_cd'])) {
                throw new IpsOperationException('La oficina no está activa en IPS.', 422);
            }
            if ($item) {
                throw new IpsOperationException('El paquete ya existe. Consulte su estado antes de registrar eventos.');
            }
            if (! $definition['create']) {
                throw new IpsOperationException('Un alta requiere admisión EMA o recepción internacional EMD.', 422);
            }
            foreach ([
                ['C_MAIL_CLASSES', 'MAIL_CLASS_CD', $input['mail_class']],
                ['C_COUNTRIES', 'COUNTRY_CD', $input['origin_country']],
                ['C_COUNTRIES', 'COUNTRY_CD', $input['destination_country']],
                ['C_COUNTRIES', 'COUNTRY_CD', $input['sender']['country']],
                ['C_COUNTRIES', 'COUNTRY_CD', $input['recipient']['country']],
            ] as [$table, $column, $value]) {
                if (! $this->ips->reference($table, $column, $value, false)) {
                    throw new IpsOperationException('Clase postal o país no reconocido por IPS.', 422);
                }
            }
            if ($input['event'] === 'EMD' && $input['destination_country'] !== config('ips.destination_country')) {
                throw new IpsOperationException('La recepción de importación debe tener destino Bolivia.', 422);
            }
            if ($input['event'] === 'EMA' && $input['origin_country'] !== config('ips.destination_country')) {
                throw new IpsOperationException('La admisión local debe tener origen Bolivia.', 422);
            }

            return;
        }
        if (! $item) {
            throw new IpsOperationException('Paquete no encontrado en IPS.', 404);
        }
        if (! $this->ips->reference('N_OWN_OFFICES', 'OWN_OFFICE_CD', $input['office_cd'])) {
            throw new IpsOperationException('La oficina no está activa en IPS.', 422);
        }
        if ((int) $item->STATE_IND_CD === 5 || $this->ips->eventExists($item->MAILITM_PID, [37, 76])) {
            throw new IpsOperationException('El paquete ya fue entregado o su importación terminó.');
        }
        if ((int) $item->EVT_TYPE_CD !== (int) $input['expected_event_cd'] ||
            ! Carbon::parse($item->EVT_GMT_DT, 'UTC')->equalTo(Carbon::parse($input['expected_event_at']))) {
            throw new IpsOperationException('El estado cambió desde la consulta. Actualice el paquete.');
        }
        if (Carbon::parse($input['occurred_at'])->lessThanOrEqualTo(Carbon::parse($item->EVT_GMT_DT, 'UTC'))) {
            throw new IpsOperationException('El evento debe ser posterior al último movimiento.', 422);
        }
        if (! $this->ips->compatible((int) $item->STATE_IND_CD, (int) $event->EVENT_TYPE_CD)) {
            throw new IpsOperationException('La transición no está permitida por el catálogo de estados IPS.');
        }
        $customsEvents = ['CUSTOMS_PRESENT', 'CUSTOMS_INFO', 'CUSTOMS_RETURN'];
        $destinationCountry = trim((string) ($item->DEST_COUNTRY_CD ?? ''));
        if (in_array($input['event'], $customsEvents, true) && $destinationCountry === '') {
            $destinationCountry = app(CustomsDestinationResolver::class)->forIpsItem($item);
        }
        if ($definition['direction'] === 'I' && $destinationCountry !== config('ips.destination_country')) {
            throw new IpsOperationException('Este flujo de entrega solo opera envíos con destino Bolivia.');
        }
        if (isset($input['actor_user_pid'])) {
            $actor = $this->ips->user((int) $input['actor_user_pid']);
            if ((int) ($actor['office_cd'] ?? 0) !== (int) $input['office_cd']) {
                throw new IpsOperationException('La oficina no corresponde al usuario IPS vinculado.', 403);
            }
        }
        if (in_array($input['event'], ['EMR', 'CUSTOMS_PRESENT', 'CUSTOMS_INFO', 'EDN', 'EIB', 'EMG', 'CUSTOMS_RETURN', 'HANDOVER_AGENT', 'EDH', 'EDG', 'EMH', 'EMI'], true)) {
            $package = $this->ips->present($item);
            if (in_array($input['event'], $customsEvents, true) && trim((string) ($package['destination_country'] ?? '')) === '') {
                $package['destination_country'] = $destinationCountry;
            }
            if (!in_array($input['event'], app(IpsStagePolicy::class)->actions($package, (int) $input['office_cd']), true)) {
                throw new IpsOperationException('La etapa u oficina actual no está habilitada para esta operación.');
            }
            if ($input['event'] === 'HANDOVER_AGENT'
                && (int) ($package['operational_event_cd'] ?? $package['event_cd']) === 36
                && empty($input['physical_return_confirmed'])) {
                throw new IpsOperationException('Confirme que el paquete regresó físicamente a la oficina antes de asignarlo para un nuevo intento.', 422);
            }
            if ($input['event'] === 'EMG' && empty($input['physical_receipt_confirmed'])) {
                throw new IpsOperationException('Confirme que recibió físicamente el paquete.', 422);
            }
            if ($input['event'] === 'EMR' && empty($input['physical_receipt_confirmed'])) {
                throw new IpsOperationException('Confirme que recibió físicamente el paquete en la oficina de intercambio.', 422);
            }
            if ($input['event'] === 'CUSTOMS_RETURN' && empty($input['physical_receipt_confirmed'])) {
                throw new IpsOperationException('Confirme que el paquete retornó físicamente desde Aduana.', 422);
            }
        }
        if (in_array($input['event'], ['EDN', 'EIB'], true)) {
            $package = $this->ips->present($item);
            if (! in_array($input['event'], app(IpsStagePolicy::class)->actions($package, (int) $input['office_cd']), true)) {
                throw new IpsOperationException('El paquete no está en la etapa u oficina requerida para este movimiento.');
            }
        }
        if ($input['event'] === 'EDN'
            && ! $this->ips->isNextOffice((int) $input['office_cd'], (int) $input['next_office_cd'])) {
            throw new IpsOperationException('La oficina de ruta no está habilitada en IPS para este origen.', 422);
        }
        if ($input['event'] === 'EIB'
            && (! isset($item->NEXT_OFFICE_CD) || ! $this->ips->isNextOffice((int) $input['office_cd'], (int) $item->NEXT_OFFICE_CD))) {
            throw new IpsOperationException('El evento 35 no dejó una oficina de ruta válida en IPS.', 422);
        }
        if ($input['event'] === 'EIB' && $this->ips->hasActiveDomesticReceptacleLink((string) $item->MAILITM_PID)) {
            throw new IpsOperationException('El envío ya figura asociado a una saca nacional.', 409);
        }
        if ($input['event'] === 'EMH') {
            $package = $this->ips->present($item);
            if (!in_array('EMH', app(IpsStagePolicy::class)->actions($package, (int) $input['office_cd']), true)) {
                throw new IpsOperationException('El paquete no está en reparto o la oficina no puede registrar este intento fallido.');
            }
            if (! $this->ips->reference('C_NON_DELIVERY_REASONS', 'NON_DELIVERY_REASON_CD', $input['non_delivery_reason']) ||
                ! $this->ips->reference('C_NON_DELIVERY_MEASURES', 'NON_DELIVERY_MEASURE_CD', $input['non_delivery_measure'])) {
                throw new IpsOperationException('Motivo o medida de entrega fallida inválidos.', 422);
            }
        }
        if ($input['event'] === 'EMI' && empty($input['delivery_mode'])) {
            throw new IpsOperationException('Seleccione si la entrega fue por ventanilla o por cartero.', 422);
        }
    }

    private function validatePostalExtras(array $input): void
    {
        if (in_array($input['event'], ['EMR', 'EMD'], true)) {
            if (! $this->ips->reference('C_ITEM_CONDITIONS', 'ITEM_CONDITION_CD', (int) $input['condition_cd'], false)) {
                throw new IpsOperationException('Seleccione una condición válida del paquete según la revisión física.', 422);
            }
            if (empty($input['receptacle_pid'])) {
                throw new IpsOperationException('Seleccione el envase recibido y abierto que contiene este paquete.', 422);
            }
        }
        if ($input['event'] === 'CUSTOMS_PRESENT'
            && (! $this->ips->reference('N_OWN_OFFICES', 'OWN_OFFICE_CD', $input['customs_office_cd'])
                || ! $this->ips->reference('C_CUSTOMS_OFFICE_TYPES', 'CUSTOMS_OFFICE_TYPE_CD', $input['customs_office_type_cd'], false))) {
            throw new IpsOperationException('La oficina o el tipo de Aduana no existe en el catalogo IPS.', 422);
        }
        if ($input['event'] === 'CUSTOMS_INFO'
            && ! $this->ips->reference('C_CUSTOMS_RELEASE_STATUSES', 'CUSTOMS_RELEASE_STATUS_CD', $input['customs_release_status_cd'], false)) {
            throw new IpsOperationException('El estado de Aduana no existe en el catalogo IPS.', 422);
        }
        if ($input['event'] === 'HANDOVER_AGENT'
            && ! $this->ips->isDeliveryAgentAllowedAtOffice((int) $input['delivery_agent_cd'], (int) $input['office_cd'])) {
            throw new IpsOperationException('El cartero no está vigente o no pertenece a la regional de esta oficina.', 422);
        }
        if (in_array($input['event'], ['EMR', 'EMD'], true) && ! empty($input['receptacle_pid'])) {
            $this->ips->assertOpenInboundReceptacle((string) $input['receptacle_pid'], (int) $input['office_cd']);
        }
    }

    private function mailParameters(?object $item, array $input, object $event, Carbon $date): array
    {
        $map = [
            'PId' => 'MAILITM_PID', 'FId' => 'MAILITM_FID', 'StateInd' => 'STATE_IND_CD',
            'PostalStatus' => 'POSTAL_STATUS_CD', 'LocalId' => 'MAILITM_LOCAL_ID', 'Weight' => 'MAILITM_WEIGHT',
            'Currency' => 'CURRENCY_CD', 'MailItemValue' => 'MAILITM_VALUE', 'DutiableInd' => 'DUTIABLE_IND',
            'DutiesAmount' => 'DUTIES_AMOUNT', 'CustomsNumber' => 'CUSTOMS_NO', 'MailClass' => 'MAIL_CLASS_CD',
            'MailContent' => 'MAILITM_CONTENT_CD', 'Operator' => 'OPERATOR_CD', 'OrigCountry' => 'ORIG_COUNTRY_CD',
            'DestCountry' => 'DEST_COUNTRY_CD', 'ProductType' => 'PRODUCT_TYPE_CD', 'Misc1' => 'MISC1',
            'Misc2' => 'MISC2', 'Misc3' => 'MISC3', 'Misc4' => 'MISC4', 'Comments' => 'COMMENTS',
            'PostagePaidValue' => 'POSTAGE_PAID_VALUE', 'PostagePaidCurrency' => 'POSTAGE_PAID_CURRENCY_CD',
            'AdditionalFeesValue' => 'ADDITIONAL_FEES_VALUE', 'AdditionalFeesCurrency' => 'ADDITIONAL_FEES_CURRENCY_CD',
            'CustomsTaxPId' => 'CUSTOMS_TAX_PID', 'NetworkEntryLocationType' => 'NETWORK_ENTRY_LOCATION_TYPE_CD',
            'MrsStatus' => 'MRS_STATUS_CD', 'MrsExpirationDate' => 'MRS_EXPIRATION_DATE', 'MrsOriginalId' => 'MRS_ORIGINAL_ID',
        ];
        $parameters = array_fill_keys(config('ips-procedures.SP_SET_MAILITM'), null);
        foreach ($map as $parameter => $column) {
            $parameters[$parameter] = $item->$column ?? null;
        }
        if (! $item) {
            $parameters = array_replace($parameters, [
                'PId' => (string) Str::uuid(), 'FId' => $input['codigo'], 'Weight' => $input['weight_kg'],
                'MailClass' => $input['mail_class'], 'OrigCountry' => $input['origin_country'],
                'DestCountry' => $input['destination_country'], 'StateInd' => 0,
            ]);
        }

        return array_replace($parameters, [
            'StateInd' => $event->RESULT_STATE_IND_CD,
            'EventType' => (int) $event->EVENT_TYPE_CD,
            'EventGmtDt' => $date->copy()->utc()->toDateTimeString(),
            'EventLocalOffset' => $date->utcOffset() / 60,
            'Office' => (int) $input['office_cd'],
            'User' => (int) ($input['actor_user_pid'] ?? config('ips.user_pid')),
            'Workstation' => (int) config('ips.workstation_pid'),
            'NextOffice' => match ($input['event']) {
                'EDN' => (int) $input['next_office_cd'],
                'EIB' => isset($item->NEXT_OFFICE_CD) ? (int) $item->NEXT_OFFICE_CD : null,
                default => null,
            },
            'InnerBagPId' => $item?->EVT_INNRBAG_PID,
            'ReceptaclePId' => $input['receptacle_pid'] ?? $item?->EVT_RECPTCL_PID,
            'Condition' => isset($input['condition_cd']) ? (int) $input['condition_cd'] : null,
            'CustomsOffice' => $input['customs_office_cd'] ?? null,
            'CustomsOfficeType' => $input['customs_office_type_cd'] ?? null,
            'CustomsReleaseStatus' => $input['customs_release_status_cd'] ?? null,
            'DeliveryAgent' => isset($input['delivery_agent_cd']) ? strtoupper($input['delivery_agent_cd']) : null,
            'DelivInfoValid' => in_array($input['event'], ['EMI', 'EMH'], true) ? '1' : '0',
            'Signatory' => $input['event'] === 'EMI' ? ($input['signatory'] ?? null) : null,
            'DelivLocation' => $input['delivery_location'] ?? null,
            'NonDeliveryReason' => $input['event'] === 'EMH' ? $input['non_delivery_reason'] : null,
            'NonDeliveryMeasure' => $input['event'] === 'EMH' ? $input['non_delivery_measure'] : null,
        ]);
    }

    private function customerParameters(string $pid, string $indicator, array $customer): array
    {
        return array_replace(array_fill_keys(config('ips-procedures.SP_SET_MAILITM_CUSTOMERS'), null), [
            'MailItemPId' => $pid, 'SenderRecipientInd' => $indicator, 'Country' => $customer['country'],
            'Name' => $customer['name'], 'Address' => $customer['address'], 'City' => $customer['city'],
            'PostalCode' => $customer['postcode'] ?? null, 'PhoneNb' => $customer['phone'] ?? null,
            'Email' => $customer['email'] ?? null,
        ]);
    }
}
