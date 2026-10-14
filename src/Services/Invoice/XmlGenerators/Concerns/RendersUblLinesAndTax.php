<?php

namespace Zatca\Services\Invoice\XmlGenerators\Concerns;

use Zatca\DTOs\ZatcaAllowanceCharge;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Enums\ZatcaVatCategory;
use Zatca\Support\Money;

/**
 * UBL rendering shared by every document profile (Simplified and Standard):
 * line items, per-category tax subtotals, allowance charges, and the money/escape
 * helpers. Extracted from AbstractSimplifiedUblDocumentXmlGenerator (P22) so
 * AbstractStandardUblDocumentXmlGenerator can reuse it byte-for-byte instead
 * of drifting from it — these elements do not differ between the two models.
 *
 * Standard-rated (S) output is byte-identical to before VAT categories existed;
 * the golden fixtures pin that.
 */
trait RendersUblLinesAndTax
{
    /**
     * One VAT breakdown entry (BG-23) per distinct category + rate + exemption code.
     *
     * @param  array<ZatcaInvoiceLine>  $lines
     * @param  array<ZatcaAllowanceCharge>  $allowanceCharges  netted out of the
     *   category they share, so a category's TaxSubtotal always agrees with the
     *   document's own (already net-of-allowance) TaxAmount.
     * @return array<string,array{category:ZatcaVatCategory,rate:float,code:?string,reason:?string,taxable:float,vat:float}>
     */
    private function groupByTaxCategory(array $lines, array $allowanceCharges = []): array
    {
        $groups = [];

        foreach ($lines as $line) {
            /** @var ZatcaInvoiceLine $line */
            $key = $this->taxGroupKey($line->vatCategory, $line->vatRate, $line->vatExemptionReasonCode);
            $groups[$key] ??= $this->emptyTaxGroup($line->vatCategory, $line->vatRate, $line->vatExemptionReasonCode, $line->vatExemptionReason);
            $groups[$key]['taxable'] += $line->lineTotalExclVat;
            $groups[$key]['vat'] += $line->vatAmount;
        }

        foreach ($allowanceCharges as $allowance) {
            /** @var ZatcaAllowanceCharge $allowance */
            $key = $this->taxGroupKey($allowance->vatCategory, $allowance->vatRate, $allowance->vatExemptionReasonCode);
            $groups[$key] ??= $this->emptyTaxGroup($allowance->vatCategory, $allowance->vatRate, $allowance->vatExemptionReasonCode, $allowance->vatExemptionReason);
            $groups[$key]['taxable'] -= $allowance->amountExclVat;
            $groups[$key]['vat'] -= $allowance->vatAmount;
        }

        return $groups;
    }

    /** Standard-rated groups keep the plain rate as their key, exactly as before categories existed. */
    private function taxGroupKey(ZatcaVatCategory $category, float $rate, ?string $exemptionCode): string
    {
        return $category === ZatcaVatCategory::Standard
            ? (string) $rate
            : $category->value.'|'.$rate.'|'.$exemptionCode;
    }

    /** @return array{category:ZatcaVatCategory,rate:float,code:?string,reason:?string,taxable:float,vat:float} */
    private function emptyTaxGroup(ZatcaVatCategory $category, float $rate, ?string $exemptionCode, ?string $exemptionReason): array
    {
        return [
            'category' => $category,
            'rate' => $rate,
            'code' => $exemptionCode,
            'reason' => $exemptionReason,
            'taxable' => 0.0,
            'vat' => 0.0,
        ];
    }

