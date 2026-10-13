<?php

namespace Zatca\Services\Invoice\XmlGenerators\V2;

use Zatca\DTOs\BuyerParty;
use Zatca\DTOs\EgsConfig;
use Zatca\DTOs\InvoiceSequenceReservation;
use Zatca\DTOs\ZatcaAllowanceCharge;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Exceptions\ZatcaException;
use Zatca\Services\Invoice\XmlGenerators\Concerns\RendersUblLinesAndTax;
use Zatca\Services\Invoice\XmlGenerators\UblDocumentXmlGenerator;

/**
 * Shared UBL 2.1 (Clearance model, B2B) template for every Standard document
 * type — invoice, credit note and debit note only differ in
 * `cbc:InvoiceTypeCode` and, for the two note types, a `cac:BillingReference`
 * back to the invoice they adjust, exactly as for the Simplified profile.
 *
 * Structure verified against the SDK's own official Standard samples
 * (Data/Samples/Standard/{Invoice,Credit,Debit}/…): `cbc:ProfileID` stays
 * "reporting:1.0" (the same string ZATCA uses for both models — not a typo),
 * `cbc:InvoiceTypeCode` carries `name="0100000"` instead of "0200000", the
 * buyer is fully populated (PartyIdentification when there is no VAT number,
 * PostalAddress, PartyTaxScheme only when a VAT number exists, PartyLegalEntity),
 * and a `cac:Delivery/cbc:ActualDeliveryDate` carries the supply date (BR-KSA-15).
 * Everything else — lines, tax subtotals, allowances, the signature placeholder —
 * is identical to the Simplified profile and shared via RendersUblLinesAndTax.
 *
 * A Standard document requires a buyer (BR-KSA-63/67); generate() refuses one
 * without it rather than emit an invalid AccountingCustomerParty.
 */
abstract class AbstractStandardUblDocumentXmlGenerator implements UblDocumentXmlGenerator
{
    use RendersUblLinesAndTax;

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

    /** UBL `cbc:InvoiceTypeCode` value for this document type — 388 invoice, 381 credit note, 383 debit note. */
    abstract protected function invoiceTypeCode(): string;

    /**
     * `cac:BillingReference` XML, or '' when this document type does not carry one.
     * Invoice omits it; credit/debit notes must reference the invoice they adjust.
     */
    protected function billingReferenceXml(?string $billingReferenceId): string
    {
        return '';
    }

    /**
     * `cbc:InstructionNote` XML to place inside the existing `cac:PaymentMeans`
     * block, or '' to leave PaymentMeans exactly as it is for a plain invoice.
     * ZATCA business rule KSA-10 (BR-KSA-17) requires a credit/debit note
     * (InvoiceTypeCode 381/383) to state the reason it was issued at this
     * specific path — not a top-level `cbc:Note`.
     */
    protected function paymentMeansInstructionNoteXml(?string $reason): string
    {
        return '';
    }

