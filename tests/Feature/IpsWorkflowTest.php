<?php

namespace Tests\Feature;

use App\Exceptions\IpsOperationException;
use App\Services\IpsRepository;
use App\Services\IpsWorkflowService;
use Illuminate\Database\Connection;
use Mockery;
use Tests\TestCase;

class IpsWorkflowTest extends TestCase
{
    private function repository(?object $item = null, string $code = 'EMI', bool $terminal = false, bool $compatible = true): IpsRepository
    {
        $repo = Mockery::mock(IpsRepository::class);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('transaction')->once()->andReturnUsing(fn ($callback) => $callback());
        $repo->shouldReceive('connection')->andReturn($connection);
        $repo->shouldReceive('assertReady')->once();
        $repo->shouldReceive('lockCode')->once()->with('TEST01');
        $repo->shouldReceive('find')->once()->with('TEST01', true)->andReturn($item);
        $definition = config('ips.events.'.$code);
        $repo->shouldReceive('event')->once()->with($definition['id'])->andReturn((object) [
            'EVENT_TYPE_CD' => $definition['id'], 'INB_OTB_IND' => $definition['direction'],
            'RESULT_STATE_IND_CD' => $code === 'EMI' ? 5 : 0,
        ]);
        $repo->shouldReceive('reference')->andReturn(true);
        $repo->shouldReceive('eventExists')->andReturn($terminal);
        $repo->shouldReceive('compatible')->andReturn($compatible);

        return $repo;
    }

    private function item(array $changes = []): object
    {
        return (object) array_replace([
            'MAILITM_PID' => '11111111-1111-4111-8111-111111111111', 'MAILITM_FID' => 'TEST01',
            'MAILITM_LOCAL_ID' => null, 'STATE_IND_CD' => 0, 'EVT_TYPE_CD' => 75,
            'EVT_GMT_DT' => '2026-01-01 12:00:00', 'EVT_OFFICE_CD' => 1, 'DEST_COUNTRY_CD' => 'BO',
            'ORIG_COUNTRY_CD' => 'US', 'MAILITM_WEIGHT' => '1.250', 'MAIL_CLASS_CD' => 'C',
            'EVT_INNRBAG_PID' => null, 'EVT_RECPTCL_PID' => null,
        ], $changes);
    }

    private function input(array $changes = []): array
    {
        return array_replace([
            'codigo' => 'TEST01', 'event' => 'EMI', 'office_cd' => 1, 'signatory' => 'Receptor',
            'occurred_at' => '2026-01-02T12:00:00-04:00', 'expected_event_cd' => 75,
            'expected_event_at' => '2026-01-01T12:00:00+00:00',
        ], $changes);
    }

    public function test_delivery_uses_ips_procedure_preserves_metadata_and_converts_to_utc(): void
    {
        $repo = $this->repository($this->item());
        $repo->shouldReceive('callProcedure')->once()->with('SP_SET_MAILITM', Mockery::on(fn ($p) => $p['EventType'] === 37 && $p['EventGmtDt'] === '2026-01-02 16:00:00' &&
            $p['EventLocalOffset'] == -4 && $p['Signatory'] === 'Receptor' &&
            $p['Weight'] === '1.250' && $p['DelivInfoValid'] === '1'
        ));
        $repo->shouldReceive('verifyWrite')->once()->with('11111111-1111-4111-8111-111111111111', 37, '2026-01-02 16:00:00', 'Receptor');
        $result = (new IpsWorkflowService($repo))->execute('event', $this->input());
        $this->assertSame(37, $result['event_cd']);
        $this->assertSame('2026-01-02T16:00:00+00:00', $result['event_at']);
    }

    public function test_existing_delivery_blocks_another_delivery(): void
    {
        $repo = $this->repository($this->item(), terminal: true);
        $repo->shouldNotReceive('callProcedure');
        $this->expectException(IpsOperationException::class);
        (new IpsWorkflowService($repo))->execute('event', $this->input());
    }

