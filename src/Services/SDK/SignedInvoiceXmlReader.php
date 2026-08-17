<?php

namespace Zatca\Services\SDK;

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

    /**
     * Returns the same XML with its PIH node replaced.
     *
     * Used when a draft invoice is signed later than it was built and the EGS hash
     * chain has moved on in the meantime: the document's other content is still
     * correct, but its previous-invoice-hash link now points at the wrong predecessor
     * and signing it unchanged would fork the chain.
     *
     * Deliberately surgical rather than a full XML rebuild. Only the one value that
     * must change is touched, so everything else — element order, whitespace, the
     * signature and QR placeholders the SDK locates by position — stays byte-identical.
     * It also means a draft with no recorded business snapshot can still be re-chained,
     * which a rebuild could not do.
     *
     * Returns the input unchanged if the document has no PIH node, so a caller can
     * never silently lose content by asking for a hash swap on the wrong document.
     */
    public function withPreviousInvoiceHash(string $xml, string $previousInvoiceHash): string
    {
        $dom = new DOMDocument;
        $dom->loadXML($xml);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cac', self::CAC_NS);
        $xpath->registerNamespace('cbc', self::CBC_NS);

        $nodes = $xpath->query("//cac:AdditionalDocumentReference[cbc:ID='PIH']//cbc:EmbeddedDocumentBinaryObject");

        if ($nodes->length === 0) {
            return $xml;
        }

        $nodes->item(0)->textContent = $previousInvoiceHash;

        return $dom->saveXML();
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
