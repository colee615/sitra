<?php

namespace App\Services;

use App\Exceptions\IpsOperationException;
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
            $definition = config('ips.events.'.$input['event']);
            if (! $definition) {
                throw new IpsOperationException('Evento operativo no permitido.', 422);
            }
            $event = $this->ips->event($definition['id']);
            if (! $event || trim($event->INB_OTB_IND) !== $definition['direction']) {
                throw new IpsOperationException('El catálogo IPS no coincide con la configuración.', 503);
            }
            $this->validateTransition($action, $input, $item, $event, $definition);
            $date = Carbon::parse($input['occurred_at']);
            $parameters = $this->mailParameters($item, $input, $event, $date);
            $this->ips->callProcedure('SP_SET_MAILITM', $parameters);
            if ($action === 'create') {
                foreach (['sender' => 'S', 'recipient' => 'A'] as $field => $indicator) {
                    $this->ips->callProcedure('SP_SET_MAILITM_CUSTOMERS', $this->customerParameters($parameters['PId'], $indicator, $input[$field]));
                }
            }
            $this->ips->verifyWrite($parameters['PId'], (int) $event->EVENT_TYPE_CD, $date->copy()->utc()->toDateTimeString(), $input['signatory'] ?? null);

            return [
                'codigo' => trim($parameters['FId']),
                'local_id' => trim($parameters['LocalId'] ?? ''),
                'mailitm_pid' => $parameters['PId'],
                'event' => $input['event'],
                'event_cd' => (int) $event->EVENT_TYPE_CD,
                'event_at' => $date->copy()->utc()->format('Y-m-d\TH:i:sP'),
                'office_cd' => (int) $input['office_cd'],
            ];
        }, 1); // No automatic transaction retry across IPS procedures.
    }

    private function validateTransition(string $action, array $input, ?object $item, object $event, array $definition): void
    {
        if (! $this->ips->reference('N_OWN_OFFICES', 'OWN_OFFICE_CD', $input['office_cd'])) {
            throw new IpsOperationException('La oficina no está activa en IPS.', 422);
        }
        if ($action === 'create') {
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
        if ($definition['direction'] === 'I' && trim($item->DEST_COUNTRY_CD ?? '') !== config('ips.destination_country')) {
            throw new IpsOperationException('Este flujo de entrega solo opera envíos con destino Bolivia.');
        }
        if ($input['event'] === 'EMI') {
            if (! in_array((int) $item->EVT_TYPE_CD, config('ips.delivery_candidate_events'), true)) {
                throw new IpsOperationException('El paquete no está en una etapa habilitada para entrega.');
            }
            if ((int) $item->EVT_OFFICE_CD !== (int) $input['office_cd']) {
                throw new IpsOperationException('La entrega debe registrarse en la oficina actual del paquete.');
            }
        }
        if ($input['event'] === 'EMH') {
            if (! $this->ips->reference('C_NON_DELIVERY_REASONS', 'NON_DELIVERY_REASON_CD', $input['non_delivery_reason']) ||
                ! $this->ips->reference('C_NON_DELIVERY_MEASURES', 'NON_DELIVERY_MEASURE_CD', $input['non_delivery_measure'])) {
                throw new IpsOperationException('Motivo o medida de entrega fallida inválidos.', 422);
            }
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
            'EventType' => (int) $event->EVENT_TYPE_CD,
            'EventGmtDt' => $date->copy()->utc()->toDateTimeString(),
            'EventLocalOffset' => $date->utcOffset() / 60,
            'Office' => (int) $input['office_cd'],
            'User' => (int) config('ips.user_pid'),
            'Workstation' => (int) config('ips.workstation_pid'),
            'InnerBagPId' => $item?->EVT_INNRBAG_PID,
            'ReceptaclePId' => $item?->EVT_RECPTCL_PID,
            'DelivInfoValid' => in_array($input['event'], ['EMI', 'EMH'], true) ? '1' : '0',
            'Signatory' => $input['event'] === 'EMI' ? $input['signatory'] : null,
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
