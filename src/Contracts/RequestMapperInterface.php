<?php

namespace Zatca\Contracts;

/** One implementation per API version — maps package DTOs to the wire request body. */
interface RequestMapperInterface
{
    public function mapComplianceCsidRequest(string $csrBase64): array;

    public function mapInvoiceSubmissionRequest(string $invoiceHash, string $uuid, string $signedInvoiceBase64): array;

    public function mapProductionCsidRequest(string $complianceRequestId): array;

    /** Kept separate from mapProductionCsidRequest() — see ZatcaConnectorInterface::renewProductionCsid(). */
    public function mapProductionCsidRenewalRequest(string $complianceRequestId): array;
}
