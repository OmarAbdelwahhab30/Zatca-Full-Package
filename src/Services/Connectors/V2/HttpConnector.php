<?php

namespace Zatca\Services\Connectors\V2;

use Zatca\Contracts\ZatcaConnectorInterface;
use Zatca\DTOs\ZatcaApiResult;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Enums\ZatcaResponseStatus;
use Zatca\Exceptions\ZatcaException;
use Zatca\Services\Security\ZatcaCredentialStore;
use Zatca\State\EgsRegistry;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
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
    /**
     * The API version this connector implements, sent as `Accept-Version` on
     * every request. Deliberately a constant and not config('zatca.api_version'):
     * this class's RequestMapper/ResponseNormalizer speak V2, so it must always
     * announce V2. The config value is the seam that decides WHICH connector is
     * used (ConnectorFactory), not what a given connector sends.
     */
    private const API_VERSION = 'V2';

    public function __construct(
        private readonly Client $client,
        private readonly RequestMapper $requestMapper,
        private readonly ResponseNormalizer $responseNormalizer,
        private readonly EgsRegistry $egsRegistry,
        private readonly ZatcaCredentialStore $credentialStore,
    ) {}

    public function requestComplianceCsid(string $egsIdentifier, string $csrBase64, #[\SensitiveParameter] string $otp): ZatcaApiResult
    {
        $environment = $this->environmentFor($egsIdentifier);

        return $this->send($environment, 'POST', '/compliance', [
            'headers' => $this->headers(['OTP' => $otp]),
            'json' => $this->requestMapper->mapComplianceCsidRequest($csrBase64),
        ], fn ($response) => $this->responseNormalizer->normalizeComplianceCsid($response));
    }

    public function submitComplianceInvoice(string $egsIdentifier, string $invoiceHash, string $uuid, string $signedInvoiceBase64): ZatcaApiResult
    {
        $environment = $this->environmentFor($egsIdentifier);
        $credentials = $this->credentialStore->get($egsIdentifier, $environment, ZatcaCredentialType::Compliance);

        return $this->send($environment, 'POST', '/compliance/invoices', [
            'headers' => $this->headers(),
            'auth' => [$credentials->authUsername(), $credentials->authPassword()],
            'json' => $this->requestMapper->mapInvoiceSubmissionRequest($invoiceHash, $uuid, $signedInvoiceBase64),
        ], fn ($response) => $this->responseNormalizer->normalizeComplianceInvoice($response));
    }

    public function requestProductionCsid(string $egsIdentifier, string $complianceRequestId): ZatcaApiResult
    {
        $environment = $this->environmentFor($egsIdentifier);
        $credentials = $this->credentialStore->get($egsIdentifier, $environment, ZatcaCredentialType::Compliance);

        return $this->send($environment, 'POST', '/production/csids', [
            'headers' => $this->headers(),
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
            'headers' => $this->headers(),
            'auth' => [$credentials->authUsername(), $credentials->authPassword()],
            'json' => $this->requestMapper->mapProductionCsidRenewalRequest($complianceRequestId),
        ], fn ($response) => $this->responseNormalizer->normalizeProductionCsidRenewal($response));
    }

    public function reportInvoice(string $egsIdentifier, string $invoiceHash, string $uuid, string $signedInvoiceBase64): ZatcaApiResult
    {
        $environment = $this->environmentFor($egsIdentifier);
        $credentials = $this->credentialStore->get($egsIdentifier, $environment, ZatcaCredentialType::Production);

        return $this->send($environment, 'POST', '/invoices/reporting/single', [
            // Reporting model (Simplified/B2C) only — Standard documents go through clearInvoice().
            'headers' => $this->headers(['Clearance-Status' => '0']),
            'auth' => [$credentials->authUsername(), $credentials->authPassword()],
            'json' => $this->requestMapper->mapInvoiceSubmissionRequest($invoiceHash, $uuid, $signedInvoiceBase64),
        ], fn ($response) => $this->responseNormalizer->normalizeReporting($response));
    }

    /** POST /invoices/clearance/single (Facts Register F-CL-01/F-CL-03) — Standard (B2B) documents. */
    public function clearInvoice(string $egsIdentifier, string $invoiceHash, string $uuid, string $signedInvoiceBase64): ZatcaApiResult
    {
        $environment = $this->environmentFor($egsIdentifier);
        $credentials = $this->credentialStore->get($egsIdentifier, $environment, ZatcaCredentialType::Production);

        return $this->send($environment, 'POST', '/invoices/clearance/single', [
            'headers' => $this->headers(['Clearance-Status' => '1']),
            'auth' => [$credentials->authUsername(), $credentials->authPassword()],
            'json' => $this->requestMapper->mapInvoiceSubmissionRequest($invoiceHash, $uuid, $signedInvoiceBase64),
        ], fn ($response) => $this->responseNormalizer->normalizeClearance($response));
    }

    /**
     * The headers every V2 request shares. Single source of `Accept-Version`
     * so the five endpoints can never drift apart when the version changes.
     *
     * @param  array<string, string>  $extra  Per-endpoint headers (OTP, Clearance-Status).
     * @return array<string, string>
     */
    private function headers(array $extra = []): array
    {
        $this->assertConfiguredApiVersionMatches();

        return array_merge([
            'Accept-Version' => self::API_VERSION,
            'Content-Type' => 'application/json',
            'Accept-Language' => Config::get('zatca.http.accept_language', 'ar'),
        ], $extra);
    }

    /**
     * Guards against this connector being resolved directly from the container
     * (bypassing ConnectorFactory) while config points at a different API
     * version. Sending V2 headers with a V3 config — or vice versa — would be a
     * silent wrong-version request; failing here makes the mismatch obvious.
     */
    private function assertConfiguredApiVersionMatches(): void
    {
        $configured = Config::get('zatca.api_version');

        if ($configured !== self::API_VERSION) {
            throw new ZatcaException(
                sprintf(
                    'ZATCA_API_VERSION is [%s] but the %s connector was used. Resolve the connector through ConnectorFactory, and add Connectors/%s/ before switching config to it.',
                    $configured,
                    self::API_VERSION,
                    $configured,
                )
            );
        }
    }

    private function send(ZatcaEnvironment $environment, string $method, string $path, array $options, callable $normalize): ZatcaApiResult
    {
        $baseUrl = rtrim(Config::get("zatca.http.base_urls.{$environment->value}"), '/');

        try {
            $response = $this->client->request($method, $baseUrl.$path, array_merge($options, [
                'timeout' => Config::get('zatca.http.timeout', 30),
                'http_errors' => false,
                // ZATCA's API never redirects these POSTs. A redirect would carry the CSID
                // Basic-auth credentials to wherever it points, so it is treated as the
                // failure it is (a 3xx result) instead of being followed.
                'allow_redirects' => false,
            ]));

            return $normalize($response);
        } catch (TransferException $e) {
            // RequestException may carry a response worth normalizing. ConnectException
            // (connection refused, DNS failure, connect timeout) is a TransferException
            // but not a RequestException in Guzzle 7, and is recorded as HTTP 0 below
            // like any other "never got an answer" failure.
            if ($e instanceof RequestException && $e->hasResponse()) {
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
