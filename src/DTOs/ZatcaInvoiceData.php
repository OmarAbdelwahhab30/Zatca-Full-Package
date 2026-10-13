<?php

namespace Zatca\DTOs;

use DateTimeImmutable;
use Zatca\Enums\ZatcaComplianceDocumentType;

/**
 * Host-agnostic invoice input. Produced by the host app's InvoiceSourceContract
 * implementation from its own Order/domain model — the package never reads
 * the host's Order directly.
 */
class ZatcaInvoiceData
{
    public function __construct(
        public readonly string $egsIdentifier,
        /** Host-side identifier (e.g. order/transaction id) used for idempotency. */
        public readonly string $sourceReference,
        public readonly ?string $buyerName,
        public readonly ?string $buyerVatNumber,
        public readonly DateTimeImmutable $issueDateTime,
        public readonly string $currency,
        /** @var array<ZatcaInvoiceLine> */
        public readonly array $lines,
        public readonly float $totalExclVat,
        public readonly float $totalVat,
        public readonly float $totalInclVat,
        /**
         * Defaults to Invoice so every existing caller (which never sets this)
         * keeps generating a plain 388 invoice exactly as before this field existed.
         */
        public readonly ZatcaComplianceDocumentType $documentType = ZatcaComplianceDocumentType::Invoice,
        /** cbc:ID of the document this one bills against. Required when documentType requires it. */
        public readonly ?string $billingReferenceId = null,
        /** KSA-10 (BR-KSA-17) reason, max 1000 chars. Required when documentType requires it. */
        public readonly ?string $adjustmentReason = null,
        /**
         * Document-level `cac:AllowanceCharge` entries (e.g. a bundle discount).
         * Appended last, and defaulted to empty, so every positional or named
         * caller that existed before this field keeps behaving exactly as it did.
         *
         * @var array<ZatcaAllowanceCharge>
         */
        public readonly array $allowanceCharges = [],
        /**
         * Standard (B2B) documents only: the company buyer. Ignored for Simplified
         * documents (not emitted, not snapshotted). Appended last and null by default,
         * like every field added after the first release.
         */
        public readonly ?BuyerParty $buyer = null,
        /** Standard documents: the supply date KSA-5 (BR-KSA-15). Ignored for Simplified. */
        public readonly ?DateTimeImmutable $supplyDate = null,
    ) {}

    /** A copy with the company buyer set; this instance is left unchanged. */
    public function withBuyer(?BuyerParty $buyer): self
    {
        return $this->copy(['buyer' => $buyer]);
    }

    /** A copy with the supply date set; this instance is left unchanged. */
    public function withSupplyDate(?DateTimeImmutable $supplyDate): self
    {
        return $this->copy(['supplyDate' => $supplyDate]);
    }

    /** @param array<string, mixed> $changes */
    private function copy(array $changes): self
    {
        $arguments = [];
        foreach ((new \ReflectionClass(self::class))->getConstructor()->getParameters() as $parameter) {
            $name = $parameter->getName();
            $arguments[$name] = array_key_exists($name, $changes) ? $changes[$name] : $this->{$name};
        }

        return new self(...$arguments);
    }
}
