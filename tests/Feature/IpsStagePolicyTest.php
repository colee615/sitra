<?php

namespace Tests\Feature;

use App\Services\IpsStagePolicy;
use Tests\TestCase;

class IpsStagePolicyTest extends TestCase
{
    public function test_state_zero_does_not_mean_counter_and_customs_return_does_not_mean_dispatch(): void
    {
        $p = new IpsStagePolicy;
        $this->assertSame('exchange', $p->describe(30, 0)['key']);
        $this->assertSame('customs_return', $p->describe(38, 0)['key']);
        $this->assertSame('received', $p->describe(32, 0)['key']);
        $this->assertSame('delivery', $p->describe(74, 0)['key']);
        $this->assertSame('pickup', $p->describe(75, 0)['key']);
        $this->assertSame('customs', $p->describe(32, 1)['key']);
    }

    public function test_receipt_and_delivery_have_different_prerequisites(): void
    {
        $p = new IpsStagePolicy;
        $item = ['event_cd'=>30, 'state_cd'=>0, 'office_cd'=>1,'destination_country'=>'BO'];
        $this->assertSame(['EMG'], $p->actions($item, 1));
        $this->assertSame([], $p->actions($item, 2));
        $item['event_cd'] = 35;
        $this->assertSame([], $p->actions($item, 1));
        $item['next_office_cd'] = 2;
        $this->assertSame(['EMG'], $p->actions($item, 2));
        $item['event_cd'] = 32;
        $this->assertContains('EMI', $p->actions($item, 1));
        $this->assertNotContains('EMG', $p->actions($item, 1));
        foreach ([1,4,5,7] as $state) {
            $this->assertSame([], $p->actions(array_replace($item,['state_cd'=>$state]), 1));
        }
        $this->assertSame([], $p->actions($item + ['terminal'=>true],1));
        $this->assertSame([], $p->actions(array_replace($item,['destination_country'=>'US']),1));
    }

    public function test_international_route_and_last_mile_actions_follow_the_current_stage(): void
    {
        $policy = new IpsStagePolicy;
        $package = [
            'event_cd' => 1, 'operational_event_cd' => 1, 'state_cd' => 0,
            'office_cd' => 10, 'operational_office_cd' => 10,
            'origin_country' => 'MY', 'destination_country' => 'BO',
            'exchange_office_cds' => [10], 'terminal' => false,
        ];
        $this->assertSame(['EMR'], $policy->actions($package, 10));
        $this->assertSame([], $policy->actions($package, 20));

        $package = array_replace($package, ['event_cd' => 30, 'operational_event_cd' => 30]);
        $this->assertSame(['EDN', 'CUSTOMS_PRESENT'], $policy->actions($package, 10));
        $package = array_replace($package, [
            'event_cd' => 35, 'operational_event_cd' => 35, 'next_office_cd' => 20,
        ]);
        $this->assertSame(['EIB'], $policy->actions($package, 10));

        $package = array_replace($package, [
            'event_cd' => 13, 'operational_event_cd' => 13, 'state_cd' => 6,
            'receipt_office_cds' => [20],
        ]);
        $this->assertSame(['EMG'], $policy->actions($package, 20));
        $this->assertSame([], $policy->actions($package, 30));

        $package = array_replace($package, [
            'event_cd' => 32, 'operational_event_cd' => 32, 'state_cd' => 0,
            'office_cd' => 20, 'operational_office_cd' => 20,
        ]);
        $this->assertSame(['EDH', 'HANDOVER_AGENT', 'EMI'], $policy->actions($package, 20));
        $package = array_replace($package, ['event_cd' => 74, 'operational_event_cd' => 74]);
        $this->assertSame(['EMI', 'EMH'], $policy->actions($package, 20));
        $package = array_replace($package, ['event_cd' => 36, 'operational_event_cd' => 36]);
        $this->assertSame(['HANDOVER_AGENT'], $policy->actions($package, 20));
    }
}
