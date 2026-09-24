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
}
