<?php

namespace Tests\Unit;

use App\Services\Postal\CdsXmlParser;
use App\Services\Postal\ShipmentCode;
use PHPUnit\Framework\TestCase;

class PostalDataTest extends TestCase
{
    public function test_s10_checksum_and_local_identifiers(): void
    {
        $this->assertTrue(ShipmentCode::describe('EC253990191BE')['valid']);
        $this->assertFalse(ShipmentCode::describe('EC253990192BE')['valid']);
        $this->assertNull(ShipmentCode::describe('EN000005100TDD')['valid']);
        $this->assertSame('EC253990191BE', ShipmentCode::normalize(' ec253990191be '));
    }

    public function test_cds_attributes_and_namespaces_are_read_without_inventing_fields(): void
    {
        $data = (new CdsXmlParser)->parse('<DecData xmlns="urn:cds" SNm="Remitente" GWgt="0.5"><ContentPieces><ContPc No="2" Desc="Libros" Amt="10" Cur="USD"/></ContentPieces><Documents><Doc Ref="123"/></Documents></DecData>', 'DecData');
        $this->assertSame('ok', $data['status']);
        $this->assertSame('Remitente', $data['fields']['SNm']);
        $this->assertSame('Libros', $data['pieces'][0]['Desc']);
        $this->assertArrayNotHasKey('RNm', $data['fields']);
        $this->assertSame('123', $data['documents'][0]['fields']['Ref']);
    }

    public function test_malformed_external_entities_and_wrong_document_type_are_rejected(): void
    {
        $parser = new CdsXmlParser;
        foreach (['<DecData>', '<ResData/>', '<!DOCTYPE DecData [<!ENTITY x SYSTEM "file:///etc/passwd">]><DecData>&x;</DecData>'] as $xml) {
            $this->assertSame('invalid', $parser->parse($xml, 'DecData')['status']);
        }
        $this->assertSame('empty', $parser->parse(null, 'DecData')['status']);
    }
}