    /**
     * BG-23 TaxCategory. The exemption code and text (BT-121/BT-120, BR-KSA-69) are
     * emitted only for zero-rated and exempt categories, and only in the VAT breakdown.
     */
    private function renderTaxCategory(ZatcaVatCategory $category, float $rate, ?string $exemptionCode = null, ?string $exemptionReason = null): string
    {
        $exemption = $category->requiresExemptionReason() && $exemptionCode !== null
            ? sprintf(
                '<cbc:TaxExemptionReasonCode>%s</cbc:TaxExemptionReasonCode><cbc:TaxExemptionReason>%s</cbc:TaxExemptionReason>',
                $this->esc($exemptionCode),
                $this->esc((string) $exemptionReason)
            )
            : '';

        return sprintf(
            '<cac:TaxCategory><cbc:ID schemeID="UN/ECE 5305" schemeAgencyID="6">%s</cbc:ID><cbc:Percent>%s</cbc:Percent>%s<cac:TaxScheme><cbc:ID schemeID="UN/ECE 5153" schemeAgencyID="6">VAT</cbc:ID></cac:TaxScheme></cac:TaxCategory>',
            $category->value,
            $rate,
            $exemption
        );
    }

    /** @param  array{category:ZatcaVatCategory,rate:float,code:?string,reason:?string,taxable:float,vat:float}  $group */
    private function renderTaxSubtotal(array $group): string
    {
        return sprintf(
            '<cac:TaxSubtotal><cbc:TaxableAmount currencyID="SAR">%s</cbc:TaxableAmount><cbc:TaxAmount currencyID="SAR">%s</cbc:TaxAmount>%s</cac:TaxSubtotal>',
            $this->money($group['taxable']),
            $this->money($group['vat']),
            $this->renderTaxCategory($group['category'], $group['rate'], $group['code'], $group['reason'])
        );
    }

    private function renderLine(int $id, ZatcaInvoiceLine $line): string
    {
        return sprintf(
            '<cac:InvoiceLine><cbc:ID>%d</cbc:ID><cbc:InvoicedQuantity unitCode="PCE">%d</cbc:InvoicedQuantity><cbc:LineExtensionAmount currencyID="SAR">%s</cbc:LineExtensionAmount>'.
            '<cac:TaxTotal><cbc:TaxAmount currencyID="SAR">%s</cbc:TaxAmount><cbc:RoundingAmount currencyID="SAR">%s</cbc:RoundingAmount></cac:TaxTotal>'.
            '<cac:Item><cbc:Name>%s</cbc:Name><cac:ClassifiedTaxCategory><cbc:ID>%s</cbc:ID><cbc:Percent>%s</cbc:Percent><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:ClassifiedTaxCategory></cac:Item>'.
            '<cac:Price><cbc:PriceAmount currencyID="SAR">%s</cbc:PriceAmount></cac:Price></cac:InvoiceLine>',
            $id,
            $line->quantity,
            $this->money($line->lineTotalExclVat),
            $this->money($line->vatAmount),
            $this->money($line->lineTotalInclVat()),
            $this->esc($line->description),
            $line->vatCategory->value,
            $line->vatRate,
            $this->price($line->netUnitPrice)
        );
    }

    /**
     * BT-146 net unit price. Two decimals when that is exact (every price that already was),
     * up to six otherwise, so InvoicedQuantity × PriceAmount reproduces the line net
     * (BR-KSA-EN16931-11) when VAT was taken out of an inclusive price. The SDK's rules put
     * no decimal limit on PriceAmount; the amounts themselves always have two.
     */
    private function price(float $value): string
    {
        return Money::trimmed(Money::toScaled($value, Money::PRICE_SCALE), Money::PRICE_SCALE);
    }

    private function renderAllowanceCharge(ZatcaAllowanceCharge $allowance): string
    {
        return sprintf(
            '<cac:AllowanceCharge>'.
            '<cbc:ChargeIndicator>false</cbc:ChargeIndicator>'.
            '<cbc:AllowanceChargeReason>%s</cbc:AllowanceChargeReason>'.
            '<cbc:Amount currencyID="SAR">%s</cbc:Amount>'.
            '%s'.
            '</cac:AllowanceCharge>',
            $this->esc($allowance->reason),
            $this->money($allowance->amountExclVat),
            $this->renderTaxCategory($allowance->vatCategory, $allowance->vatRate)
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
