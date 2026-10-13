<?php

namespace Zatca\Services\Invoice\XmlGenerators\Concerns;

use Zatca\DTOs\ZatcaAllowanceCharge;
use Zatca\DTOs\ZatcaInvoiceLine;

/**
 * UBL rendering shared by every document profile (Simplified and Standard):
 * line items, per-rate tax subtotals, allowance charges, and the money/escape
 * helpers. Extracted from AbstractSimplifiedUblDocumentXmlGenerator (P22) so
 * AbstractStandardUblDocumentXmlGenerator can reuse it byte-for-byte instead
 * of drifting from it — these elements do not differ between the two models.
 */
trait RendersUblLinesAndTax
{
    /**
     * @param  array<ZatcaInvoiceLine>  $lines
     * @param  array<ZatcaAllowanceCharge>  $allowanceCharges  netted out of the
     *   category they share a rate with, so a category's TaxSubtotal always
     *   agrees with the document's own (already net-of-allowance) TaxAmount.
     * @return array<string,array{taxable:float,vat:float}>
     */
    private function groupByVatRate(array $lines, array $allowanceCharges = []): array
    {
        $groups = [];

        foreach ($lines as $line) {
            /** @var ZatcaInvoiceLine $line */
            $key = (string) $line->vatRate;
            $groups[$key]['taxable'] = ($groups[$key]['taxable'] ?? 0) + $line->lineTotalExclVat;
            $groups[$key]['vat'] = ($groups[$key]['vat'] ?? 0) + $line->vatAmount;
        }

        foreach ($allowanceCharges as $allowance) {
            /** @var ZatcaAllowanceCharge $allowance */
            $key = (string) $allowance->vatRate;
            $groups[$key]['taxable'] = ($groups[$key]['taxable'] ?? 0) - $allowance->amountExclVat;
            $groups[$key]['vat'] = ($groups[$key]['vat'] ?? 0) - $allowance->vatAmount;
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

    private function renderAllowanceCharge(ZatcaAllowanceCharge $allowance): string
    {
        return sprintf(
            '<cac:AllowanceCharge>'.
            '<cbc:ChargeIndicator>false</cbc:ChargeIndicator>'.
            '<cbc:AllowanceChargeReason>%s</cbc:AllowanceChargeReason>'.
            '<cbc:Amount currencyID="SAR">%s</cbc:Amount>'.
            '<cac:TaxCategory><cbc:ID schemeID="UN/ECE 5305" schemeAgencyID="6">S</cbc:ID><cbc:Percent>%s</cbc:Percent><cac:TaxScheme><cbc:ID schemeID="UN/ECE 5153" schemeAgencyID="6">VAT</cbc:ID></cac:TaxScheme></cac:TaxCategory>'.
            '</cac:AllowanceCharge>',
            $this->esc($allowance->reason),
            $this->money($allowance->amountExclVat),
            $allowance->vatRate
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