    public function generate(
        ZatcaInvoiceData $invoiceData,
        EgsConfig $egsConfig,
        string $uuid,
        InvoiceSequenceReservation $reservation,
        ?string $billingReferenceId = null
    ): string {
        if (! $invoiceData->buyer) {
            throw new ZatcaException('A Standard (B2B) document needs a buyer (BR-KSA-63/67).');
        }

        $billingReferenceId = $invoiceData->billingReferenceId ?? $billingReferenceId;
        $lines = implode("\n", array_map(
            fn (int $i, ZatcaInvoiceLine $line) => $this->renderLine($i + 1, $line),
            array_keys($invoiceData->lines),
            $invoiceData->lines
        ));

        // BT-106 LineExtensionAmount is the raw sum of line net amounts —
        // pre-allowance. TaxExclusiveAmount is that sum minus allowances
        // (already what $invoiceData->totalExclVat is), which is why the two
        // are computed from different bases below.
        $lineExtensionSum = array_reduce(
            $invoiceData->lines,
            fn (float $carry, ZatcaInvoiceLine $line) => $carry + $line->lineTotalExclVat,
            0.0
        );

        $taxCategories = $this->groupByVatRate($invoiceData->lines, $invoiceData->allowanceCharges);
        $taxSubtotalXml = implode("\n", array_map(
            fn (float $rate, array $amounts) => $this->renderTaxSubtotal($rate, $amounts['taxable'], $amounts['vat']),
            array_keys($taxCategories),
            $taxCategories
        ));

        $allowanceChargeXml = implode("\n", array_map(
            fn (ZatcaAllowanceCharge $allowance) => $this->renderAllowanceCharge($allowance),
            $invoiceData->allowanceCharges
        ));
        $allowanceTotal = array_reduce(
            $invoiceData->allowanceCharges,
            fn (float $carry, ZatcaAllowanceCharge $allowance) => $carry + $allowance->amountExclVat,
            0.0
        );

        $deliveryXml = $invoiceData->supplyDate
            ? sprintf('<cac:Delivery><cbc:ActualDeliveryDate>%s</cbc:ActualDeliveryDate></cac:Delivery>', $invoiceData->supplyDate->format('Y-m-d'))
            : '';

        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'.
            '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2">%s'.
            '<cbc:ProfileID>reporting:1.0</cbc:ProfileID>'.
            '<cbc:ID>%s</cbc:ID>'.
            '<cbc:UUID>%s</cbc:UUID>'.
            '<cbc:IssueDate>%s</cbc:IssueDate>'.
            '<cbc:IssueTime>%s</cbc:IssueTime>'.
            '<cbc:InvoiceTypeCode name="0100000">%s</cbc:InvoiceTypeCode>'.
            '<cbc:DocumentCurrencyCode>%s</cbc:DocumentCurrencyCode>'.
            '<cbc:TaxCurrencyCode>%s</cbc:TaxCurrencyCode>'.
            '%s'.
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
            '%s'. // AccountingCustomerParty (buyer)
            '%s'. // Delivery
            '<cac:PaymentMeans><cbc:PaymentMeansCode>10</cbc:PaymentMeansCode>%s</cac:PaymentMeans>'.
            '%s'.
            '<cac:TaxTotal><cbc:TaxAmount currencyID="%s">%s</cbc:TaxAmount></cac:TaxTotal>'.
            '<cac:TaxTotal><cbc:TaxAmount currencyID="%s">%s</cbc:TaxAmount>%s</cac:TaxTotal>'.
            '<cac:LegalMonetaryTotal>'.
            '<cbc:LineExtensionAmount currencyID="%s">%s</cbc:LineExtensionAmount>'.
            '<cbc:TaxExclusiveAmount currencyID="%s">%s</cbc:TaxExclusiveAmount>'.
            '<cbc:TaxInclusiveAmount currencyID="%s">%s</cbc:TaxInclusiveAmount>'.
            '<cbc:AllowanceTotalAmount currencyID="%s">%s</cbc:AllowanceTotalAmount>'.
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
            $this->invoiceTypeCode(),
            $invoiceData->currency,
            $invoiceData->currency,
            $this->billingReferenceXml($billingReferenceId),
            $reservation->icv,
            $reservation->previousInvoiceHash,
            $this->esc((string) $egsConfig->commercialRegistrationNumber),
            $this->esc((string) ($egsConfig->street ?: $egsConfig->locationAddress)),
            $this->esc((string) ($egsConfig->buildingNumber ?: '0000')),
            $this->esc((string) ($egsConfig->district ?: $egsConfig->locationAddress)),
            $this->esc((string) ($egsConfig->city ?: $egsConfig->locationAddress)),
            $this->esc((string) ($egsConfig->postalCode ?: '00000')),
            $this->esc($egsConfig->countryCode),
            $this->esc((string) $egsConfig->vatNumber),
            $this->esc((string) $egsConfig->invoiceLegalName()),
            $this->renderBuyer($invoiceData->buyer),
            $deliveryXml,
            $this->paymentMeansInstructionNoteXml($invoiceData->adjustmentReason),
            $allowanceChargeXml,
            $invoiceData->currency,
            $this->money($invoiceData->totalVat),
            $invoiceData->currency,
            $this->money($invoiceData->totalVat),
            $taxSubtotalXml,
            $invoiceData->currency,
            $this->money($lineExtensionSum),
            $invoiceData->currency,
            $this->money($invoiceData->totalExclVat),
            $invoiceData->currency,
            $this->money($invoiceData->totalInclVat),
            $invoiceData->currency,
            $this->money($allowanceTotal),
            $invoiceData->currency,
            $invoiceData->currency,
            $this->money($invoiceData->totalInclVat),
            $lines,
        );
    }

    /**
     * `cac:AccountingCustomerParty` for a Standard document. PartyIdentification
     * (BT-46) is emitted only when the buyer has no VAT number — the SDK's own
     * Standard samples never carry both (Data/Samples/Standard/Invoice/Export
     * invoice.xml uses PartyIdentification alone; Standard_Invoice.xml uses
     * PartyTaxScheme alone). BR-KSA-81 requires exactly one of the two.
     */
    private function renderBuyer(BuyerParty $buyer): string
    {
        $identificationXml = $buyer->otherId
            ? sprintf('<cac:PartyIdentification><cbc:ID schemeID="%s">%s</cbc:ID></cac:PartyIdentification>', $this->esc($buyer->otherId->scheme), $this->esc($buyer->otherId->value))
            : '';

        $address = $buyer->address;
        $addressXml = sprintf(
            '<cac:PostalAddress>%s%s%s%s%s<cac:Country><cbc:IdentificationCode>%s</cbc:IdentificationCode></cac:Country></cac:PostalAddress>',
            $address->street !== null ? sprintf('<cbc:StreetName>%s</cbc:StreetName>', $this->esc($address->street)) : '',
            $address->buildingNumber !== null ? sprintf('<cbc:BuildingNumber>%s</cbc:BuildingNumber>', $this->esc($address->buildingNumber)) : '',
            $address->district !== null ? sprintf('<cbc:CitySubdivisionName>%s</cbc:CitySubdivisionName>', $this->esc($address->district)) : '',
            $address->city !== null ? sprintf('<cbc:CityName>%s</cbc:CityName>', $this->esc($address->city)) : '',
            $address->postalCode !== null ? sprintf('<cbc:PostalZone>%s</cbc:PostalZone>', $this->esc($address->postalCode)) : '',
            $this->esc($address->countryCode)
        );

        $taxSchemeXml = $buyer->vatNumber
            ? sprintf('<cac:PartyTaxScheme><cbc:CompanyID>%s</cbc:CompanyID><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:PartyTaxScheme>', $this->esc($buyer->vatNumber))
            : '';

        return sprintf(
            '<cac:AccountingCustomerParty><cac:Party>%s%s%s<cac:PartyLegalEntity><cbc:RegistrationName>%s</cbc:RegistrationName></cac:PartyLegalEntity></cac:Party></cac:AccountingCustomerParty>',
            $identificationXml,
            $addressXml,
            $taxSchemeXml,
            $this->esc($buyer->registrationName)
        );
    }
}
