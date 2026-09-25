<?php

namespace App\Services\Postal;

use DOMDocument;
use DOMElement;
use DOMXPath;

final class CdsXmlParser
{
    public function parse(?string $xml, string $rootName): array
    {
        if (!$xml) return ['status' => 'empty', 'fields' => [], 'pieces' => [], 'documents' => []];
        if (strlen($xml) > 2_000_000 || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            return ['status' => 'invalid', 'fields' => [], 'pieces' => [], 'documents' => []];
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS) || $document->documentElement?->localName !== $rootName) {
                return ['status' => 'invalid', 'fields' => [], 'pieces' => [], 'documents' => []];
            }
            $xpath = new DOMXPath($document);
            $pieces = [];
            foreach ($xpath->query('/*/*[local-name()="ContentPieces"]/*[local-name()="ContPc"]') as $piece) {
                $pieces[] = $this->attributes($piece);
            }
            $documents = [];
            foreach ($xpath->query('/*/*[local-name()="Documents"]/*') as $item) {
                $documents[] = ['type' => $item->localName, 'fields' => $this->attributes($item)];
            }
            return ['status' => 'ok', 'fields' => $this->attributes($document->documentElement), 'pieces' => $pieces, 'documents' => $documents];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function attributes(DOMElement $element): array
    {
        $fields = [];
        foreach ($element->attributes as $attribute) {
            $fields[$attribute->localName] = trim($attribute->value);
        }
        return $fields;
    }
}
