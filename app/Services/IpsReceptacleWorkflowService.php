<?php

namespace App\Services;

use App\Exceptions\IpsOperationException;
use Illuminate\Support\Carbon;

class IpsReceptacleWorkflowService
{
    public function __construct(private readonly IpsRepository $ips) {}

    public function execute(array $input): array
    {
        $this->ips->assertReady();

        return $this->ips->connection()->transaction(function () use ($input) {
            $this->ips->lockReceptacleCode($input['codigo']);
            $receptacle = $this->ips->findReceptacle($input['codigo'], true);
            if (! $receptacle) {
                throw new IpsOperationException('Envase no encontrado en IPS.', 404);
            }

            $definition = config('ips.receptacle_events.'.$input['event']);
            $event = $definition ? $this->ips->receptacleEvent((int) $definition['id']) : null;
            if (! $definition || ! $event
                || trim((string) $event->INB_OTB_IND) !== $definition['direction']
                || (int) $event->RESULT_STATE_IND_CD !== (int) $definition['result_state']) {
                throw new IpsOperationException('El catálogo IPS no coincide con el evento de envase configurado.', 503);
            }

            $office = (int) $input['office_cd'];
            if (! $this->ips->ownOffice($office)
                || ! in_array($office, $this->ips->exchangeOfficeCds(), true)) {
                throw new IpsOperationException('La oficina no está habilitada como oficina de cambio.', 422);
            }
            if (isset($input['actor_user_pid'])) {
                $actor = $this->ips->user((int) $input['actor_user_pid']);
                if ((int) ($actor['office_cd'] ?? 0) !== $office) {
                    throw new IpsOperationException('La oficina no corresponde al usuario IPS vinculado.', 403);
                }
            }

            $current = $this->ips->receptacleDetail($input['codigo'], $office)['receptacle'];
            if (! in_array($input['event'], $current['allowed_actions'], true)) {
                throw new IpsOperationException('La etapa u oficina actual no habilita este movimiento para el envase.', 409);
            }
            if ((int) $receptacle->EVT_TYPE_CD !== (int) $input['expected_event_cd']
                || ! Carbon::parse($receptacle->EVT_GMT_DT, 'UTC')->equalTo(Carbon::parse($input['expected_event_at']))) {
                throw new IpsOperationException('El estado del envase cambió desde la consulta. Actualice antes de operar.', 409);
            }
            if (! $this->ips->compatible((int) $receptacle->STATE_IND_CD, (int) $event->EVENT_TYPE_CD)) {
                throw new IpsOperationException('La transición no está permitida por el catálogo de estados IPS.', 409);
            }
            if ($input['event'] === 'RECEPTACLE_RECEIVE' && empty($input['physical_receipt_confirmed'])) {
                throw new IpsOperationException('Confirme que la saca llegó físicamente a esta oficina.', 422);
            }

            $occurredAt = Carbon::parse($input['occurred_at']);
            if ($occurredAt->lessThanOrEqualTo(Carbon::parse($receptacle->EVT_GMT_DT, 'UTC'))) {
                throw new IpsOperationException('El evento debe ser posterior al último movimiento del envase.', 422);
            }
            $workstation = $this->ips->workstation((int) config('ips.workstation_pid'));
            $parameters = $this->parameters($receptacle, $event, $input, $occurredAt, $workstation);
            $this->ips->callProcedure('SP_SET_RECPTCL', $parameters);
            $eventDate = $occurredAt->copy()->utc()->toDateTimeString();
            $this->ips->verifyReceptacleWrite(
                (string) $receptacle->RECPTCL_PID,
                (int) $event->EVENT_TYPE_CD,
                $eventDate,
                (int) $event->RESULT_STATE_IND_CD,
                $office
            );

            return [
                'codigo' => trim((string) $receptacle->RECPTCL_FID),
                'receptacle_pid' => (string) $receptacle->RECPTCL_PID,
                'event' => $input['event'],
                'event_cd' => (int) $event->EVENT_TYPE_CD,
                'event_at' => $occurredAt->copy()->utc()->format('Y-m-d\\TH:i:sP'),
                'office_cd' => $office,
                'actor_user_pid' => (int) ($input['actor_user_pid'] ?? config('ips.user_pid')),
                'workstation_pid' => (int) config('ips.workstation_pid'),
            ];
        }, 1);
    }

    private function parameters(object $row, object $event, array $input, Carbon $occurredAt, object $workstation): array
    {
        $source = [
            'PId' => 'RECPTCL_PID', 'FId' => 'RECPTCL_FID', 'PostalStatus' => 'POSTAL_STATUS_CD',
            'DispatchPId' => 'DESPTCH_PID', 'MailSubClass' => 'MAIL_SUBCLASS_FCD', 'Weight' => 'RECPTCL_WEIGHT',
            'MailItemsNo' => 'RECPTCL_MAILITMS_NO', 'RegNo' => 'RECPTCL_REG_NO', 'InsNo' => 'RECPTCL_INS_NO',
            'ReceptacleContentFormat' => 'RECPTCL_CONTENT_FORMAT_CD', 'ReceptacleCharacteristic' => 'RECPTCL_CHARACTERISTIC_CD',
            'ReceptacleSubType' => 'RECPTCL_SUB_TYPE_CD', 'TypeWeight' => 'RECPTCL_TYPE_WEIGHT',
            'ReceptacleOwner' => 'RECPTCL_OWNERSHIP_CD', 'SealNo' => 'RECPTCL_SEAL_NUMBER',
            'ReceptacleSendingCustomer' => 'RECPTCL_SENDING_CUSTOMER_CD', 'Comments' => 'COMMENTS',
            'DocumentationEnclosed' => 'DOCUMENTATION_ENCLOSED', 'ReceptacleCustomerNo' => 'RECPTCL_CUSTOMER_NUMBER',
            'ProductType' => 'PRODUCT_TYPE_CD', 'ConsignmentPId' => 'EVT_CONSGNT_PID', 'ContainerPId' => 'EVT_CONTNER_PID',
        ];
        $parameters = array_fill_keys(config('ips-procedures.SP_SET_RECPTCL'), null);
        foreach ($source as $parameter => $column) {
            $parameters[$parameter] = $row->$column ?? null;
        }
        $parameters = array_replace($parameters, [
            'StateInd' => (int) $event->RESULT_STATE_IND_CD,
            'LockUser' => null,
            'LockWorkstation' => null,
            'EventType' => (int) $event->EVENT_TYPE_CD,
            'EventGmtDt' => $occurredAt->copy()->utc()->toDateTimeString(),
            'EventLocalOffset' => $occurredAt->utcOffset() / 60,
            'Office' => (int) $input['office_cd'],
            'User' => (int) ($input['actor_user_pid'] ?? config('ips.user_pid')),
            'Workstation' => (int) config('ips.workstation_pid'),
            'Condition' => null,
            'NextOffice' => null,
            'SecurityCheckPId' => null,
            'WORKSTATION_FID' => trim((string) $workstation->WORKSTATION_FID),
        ]);

        return $parameters;
    }
}
