<?php

namespace Zatca\Services\Invoice;

use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\Enums\ZatcaComplianceDocumentType;
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

    /** KSA-10 / BR-KSA-17: PaymentMeans/InstructionNote text (BR-KSA-F-06-C13). */
    private const MAX_REASON_LENGTH = 1000;

    /** BR-KSA-F-06: buyer legal name (BT-44) length. */
    private const MAX_BUYER_NAME_LENGTH = 1000;

    /** @throws ZatcaException when the invoice's own figures do not reconcile. */
    public function validate(ZatcaInvoiceData $data): void
    {
        if ($data->documentType->isStandard()) {
            $this->validateStandardBuyer($data);
        }

        if ($data->documentType->requiresBillingReference()) {
            $this->validateNoteRequirements($data);
        }

        if ($data->lines === []) {
            throw new ZatcaException(
                "[ZATCA] Invoice [{$data->sourceReference}] has no lines, so there is nothing to invoice."
            );
        }

        $net = 0.0;
        $vat = 0.0;
        $allowanceNet = 0.0;
        $allowanceVat = 0.0;

        foreach ($data->allowanceCharges as $position => $allowance) {
            $number = $position + 1;

            if ($allowance->amountExclVat < 0 || $allowance->vatAmount < 0) {
                throw new ZatcaException(
                    "[ZATCA] Invoice [{$data->sourceReference}] allowance {$number} is negative (net "
                    .$this->format($allowance->amountExclVat).', VAT '.$this->format($allowance->vatAmount).').'
                );
            }

            $allowanceNet += $allowance->amountExclVat;
            $allowanceVat += $allowance->vatAmount;
        }

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

        // BR-CO-10: the document's line extension amount, minus any document-level
        // allowance, is TaxExclusiveAmount. With no allowances (every document
        // except a new bundle invoice) this is exactly the original rule.
        $this->assertEqual(
            round($net - $allowanceNet, 2),
            $data->totalExclVat,
            $data->sourceReference,
            'BR-CO-10: the sum of the invoice lines minus allowances does not equal TaxExclusiveAmount'
        );

        // BR-CO-14: same reconciliation on the VAT side.
        $this->assertEqual(
            round($vat - $allowanceVat, 2),
            $data->totalVat,
            $data->sourceReference,
            'BR-CO-14: the sum of the line VAT amounts minus allowance VAT does not equal the invoice VAT total'
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

    /**
     * The Standard (B2B) counterpart of the arithmetic checks above — the one thing this
     * class was, until now, missing before it: Standard\StandardCreditNote\StandardDebitNote
     * all carry a buyer, and nothing validated its shape before it reached the XML
     * generator (which only ever asserted the buyer is non-null). Every rule here comes
     * from the KSA schematron rules shipped with the ZATCA SDK itself (Facts Register
     * F-SDK-04…F-SDK-10) — never invented, and never re-checking what BuyerOtherId's own
     * constructor already guarantees (the scheme is validated there).
     *
     * @throws ZatcaException when the buyer or its address does not meet a documented
     *         KSA rule.
     */
    private function validateStandardBuyer(ZatcaInvoiceData $data): void
    {
        $buyer = $data->buyer;

        if ($buyer === null) {
            throw new ZatcaException(
                "[ZATCA] Standard document [{$data->sourceReference}] has no buyer (BT-44/BR-KSA-63)."
            );
        }

        // BR-KSA-15: a tax invoice (KSA-2 starting 01, which every Standard document is)
        // must carry a supply date. Notes inherit it from the original (P41); a plain
        // Standard invoice sets it at generation — either way it must be here by now.
        if ($data->supplyDate === null) {
            throw new ZatcaException(
                "[ZATCA] Standard document [{$data->sourceReference}] has no supply date (KSA-5/BR-KSA-15)."
            );
        }

        if (trim($buyer->registrationName) === '' || mb_strlen($buyer->registrationName) > self::MAX_BUYER_NAME_LENGTH) {
            throw new ZatcaException(
                "[ZATCA] Standard document [{$data->sourceReference}] buyer legal name (BT-44) must be 1-"
                .self::MAX_BUYER_NAME_LENGTH.' characters (BR-KSA-F-06).'
            );
        }

        $hasVat = filled($buyer->vatNumber);
        $hasOtherId = $buyer->otherId !== null;

        // BT-46/BT-48 are mutually exclusive on the signed XML (SaleBuyer::toBuyerParty()
        // already enforces this at capture) — checked again here because this class is the
        // last gate regardless of how a ZatcaInvoiceData was actually built.
        if ($hasVat && $hasOtherId) {
            throw new ZatcaException(
                "[ZATCA] Standard document [{$data->sourceReference}] buyer has both a VAT number and another ID "
                .'— exactly one is required (BT-46/BT-48).'
            );
        }

        if (! $hasVat && ! $hasOtherId) {
            throw new ZatcaException(
                "[ZATCA] Standard document [{$data->sourceReference}] buyer has neither a VAT number nor another "
                .'ID; one is required when the buyer has no VAT number (BR-KSA-81).'
            );
        }

        if ($hasVat && ! preg_match('/^3\d{13}3$/', $buyer->vatNumber)) {
            throw new ZatcaException(
                "[ZATCA] Standard document [{$data->sourceReference}] buyer VAT number must be 15 digits, first "
                .'and last digit 3 (BR-KSA-44).'
            );
        }

        if ($hasOtherId && ! ctype_alnum($buyer->otherId->value)) {
            throw new ZatcaException(
                "[ZATCA] Standard document [{$data->sourceReference}] buyer other-ID value must be alphanumeric "
                .'with no spaces (BR-KSA-F-12).'
            );
        }

        $this->validateStandardBuyerAddress($data);
    }

    /** @throws ZatcaException when the buyer address is missing what BR-KSA-63/10/67 require. */
    private function validateStandardBuyerAddress(ZatcaInvoiceData $data): void
    {
        $address = $data->buyer->address;
        $isSaudi = ($address->countryCode ?: 'SA') === 'SA';

        // BR-KSA-63: mandatory when the buyer country is SA. The additional number is
        // deliberately absent from this list (F-SDK-07 says so explicitly).
        $mandatoryWhenSaudi = [
            'street' => 'BT-50',
            'buildingNumber' => 'KSA-18',
            'district' => 'KSA-4',
            'city' => 'BT-52',
            'postalCode' => 'BT-53',
        ];

        if ($isSaudi) {
            foreach ($mandatoryWhenSaudi as $field => $label) {
                if (blank($address->{$field})) {
                    throw new ZatcaException(
                        "[ZATCA] Standard document [{$data->sourceReference}] buyer address is missing {$label} "
                        .'— mandatory for a Saudi buyer (BR-KSA-63).'
                    );
                }
            }

            if (! preg_match('/^\d{5}$/', (string) $address->postalCode)) {
                throw new ZatcaException(
                    "[ZATCA] Standard document [{$data->sourceReference}] buyer postal code must be 5 digits "
                    .'(BR-KSA-67).'
                );
            }

            return;
        }

        // BR-KSA-10: a non-SA buyer only needs street, city and country code.
        foreach (['street' => 'street', 'city' => 'city'] as $field => $label) {
            if (blank($address->{$field})) {
                throw new ZatcaException(
                    "[ZATCA] Standard document [{$data->sourceReference}] non-Saudi buyer address is missing "
                    ."{$label} (BR-KSA-10)."
                );
            }
        }
    }

    /** @throws ZatcaException when a credit/debit note is missing what KSA-10/BR-KSA-56 require. */
    private function validateNoteRequirements(ZatcaInvoiceData $data): void
    {
        if (blank($data->billingReferenceId)) {
            throw new ZatcaException(
                "[ZATCA] {$data->documentType->label()} [{$data->sourceReference}] has no billing reference to "
                .'the document it adjusts (BR-KSA-56).'
            );
        }

        if (blank($data->adjustmentReason)) {
            throw new ZatcaException(
                "[ZATCA] {$data->documentType->label()} [{$data->sourceReference}] has no reason (KSA-10 / BR-KSA-17)."
            );
        }

        if (mb_strlen($data->adjustmentReason) > self::MAX_REASON_LENGTH) {
            throw new ZatcaException(
                "[ZATCA] {$data->documentType->label()} [{$data->sourceReference}] reason exceeds "
                .self::MAX_REASON_LENGTH.' characters (BR-KSA-F-06-C13).'
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
