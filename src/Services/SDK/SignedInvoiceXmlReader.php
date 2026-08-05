<?php

namespace Asas\Zatca\Services\SDK;

use DOMDocument;
use DOMXPath;

/**
 * Reads the QR code and Previous Invoice Hash embedded in a (signed or
 * unsigned) UBL invoice XML document. Extracted out of ZatcaSdkAdapter so
 * this XML-reading logic can be golden-file tested on its own, without
 * needing to run the SDK.
 */
class SignedInvoiceXmlReader
{
    private const CAC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private const CBC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    public function extractQrCode(string $xml): string
    {
        return $this->extractAdditionalDocumentReference($xml, 'QR') ?? '';
    }

    public function extractPih(string $xml): string
    {
        return $this->extractAdditionalDocumentReference($xml, 'PIH') ?? base64_encode('0');
    }

    private function extractAdditionalDocumentReference(string $xml, string $id): ?string
    {
        $dom = new DOMDocument;
        $dom->loadXML($xml);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cac', self::CAC_NS);
        $xpath->registerNamespace('cbc', self::CBC_NS);

        $nodes = $xpath->query("//cac:AdditionalDocumentReference[cbc:ID='{$id}']//cbc:EmbeddedDocumentBinaryObject");

        return $nodes->length > 0 ? trim($nodes->item(0)->textContent) : null;
    }
}