    public function test_stale_expected_state_blocks_write(): void
    {
        $repo = $this->repository($this->item(['EVT_TYPE_CD' => 74]));
        $repo->shouldNotReceive('callProcedure');
        $this->expectExceptionMessage('El estado cambió');
        (new IpsWorkflowService($repo))->execute('event', $this->input());
    }

    public function test_old_timestamp_blocks_write(): void
    {
        $repo = $this->repository($this->item());
        $repo->shouldNotReceive('callProcedure');
        $this->expectExceptionMessage('posterior');
        (new IpsWorkflowService($repo))->execute('event', $this->input(['occurred_at' => '2025-12-31T12:00:00+00:00']));
    }

    public function test_customs_or_transit_is_not_eligible_for_delivery(): void
    {
        $repo = $this->repository($this->item(['EVT_TYPE_CD' => 31]));
        $repo->shouldNotReceive('callProcedure');
        $this->expectExceptionMessage('etapa habilitada');
        (new IpsWorkflowService($repo))->execute('event', $this->input(['expected_event_cd' => 31]));
    }

    public function test_delivery_in_another_office_is_rejected(): void
    {
        $repo = $this->repository($this->item());
        $repo->shouldNotReceive('callProcedure');
        $this->expectExceptionMessage('oficina actual');
        (new IpsWorkflowService($repo))->execute('event', $this->input(['office_cd' => 2]));
    }

    public function test_ips_state_compatibility_is_enforced(): void
    {
        $repo = $this->repository($this->item(), compatible: false);
        $repo->shouldNotReceive('callProcedure');
        $this->expectExceptionMessage('catálogo de estados');
        (new IpsWorkflowService($repo))->execute('event', $this->input());
    }

    public function test_failed_delivery_keeps_reason_and_does_not_report_emi(): void
    {
        $repo = $this->repository($this->item(), 'EMH');
        $repo->shouldReceive('callProcedure')->once()->with('SP_SET_MAILITM', Mockery::on(fn ($p) => $p['EventType'] === 36 && $p['Signatory'] === null && $p['NonDeliveryReason'] === 1 && $p['NonDeliveryMeasure'] === 'A'
        ));
        $repo->shouldReceive('verifyWrite')->once();
        $result = (new IpsWorkflowService($repo))->execute('event', $this->input(['event' => 'EMH', 'non_delivery_reason' => 1, 'non_delivery_measure' => 'A']));
        $this->assertSame(36, $result['event_cd']);
    }

    public function test_new_package_creates_both_customer_records(): void
    {
        $repo = $this->repository(code: 'EMA');
        $repo->shouldReceive('callProcedure')->once()->with('SP_SET_MAILITM', Mockery::on(fn ($p) => $p['FId'] === 'TEST01' && $p['EventType'] === 1 && $p['OrigCountry'] === 'BO'
        ));
        $repo->shouldReceive('callProcedure')->once()->with('SP_SET_MAILITM_CUSTOMERS', Mockery::on(fn ($p) => $p['SenderRecipientInd'] === 'S'));
        $repo->shouldReceive('callProcedure')->once()->with('SP_SET_MAILITM_CUSTOMERS', Mockery::on(fn ($p) => $p['SenderRecipientInd'] === 'A'));
        $repo->shouldReceive('verifyWrite')->once();
        $customer = ['name' => 'Prueba', 'address' => 'Calle', 'city' => 'Ciudad', 'country' => 'BO'];
        $result = (new IpsWorkflowService($repo))->execute('create', $this->input([
            'event' => 'EMA', 'mail_class' => 'C', 'origin_country' => 'BO', 'destination_country' => 'US',
            'weight_kg' => 1.25, 'sender' => $customer, 'recipient' => $customer,
        ]));
        $this->assertSame(1, $result['event_cd']);
    }
}
