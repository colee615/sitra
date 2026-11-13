<?php

namespace Tests\Unit;

use App\Support\IpsDeliveryReceipt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IpsDeliveryReceiptTest extends TestCase
{
    public static function histories(): array
    {
        return [
            'empty history' => [[], false],
            'received here' => [[[32, 18]], true],
            'received elsewhere' => [[[32, 17]], false],
            'pickup after receipt' => [[[75, 18], [32, 18]], true],
            'pickup without receipt' => [[[75, 18]], false],
            'courier after receipt' => [[[74, 18], [39, 18], [32, 18]], true],
            'redispatched after receipt' => [[[35, 18], [32, 18]], false],
            'returned from customs' => [[[38, 18], [31, 18], [32, 18]], false],
            'received after customs' => [[[32, 18], [38, 18]], true],
            'pickup at another office' => [[[75, 17], [32, 18]], false],
            'technical update' => [[[61, 18], [32, 18]], true],
        ];
    }

    #[DataProvider('histories')]
    public function test_receipt_must_belong_to_the_current_office_and_current_journey(array $history, bool $expected): void
    {
        $events = array_map(fn ($event) => ['EVENT_TYPE_CD' => $event[0], 'EVENT_OFFICE_CD' => $event[1]], $history);
        $this->assertSame($expected, IpsDeliveryReceipt::fromHistory($events, 18));
    }

    public function test_a_pickup_stage_alone_is_insufficient_but_verified_reception_allows_delivery(): void
    {
        $package = ['event_cd' => 75, 'state_cd' => 0, 'office_cd' => 18, 'destination_country' => 'BO'];
        $this->assertFalse(IpsDeliveryReceipt::confirmed($package, 18));
        $package['destination_receipt_confirmed'] = true;
        $this->assertTrue(IpsDeliveryReceipt::confirmed($package, 18));
        $this->assertFalse(IpsDeliveryReceipt::confirmed($package, 17));
    }
}
