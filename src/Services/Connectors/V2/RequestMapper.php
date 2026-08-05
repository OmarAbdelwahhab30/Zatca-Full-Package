<?php

namespace Asas\Zatca\Services\Connectors\V2;

use Asas\Zatca\Contracts\RequestMapperInterface;

/** Maps package inputs to ZATCA API V2 wire request bodies — verified against ZATCA's own sandbox API docs. */
class RequestMapper implements RequestMapperInterface
{
    public function mapComplianceCsidRequest(string $csrBase64): array
    {
        return ['csr' => $csrBase64];
    }

    public function mapInvoiceSubmissionRequest(string $invoiceHash, string $uuid, string $signedInvoiceBase64): array
    {
        return [
            'invoiceHash' => $invoiceHash,
            'uuid' => $uuid,
            'invoice' => $signedInvoiceBase64,
        ];
    }

    public function mapProductionCsidRequest(string $complianceRequestId): array
    {
        return ['compliance_request_id' => $complianceRequestId];
    }

    /**
     * NOTE: identical body to mapProductionCsidRequest() today because
     * `/production/csids` is the only production-cert-issuing endpoint this
     * package has verified ZATCA documentation for. Kept as a separate
     * method so a real renewal contract (different endpoint, different
     * required fields) can be implemented here without touching onboarding.
     */
    public function mapProductionCsidRenewalRequest(string $complianceRequestId): array
    {
        return ['compliance_request_id' => $complianceRequestId];
    }
}
