<?php

namespace Asas\Zatca\Contracts;

use Asas\Zatca\DTOs\ZatcaApiResult;

/**
 * The primary versioning seam. One implementation per ZATCA API version
 * (V2 today, V3 later) selected by ConnectorFactory from config('zatca.api_version').
 */
interface ZatcaConnectorInterface
{
    public function requestComplianceCsid(string $egsIdentifier, string $csrBase64, string $otp): ZatcaApiResult;

    public function submitComplianceInvoice(
        string $egsIdentifier,
        string $invoiceHash,
        string $uuid,
        string $signedInvoiceBase64
    ): ZatcaApiResult;

    public function requestProductionCsid(string $egsIdentifier, string $complianceRequestId): ZatcaApiResult;

    /**
     * Renews a Production CSID. Deliberately a SEPARATE method from
     * requestProductionCsid() — ZATCA's dedicated certificate-renewal
     * contract (whether it needs a new OTP, a different endpoint, or
     * different auth) has not been verified against an official ZATCA
     * document in this repository. Today's V2 implementation calls the
     * same known `/production/csids` endpoint as initial onboarding purely
     * because it is the only production-cert-issuing endpoint this package
     * has verified documentation for — but it is isolated behind this own
     * method/request-mapper/response-normalizer so it can be corrected
     * without touching onboarding once the real renewal contract is
     * confirmed. Automatic renewal is gated off by default — see
     * config('zatca.certificate.auto_renewal_enabled').
     */
    public function renewProductionCsid(string $egsIdentifier, string $complianceRequestId): ZatcaApiResult;

    public function reportInvoice(
        string $egsIdentifier,
        string $invoiceHash,
        string $uuid,
        string $signedInvoiceBase64
    ): ZatcaApiResult;
}
