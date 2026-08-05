<?php

namespace Zatca\Contracts;

use Zatca\DTOs\ZatcaApiResult;
use Psr\Http\Message\ResponseInterface;

/** One implementation per API version — normalizes the wire response into ZatcaApiResult + typed payload. */
interface ResponseNormalizerInterface
{
    public function normalizeComplianceCsid(ResponseInterface $response): ZatcaApiResult;

    public function normalizeComplianceInvoice(ResponseInterface $response): ZatcaApiResult;

    public function normalizeProductionCsid(ResponseInterface $response): ZatcaApiResult;

    /** Kept separate from normalizeProductionCsid() — see ZatcaConnectorInterface::renewProductionCsid(). */
    public function normalizeProductionCsidRenewal(ResponseInterface $response): ZatcaApiResult;

    public function normalizeReporting(ResponseInterface $response): ZatcaApiResult;
}
