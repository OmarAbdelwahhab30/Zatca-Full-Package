<?php

namespace Zatca\Services\Invoice;

use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\Exceptions\ZatcaException;

/**
 * The last gate before an invoice becomes a document.
 *
 * Once XML is generated the invoice has consumed an ICV slot, and once it is
 * signed its hash is committed to the EGS chain — neither is retractable. So the
 * cheapest possible moment to reject nonsense is before either happens, which is
 * where this runs.
 *
 * It checks the arithmetic ZATCA itself checks, plus the one thing ZATCA does not
 * have to say out loud: a simplified tax invoice line cannot be negative. A
 * negative line is not a rounding problem, it is the visible end of a pricing
 * fault upstream — the host's price distribution reconciling a discount it could
 * not re-validate, for instance. Signing it would file a fiscal document claiming
 * the seller owes tax back on a sale that collected money.
 *
 * Deliberately arithmetic only. Nothing here validates ZATCA's structural or
 * business rules for the document as a whole; that is the SDK's job and it is done
 * against real XML. This exists to stop a document whose OWN numbers do not add
 * up from ever reaching it.
 */
class InvoiceDataValidator
{
    /**
     * Half a fil. Every amount reaching here is already rounded to two decimals,
     * so any real disagreement is at least one fil and any difference below this
     * is float representation, not money.
     */
    private const EPSILON = 0.005;

    /** @throws ZatcaException when the invoice's own figures do not reconcile. */
    public function validate(ZatcaInvoiceData $data): void
    {
        if ($data->lines === []) {
            throw new ZatcaException(
                "[ZATCA] Invoice [{$data->sourceReference}] has no lines, so there is nothing to invoice."
            );
        }

        $net = 0.0;
        $vat = 0.0;

        foreach ($data->lines as $position => $line) {
            $number = $position + 1;

            if ($line->quantity < 1) {
                throw new ZatcaException(
                    "[ZATCA] Invoice [{$data->sourceReference}] line {$number} has quantity {$line->quantity}."
                );
            }

            if ($line->lineTotalExclVat < 0 || $line->vatAmount < 0 || $line->unitPrice < 0) {
                throw new ZatcaException(
                    "[ZATCA] Invoice [{$data->sourceReference}] line {$number} is negative (net "
                    .$this->format($line->lineTotalExclVat).', VAT '.$this->format($line->vatAmount).', unit price '
                    .$this->format($line->unitPrice).'). A negative line means the amounts fed to this invoice do '
                    .'not describe the sale, so no document is generated for it.'
                );
            }

            if ($line->vatRate < 0) {
                throw new ZatcaException(
                    "[ZATCA] Invoice [{$data->sourceReference}] line {$number} has a negative VAT rate "
                    ."({$line->vatRate})."
                );
            }

            $net += $line->lineTotalExclVat;
            $vat += $line->vatAmount;
        }

        // BR-CO-10: the document's line extension amount is the sum of its lines.
        $this->assertEqual(
            round($net, 2),
            $data->totalExclVat,
            $data->sourceReference,
            'BR-CO-10: the sum of the invoice lines does not equal TaxExclusiveAmount'
        );

        // BR-CO-14: the document's VAT total is the sum of the line VAT amounts.
        $this->assertEqual(
            round($vat, 2),
            $data->totalVat,
            $data->sourceReference,
            'BR-CO-14: the sum of the line VAT amounts does not equal the invoice VAT total'
        );

        // BR-CO-15: tax exclusive + VAT = tax inclusive, which is also BT-115 Payable.
        $this->assertEqual(
            round($data->totalExclVat + $data->totalVat, 2),
            $data->totalInclVat,
            $data->sourceReference,
            'BR-CO-15: TaxExclusiveAmount plus the VAT total does not equal TaxInclusiveAmount'
        );

        if ($data->totalExclVat < 0 || $data->totalVat < 0 || $data->totalInclVat < 0) {
            throw new ZatcaException(
                "[ZATCA] Invoice [{$data->sourceReference}] has a negative total (excl "
                .$this->format($data->totalExclVat).', VAT '.$this->format($data->totalVat).', incl '
                .$this->format($data->totalInclVat).').'
            );
        }
    }

    /** Whether an invoice would pass, without raising. */
    public function passes(ZatcaInvoiceData $data): bool
    {
        try {
            $this->validate($data);

            return true;
        } catch (ZatcaException) {
            return false;
        }
    }

    private function assertEqual(float $actual, float $expected, string $reference, string $rule): void
    {
        if (abs($actual - $expected) < self::EPSILON) {
            return;
        }

        throw new ZatcaException(
            "[ZATCA] Invoice [{$reference}] does not reconcile — {$rule} (".$this->format($actual).' vs '
            .$this->format($expected).').'
        );
    }

    private function format(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
