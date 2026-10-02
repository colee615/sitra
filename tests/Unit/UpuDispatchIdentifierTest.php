<?php

namespace Tests\Unit;

use App\Services\Postal\UpuDispatchIdentifier;
use PHPUnit\Framework\TestCase;

class UpuDispatchIdentifierTest extends TestCase
{
    public function test_it_splits_an_s9_receptacle_code_into_dispatch_and_bag_fields(): void
    {
        $parsed = UpuDispatchIdentifier::parse('BEBRUABOLPBBAEN60073003000227');

        $this->assertSame('s9', $parsed['type']);
        $this->assertSame('BEBRUA', $parsed['origin_ctci']);
        $this->assertSame('BOLPBB', $parsed['destination_ctci']);
        $this->assertSame('A', $parsed['mail_category']);
        $this->assertSame('E', $parsed['mail_class']);
        $this->assertSame('N', $parsed['mail_subclass']);
        $this->assertSame('6', $parsed['year_digit']);
        $this->assertSame('0073', $parsed['dispatch_sequence']);
        $this->assertSame('BEBRUABOLPBBAEN60073', $parsed['dispatch_code']);
        $this->assertSame('003', $parsed['receptacle_sequence']);
        $this->assertSame(22.7, $parsed['encoded_gross_weight_kg']);
    }

    public function test_it_parses_an_s8_dispatch_code_and_accepts_grouped_input(): void
    {
        $parsed = UpuDispatchIdentifier::parse('BEBRUA BOLPBB AEN6 0073');

        $this->assertSame('s8', $parsed['type']);
        $this->assertSame('BEBRUABOLPBBAEN60073', $parsed['code']);
        $this->assertSame('0073', $parsed['dispatch_sequence']);
    }

    public function test_it_treats_the_special_weight_code_as_over_the_encoding_limit(): void
    {
        $parsed = UpuDispatchIdentifier::parse('BEBRUABOLPBBAEN60073001999999');

        $this->assertSame('s9', $parsed['type']);
        $this->assertNull($parsed['encoded_gross_weight_kg']);
        $this->assertStringContainsString('superior a 999,8 kg', $parsed['encoded_gross_weight_label']);
    }

    public function test_it_leaves_other_identifiers_for_the_existing_receptacle_search(): void
    {
        $this->assertNull(UpuDispatchIdentifier::parse('BAG-001'));
        $this->assertNull(UpuDispatchIdentifier::parse('BEBRUABOLPBBAEN6007300300022X'));
    }
}
