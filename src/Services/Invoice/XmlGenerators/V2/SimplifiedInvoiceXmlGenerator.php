<?php

namespace Zatca\Services\Invoice\XmlGenerators\V2;

use Zatca\DTOs\EgsConfig;
use Zatca\DTOs\InvoiceSequenceReservation;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;

/**
 * Builds the unsigned UBL 2.1 Simplified Tax Invoice (Reporting model, B2C)
 * XML that the ZATCA SDK expects as its `-sign` input. Structure verified
 * against the SDK's own official sample (Data/Samples/Simplified/Invoice/
 * Simplified_Invoice.xml) — including the placeholder ext:UBLExtensions
 * signature block, the QR AdditionalDocumentReference and cac:Signature
 * stub, all of which the SDK overwrites in place during signing. Removing
 * those placeholders causes `-sign` to fail to locate the nodes it replaces.
 *
 * Versioned independently of the ZATCA API/connector version — this folder
 * moves only when ZATCA changes the UBL profile itself (config('zatca.
 * xml_profile_version')), never in lockstep with an API version bump.
 */
class SimplifiedInvoiceXmlGenerator
{
    private const SIGNATURE_PLACEHOLDER = <<<'XML'
<ext:UBLExtensions>
    <ext:UBLExtension>
        <ext:ExtensionURI>urn:oasis:names:specification:ubl:dsig:enveloped:xades</ext:ExtensionURI>
        <ext:ExtensionContent>
            <sig:UBLDocumentSignatures xmlns:sig="urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2" xmlns:sac="urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2" xmlns:sbc="urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2">
                <sac:SignatureInformation>
                    <cbc:ID>urn:oasis:names:specification:ubl:signature:1</cbc:ID>
                    <sbc:ReferencedSignatureID>urn:oasis:names:specification:ubl:signature:Invoice</sbc:ReferencedSignatureID>
                    <ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Id="signature">
                        <ds:SignedInfo>
                            <ds:CanonicalizationMethod Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>
                            <ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256"/>
                            <ds:Reference Id="invoiceSignedData" URI="">
                                <ds:Transforms>
                                    <ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116">
                                        <ds:XPath>not(//ancestor-or-self::ext:UBLExtensions)</ds:XPath>
                                    </ds:Transform>
                                    <ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116">
                                        <ds:XPath>not(//ancestor-or-self::cac:Signature)</ds:XPath>
                                    </ds:Transform>
                                    <ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116">
                                        <ds:XPath>not(//ancestor-or-self::cac:AdditionalDocumentReference[cbc:ID='QR'])</ds:XPath>
                                    </ds:Transform>
                                    <ds:Transform Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>
                                </ds:Transforms>
                                <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
                                <ds:DigestValue>PLACEHOLDER</ds:DigestValue>
                            </ds:Reference>
                            <ds:Reference Type="http://www.w3.org/2000/09/xmldsig#SignatureProperties" URI="#xadesSignedProperties">
                                <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
                                <ds:DigestValue>PLACEHOLDER</ds:DigestValue>
                            </ds:Reference>
                        </ds:SignedInfo>
                        <ds:SignatureValue>PLACEHOLDER</ds:SignatureValue>
                        <ds:KeyInfo>
                            <ds:X509Data>
                                <ds:X509Certificate>PLACEHOLDER</ds:X509Certificate>
                            </ds:X509Data>
                        </ds:KeyInfo>
                        <ds:Object>
                            <xades:QualifyingProperties xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" Target="signature">
                                <xades:SignedProperties Id="xadesSignedProperties">
                                    <xades:SignedSignatureProperties>
                                        <xades:SigningTime>2024-01-01T00:00:00</xades:SigningTime>
                                        <xades:SigningCertificate>
                                            <xades:Cert>
                                                <xades:CertDigest>
                                                    <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
                                                    <ds:DigestValue>PLACEHOLDER</ds:DigestValue>
                                                </xades:CertDigest>
                                                <xades:IssuerSerial>
                                                    <ds:X509IssuerName>PLACEHOLDER</ds:X509IssuerName>
                                                    <ds:X509SerialNumber>0</ds:X509SerialNumber>
                                                </xades:IssuerSerial>
                                            </xades:Cert>
                                        </xades:SigningCertificate>
                                    </xades:SignedSignatureProperties>
                                </xades:SignedProperties>
                            </xades:QualifyingProperties>
                        </ds:Object>
                    </ds:Signature>
                </sac:SignatureInformation>
            </sig:UBLDocumentSignatures>
        </ext:ExtensionContent>
    </ext:UBLExtension>
</ext:UBLExtensions>
XML;

    public function generate(
        ZatcaInvoiceData $invoiceData,
        EgsConfig $egsConfig,
        string $uuid,
        InvoiceSequenceReservation $reservation
    ): string {
        $lines = implode("\n", array_map(
            fn (int $i, ZatcaInvoiceLine $line) => $this->renderLine($i + 1, $line),
            array_keys($invoiceData->lines),
            $invoiceData->lines
        ));

        $taxCategories = $this->groupByVatRate($invoiceData->lines);
        $taxCategoryXml = implode("\n", array_map(fn (float $rate) => $this->renderTaxCategory($rate), array_keys($taxCategories)));
        $taxSubtotalXml = implode("\n", array_map(
            fn (float $rate, array $amounts) => $this->renderTaxSubtotal($rate, $amounts['taxable'], $amounts['vat']),
            array_keys($taxCategories),
            $taxCategories
        ));

        $buyerXml = $invoiceData->buyerName
            ? sprintf(
                "<cac:AccountingCustomerParty>\n    <cac:Party>\n        <cac:PartyLegalEntity>\n            <cbc:RegistrationName>%s</cbc:RegistrationName>\n        </cac:PartyLegalEntity>\n    </cac:Party>\n</cac:AccountingCustomerParty>",
                $this->esc($invoiceData->buyerName)
            )
            : "<cac:AccountingCustomerParty>\n</cac:AccountingCustomerParty>";

        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'.
            '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2">%s'.
            '<cbc:ProfileID>reporting:1.0</cbc:ProfileID>'.
            '<cbc:ID>%s</cbc:ID>'.
            '<cbc:UUID>%s</cbc:UUID>'.
            '<cbc:IssueDate>%s</cbc:IssueDate>'.
            '<cbc:IssueTime>%s</cbc:IssueTime>'.
            '<cbc:InvoiceTypeCode name="0200000">388</cbc:InvoiceTypeCode>'.
            '<cbc:DocumentCurrencyCode>%s</cbc:DocumentCurrencyCode>'.
            '<cbc:TaxCurrencyCode>%s</cbc:TaxCurrencyCode>'.
            '<cac:AdditionalDocumentReference><cbc:ID>ICV</cbc:ID><cbc:UUID>%d</cbc:UUID></cac:AdditionalDocumentReference>'.
            '<cac:AdditionalDocumentReference><cbc:ID>PIH</cbc:ID><cac:Attachment><cbc:EmbeddedDocumentBinaryObject mimeCode="text/plain">%s</cbc:EmbeddedDocumentBinaryObject></cac:Attachment></cac:AdditionalDocumentReference>'.
            '<cac:AdditionalDocumentReference><cbc:ID>QR</cbc:ID><cac:Attachment><cbc:EmbeddedDocumentBinaryObject mimeCode="text/plain">UExBQ0VIT0xERVI=</cbc:EmbeddedDocumentBinaryObject></cac:Attachment></cac:AdditionalDocumentReference>'.
            '<cac:Signature><cbc:ID>urn:oasis:names:specification:ubl:signature:Invoice</cbc:ID><cbc:SignatureMethod>urn:oasis:names:specification:ubl:dsig:enveloped:xades</cbc:SignatureMethod></cac:Signature>'.
            '<cac:AccountingSupplierParty><cac:Party>'.
            '<cac:PartyIdentification><cbc:ID schemeID="CRN">%s</cbc:ID></cac:PartyIdentification>'.
            '<cac:PostalAddress><cbc:StreetName>%s</cbc:StreetName><cbc:BuildingNumber>%s</cbc:BuildingNumber><cbc:CitySubdivisionName>%s</cbc:CitySubdivisionName><cbc:CityName>%s</cbc:CityName><cbc:PostalZone>%s</cbc:PostalZone><cac:Country><cbc:IdentificationCode>%s</cbc:IdentificationCode></cac:Country></cac:PostalAddress>'.
            '<cac:PartyTaxScheme><cbc:CompanyID>%s</cbc:CompanyID><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:PartyTaxScheme>'.
            '<cac:PartyLegalEntity><cbc:RegistrationName>%s</cbc:RegistrationName></cac:PartyLegalEntity>'.
            '</cac:Party></cac:AccountingSupplierParty>'.
            '%s'.
            '<cac:PaymentMeans><cbc:PaymentMeansCode>10</cbc:PaymentMeansCode></cac:PaymentMeans>'.
            '<cac:TaxTotal><cbc:TaxAmount currencyID="%s">%s</cbc:TaxAmount></cac:TaxTotal>'.
            '<cac:TaxTotal><cbc:TaxAmount currencyID="%s">%s</cbc:TaxAmount>%s</cac:TaxTotal>'.
            '<cac:LegalMonetaryTotal>'.
            '<cbc:LineExtensionAmount currencyID="%s">%s</cbc:LineExtensionAmount>'.
            '<cbc:TaxExclusiveAmount currencyID="%s">%s</cbc:TaxExclusiveAmount>'.
            '<cbc:TaxInclusiveAmount currencyID="%s">%s</cbc:TaxInclusiveAmount>'.
            '<cbc:AllowanceTotalAmount currencyID="%s">0.00</cbc:AllowanceTotalAmount>'.
            '<cbc:PrepaidAmount currencyID="%s">0.00</cbc:PrepaidAmount>'.
            '<cbc:PayableAmount currencyID="%s">%s</cbc:PayableAmount>'.
            '</cac:LegalMonetaryTotal>'.
            '%s'.
            '</Invoice>',
            self::SIGNATURE_PLACEHOLDER,
            $this->esc($invoiceData->sourceReference),
            $uuid,
            $invoiceData->issueDateTime->format('Y-m-d'),
            $invoiceData->issueDateTime->format('H:i:s'),
            $invoiceData->currency,
            $invoiceData->currency,
            $reservation->icv,
            $reservation->previousInvoiceHash,
            $this->esc((string) $egsConfig->commercialRegistrationNumber),
            // Structured national address. Each field falls back to the legacy
            // single-line ZATCA_LOCATION_ADDRESS (and, for the numeric fields,
            // to the placeholders this generator used before the structured
            // address existed) so a deployment that has not set the new config
            // keys still produces byte-identical XML.
            $this->esc((string) ($egsConfig->street ?: $egsConfig->locationAddress)),
            $this->esc((string) ($egsConfig->buildingNumber ?: '0000')),
            $this->esc((string) ($egsConfig->district ?: $egsConfig->locationAddress)),
            $this->esc((string) ($egsConfig->city ?: $egsConfig->locationAddress)),
            $this->esc((string) ($egsConfig->postalCode ?: '00000')),
            $this->esc($egsConfig->countryCode),
            $this->esc((string) $egsConfig->vatNumber),
            // Arabic legal name where configured — KSA tax invoices are
            // Arabic-first — falling back to the English one.
            $this->esc((string) $egsConfig->invoiceLegalName()),
            $buyerXml,
            $invoiceData->currency,
            $this->money($invoiceData->totalVat),
            $invoiceData->currency,
            $this->money($invoiceData->totalVat),
            $taxSubtotalXml,
            $invoiceData->currency,
            $this->money($invoiceData->totalExclVat),
            $invoiceData->currency,
            $this->money($invoiceData->totalExclVat),
            $invoiceData->currency,
            $this->money($invoiceData->totalInclVat),
            $invoiceData->currency,
            $invoiceData->currency,
            $invoiceData->currency,
            $this->money($invoiceData->totalInclVat),
            $lines,
        );
    }

    /** @return array<string,array{taxable:float,vat:float}> keyed by rate */
    private function groupByVatRate(array $lines): array
    {
        $groups = [];

        foreach ($lines as $line) {
            /** @var ZatcaInvoiceLine $line */
            $key = (string) $line->vatRate;
            $groups[$key]['taxable'] = ($groups[$key]['taxable'] ?? 0) + $line->lineTotalExclVat;
            $groups[$key]['vat'] = ($groups[$key]['vat'] ?? 0) + $line->vatAmount;
        }

        return $groups;
    }

    private function renderTaxCategory(float $rate): string
    {
        return sprintf(
            '<cac:TaxCategory><cbc:ID schemeID="UN/ECE 5305" schemeAgencyID="6">S</cbc:ID><cbc:Percent>%s</cbc:Percent><cac:TaxScheme><cbc:ID schemeID="UN/ECE 5153" schemeAgencyID="6">VAT</cbc:ID></cac:TaxScheme></cac:TaxCategory>',
            $rate
        );
    }

    private function renderTaxSubtotal(float $rate, float $taxable, float $vat): string
    {
        return sprintf(
            '<cac:TaxSubtotal><cbc:TaxableAmount currencyID="SAR">%s</cbc:TaxableAmount><cbc:TaxAmount currencyID="SAR">%s</cbc:TaxAmount><cac:TaxCategory><cbc:ID schemeID="UN/ECE 5305" schemeAgencyID="6">S</cbc:ID><cbc:Percent>%s</cbc:Percent><cac:TaxScheme><cbc:ID schemeID="UN/ECE 5153" schemeAgencyID="6">VAT</cbc:ID></cac:TaxScheme></cac:TaxCategory></cac:TaxSubtotal>',
            $this->money($taxable),
            $this->money($vat),
            $rate
        );
    }

    private function renderLine(int $id, ZatcaInvoiceLine $line): string
    {
        return sprintf(
            '<cac:InvoiceLine><cbc:ID>%d</cbc:ID><cbc:InvoicedQuantity unitCode="PCE">%d</cbc:InvoicedQuantity><cbc:LineExtensionAmount currencyID="SAR">%s</cbc:LineExtensionAmount>'.
            '<cac:TaxTotal><cbc:TaxAmount currencyID="SAR">%s</cbc:TaxAmount><cbc:RoundingAmount currencyID="SAR">%s</cbc:RoundingAmount></cac:TaxTotal>'.
            '<cac:Item><cbc:Name>%s</cbc:Name><cac:ClassifiedTaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>%s</cbc:Percent><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:ClassifiedTaxCategory></cac:Item>'.
            '<cac:Price><cbc:PriceAmount currencyID="SAR">%s</cbc:PriceAmount></cac:Price></cac:InvoiceLine>',
            $id,
            $line->quantity,
            $this->money($line->lineTotalExclVat),
            $this->money($line->vatAmount),
            $this->money($line->lineTotalInclVat()),
            $this->esc($line->description),
            $line->vatRate,
            $this->money($line->unitPrice)
        );
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
