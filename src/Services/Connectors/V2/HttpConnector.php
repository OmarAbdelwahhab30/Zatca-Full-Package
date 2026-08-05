<?php

namespace Zatca\Services\Connectors\V2;

use Zatca\Contracts\ZatcaConnectorInterface;
use Zatca\DTOs\ZatcaApiResult;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Enums\ZatcaResponseStatus;
use Zatca\Services\Security\ZatcaCredentialStore;
use Zatca\State\EgsRegistry;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Config;

/**
 * ZATCA API V2 HTTP connector. Endpoints, headers and payload keys verified
 * against ZATCA's own sandbox Swagger documentation (Compliance CSID API
 * 2.1.0 / Reporting API 2.1.0) — not guessed. Never logs Authorization
 * headers, tokens, or secrets; only sanitized response bodies are returned
 * up through ZatcaApiResult.
 */
class HttpConnector implements ZatcaConnectorInterface
{
    public function __construct(
        private readonly Client $client,
        private readonly RequestMapper $requestMapper,
        private readonly ResponseNormalizer $responseNormalizer,
        private readonly EgsRegistry $egsRegistry,
        private readonly ZatcaCredentialStore $credentialStore,
    ) {}

    public function requestComplianceCsid(string $egsIdentifier, string $csrBase64, string $otp): ZatcaApiResult
    {
        $environment = $this->environmentFor($egsIdentifier);

        return $this->send($environment, 'POST', '/compliance', [
            'headers' => [
                'OTP' => $otp,
                'Accept-Version' => 'V2',
                'Content-Type' => 'application/json',
            ],
            'json' => $this->requestMapper->mapComplianceCsidRequest($csrBase64),
        ], fn ($response) => $this->responseNormalizer->normalizeComplianceCsid($response));
    }

    public function submitComplianceInvoice(string $egsIdentifier, string $invoiceHash, string $uuid, string $signedInvoiceBase64): ZatcaApiResult
    {
        $environment = $this->environmentFor($egsIdentifier);
        $credentials = $this->credentialStore->get($egsIdentifier, $environment, ZatcaCredentialType::Compliance);

        return $this->send($environment, 'POST', '/compliance/invoices', [
            'headers' => [
                'Accept-Version' => 'V2',
                'Content-Type' => 'application/json',
            ],
            'auth' => [$credentials->authUsername(), $credentials->authPassword()],
            'json' => $this->requestMapper->mapInvoiceSubmissionRequest($invoiceHash, $uuid, $signedInvoiceBase64),
        ], fn ($response) => $this->responseNormalizer->normalizeComplianceInvoice($response));
    }

    public function requestProductionCsid(string $egsIdentifier, string $complianceRequestId): ZatcaApiResult
    {
        $environment = $this->environmentFor($egsIdentifier);
        $credentials = $this->credentialStore->get($egsIdentifier, $environment, ZatcaCredentialType::Compliance);

        return $this->send($environment, 'POST', '/production/csids', [
            'headers' => [
                'Accept-Version' => 'V2',
                'Content-Type' => 'application/json',
            ],
            'auth' => [$credentials->authUsername(), $credentials->authPassword()],
            'json' => $this->requestMapper->mapProductionCsidRequest($complianceRequestId),
        ], fn ($response) => $this->responseNormalizer->normalizeProductionCsid($response));
    }

    public function renewProductionCsid(string $egsIdentifier, string $complianceRequestId): ZatcaApiResult
    {
        $environment = $this->environmentFor($egsIdentifier);
        $credentials = $this->credentialStore->get($egsIdentifier, $environment, ZatcaCredentialType::Compliance);

        // Calls the same known `/production/csids` endpoint as onboarding today — see the
        // docblock on ZatcaConnectorInterface::renewProductionCsid() for why this is a
        // separate method rather than an alias, and config('zatca.certificate.auto_renewal_enabled')
        // for why this is not called automatically until the real renewal contract is verified.
        return $this->send($environment, 'POST', '/production/csids', [
            'headers' => [
                'Accept-Version' => 'V2',
                'Content-Type' => 'application/json',
            ],
            'auth' => [$credentials->authUsername(), $credentials->authPassword()],
            'json' => $this->requestMapper->mapProductionCsidRenewalRequest($complianceRequestId),
        ], fn ($response) => $this->responseNormalizer->normalizeProductionCsidRenewal($response));
    }

    public function reportInvoice(string $egsIdentifier, string $invoiceHash, string $uuid, string $signedInvoiceBase64): ZatcaApiResult
    {
        $environment = $this->environmentFor($egsIdentifier);
        $credentials = $this->credentialStore->get($egsIdentifier, $environment, ZatcaCredentialType::Production);

        return $this->send($environment, 'POST', '/invoices/reporting/single', [
            'headers' => [
                'Accept-Version' => 'V2',
                'Content-Type' => 'application/json',
                'Accept-Language' => Config::get('zatca.http.accept_language', 'ar'),
                'Clearance-Status' => '0', // Reporting model only — clearance not used in this package's scope.
            ],
            'auth' => [$credentials->authUsername(), $credentials->authPassword()],
            'json' => $this->requestMapper->mapInvoiceSubmissionRequest($invoiceHash, $uuid, $signedInvoiceBase64),
        ], fn ($response) => $this->responseNormalizer->normalizeReporting($response));
    }

    private function send(ZatcaEnvironment $environment, string $method, string $path, array $options, callable $normalize): ZatcaApiResult
    {
        $baseUrl = rtrim(Config::get("zatca.http.base_urls.{$environment->value}"), '/');

        try {
            $response = $this->client->request($method, $baseUrl.$path, array_merge($options, [
                'timeout' => Config::get('zatca.http.timeout', 30),
                'http_errors' => false,
            ]));

            return $normalize($response);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                return $normalize($e->getResponse());
            }

            return new ZatcaApiResult(
                successful: false,
                status: ZatcaResponseStatus::Error,
                httpStatus: 0,
                requestId: null,
                sanitizedResponse: [],
                errors: ['Network error: '.$e->getMessage()],
            );
        }
    }

    private function environmentFor(string $egsIdentifier): ZatcaEnvironment
    {
        return $this->egsRegistry->for($egsIdentifier)->environment;
    }
}
