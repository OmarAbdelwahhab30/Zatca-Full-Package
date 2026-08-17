<?php

namespace Zatca\Services\Connectors\V2;

use Zatca\Contracts\ResponseNormalizerInterface;
use Zatca\DTOs\Payloads\ComplianceCsidPayload;
use Zatca\DTOs\Payloads\ComplianceInvoicePayload;
use Zatca\DTOs\Payloads\ProductionCsidPayload;
use Zatca\DTOs\Payloads\ReportingPayload;
use Zatca\DTOs\ZatcaApiResult;
use Zatca\Enums\ZatcaResponseStatus;
use Zatca\Exceptions\ZatcaContractChangedException;
use Psr\Http\Message\ResponseInterface;

/**
 * Normalizes ZATCA API V2 responses into ZatcaApiResult + typed payload.
 * Field names verified against ZATCA's own sandbox Swagger docs (Compliance
 * CSID API 2.1.0 / Reporting API 2.1.0) — not guessed.
 */
class ResponseNormalizer implements ResponseNormalizerInterface
{
    public function normalizeComplianceCsid(ResponseInterface $response): ZatcaApiResult
    {
        $body = $this->decode($response);
        $httpStatus = $response->getStatusCode();

        if ($httpStatus >= 400) {
            return $this->errorResult($body, $httpStatus);
        }

        if (! isset($body['binarySecurityToken'], $body['secret'])) {
            throw ZatcaContractChangedException::unexpectedShape('POST /compliance', $body);
        }

        $payload = new ComplianceCsidPayload(
            requestId: (string) ($body['requestID'] ?? ''),
            binarySecurityToken: $body['binarySecurityToken'],
            secret: $body['secret'],
        );

        return new ZatcaApiResult(
            successful: true,
            status: ZatcaResponseStatus::Pass,
            httpStatus: $httpStatus,
            requestId: $payload->requestId,
            sanitizedResponse: ['dispositionMessage' => $body['dispositionMessage'] ?? null, 'requestID' => $body['requestID'] ?? null],
            payload: $payload,
        );
    }

    public function normalizeComplianceInvoice(ResponseInterface $response): ZatcaApiResult
    {
        return $this->normalizeValidationShaped($response, 'POST /compliance/invoices', fn (array $v, ?string $reportingStatus) => new ComplianceInvoicePayload(
            reportingStatus: (string) $reportingStatus,
            validationWarnings: array_column($v['warningMessages'] ?? [], 'message'),
            validationErrors: array_column($v['errorMessages'] ?? [], 'message'),
        ));
    }

    public function normalizeProductionCsid(ResponseInterface $response): ZatcaApiResult
    {
        $body = $this->decode($response);
        $httpStatus = $response->getStatusCode();

        if ($httpStatus >= 400) {
            return $this->errorResult($body, $httpStatus);
        }

        if (! isset($body['binarySecurityToken'], $body['secret'])) {
            throw ZatcaContractChangedException::unexpectedShape('POST /production/csids', $body);
        }

        $payload = new ProductionCsidPayload(
            binarySecurityToken: $body['binarySecurityToken'],
            secret: $body['secret'],
        );

        return new ZatcaApiResult(
            successful: true,
            status: ZatcaResponseStatus::Pass,
            httpStatus: $httpStatus,
            requestId: null,
            sanitizedResponse: [],
            payload: $payload,
        );
    }

    public function normalizeProductionCsidRenewal(ResponseInterface $response): ZatcaApiResult
    {
        // Same shape as normalizeProductionCsid() today — kept as a separate method so a
        // verified renewal response contract can diverge here without touching onboarding.
        return $this->normalizeProductionCsid($response);
    }

    public function normalizeReporting(ResponseInterface $response): ZatcaApiResult
    {
        return $this->normalizeValidationShaped($response, 'POST /invoices/reporting/single', fn (array $v, ?string $reportingStatus) => new ReportingPayload(
            reportingStatus: (string) $reportingStatus,
            validationWarnings: array_column($v['warningMessages'] ?? [], 'message'),
            validationErrors: array_column($v['errorMessages'] ?? [], 'message'),
        ));
    }

    private function normalizeValidationShaped(ResponseInterface $response, string $endpoint, callable $payloadFactory): ZatcaApiResult
    {
        $body = $this->decode($response);
        $httpStatus = $response->getStatusCode();

        // 409 = "already reported" — treated as a terminal success (idempotent), not a failure to retry.
        $validationResults = $body['validationResults'] ?? null;

        if (! is_array($validationResults) || ! isset($validationResults['status'])) {
            // Non-validation failures (401 Unauthorized, 406 version mismatch, 500) don't carry
            // the validationResults shape at all — those are plain transport/auth errors, not a
            // contract change in the shape ZATCA uses for actual invoice validation results.
            if ($httpStatus >= 400) {
                return $this->errorResult($body, $httpStatus);
            }

            throw ZatcaContractChangedException::unexpectedShape($endpoint, $body);
        }

        $status = ZatcaResponseStatus::fromClearanceStatus($validationResults['status']);
        $reportingStatus = $body['reportingStatus'] ?? null;
        $errorMessages = array_column($validationResults['errorMessages'] ?? [], 'message');

        $payload = $payloadFactory($validationResults, $reportingStatus);

        // HTTP 409 is ONLY treated as a successful/idempotent outcome when the response body
        // itself confirms this exact invoice was already reported. A 409 for any other reason
        // (a different conflict ZATCA may return under the same status code) must NOT be
        // silently accepted as success — it is a real failure requiring manual review.
        $isConfirmedAlreadyReported = $httpStatus === 409 && $this->confirmsAlreadyReported($errorMessages);

        return new ZatcaApiResult(
            successful: in_array($status, [ZatcaResponseStatus::Pass, ZatcaResponseStatus::Warning], true) || $isConfirmedAlreadyReported,
            status: $isConfirmedAlreadyReported ? ZatcaResponseStatus::Pass : $status,
            httpStatus: $httpStatus,
            requestId: null,
            sanitizedResponse: [
                'status' => $validationResults['status'],
                'reportingStatus' => $reportingStatus,
                'alreadyReported' => $isConfirmedAlreadyReported,
                // ZATCA's own validation error codes, kept alongside the messages so a
                // diagnostic screen can show the code rather than parsing it back out
                // of free text. Codes only — no other field of errorMessages is kept.
                'errorCodes' => $this->errorCodes($validationResults),
            ],
            warnings: array_column($validationResults['warningMessages'] ?? [], 'message'),
            errors: $errorMessages,
            payload: $payload,
        );
    }

    /**
     * Validation error codes, in the same order as the messages.
     *
     * ZATCA does not always send a code, so entries without one are dropped rather
     * than padded with a placeholder — an absent code stays absent.
     *
     * @param  array<string,mixed>  $validationResults
     * @return array<int, string>
     */
    private function errorCodes(array $validationResults): array
    {
        $codes = array_column($validationResults['errorMessages'] ?? [], 'code');

        return array_values(array_filter(
            array_map(fn ($code) => is_scalar($code) ? (string) $code : null, $codes),
            fn (?string $code) => $code !== null && $code !== ''
        ));
    }

    /**
     * Confirms a 409 response is specifically ZATCA's "already reported" idempotency signal,
     * not an unrelated conflict that happens to share the same HTTP status code.
     *
     * @param  array<string>  $errorMessages
     */
    private function confirmsAlreadyReported(array $errorMessages): bool
    {
        foreach ($errorMessages as $message) {
            if (preg_match('/already\s+.*report/i', (string) $message) === 1) {
                return true;
            }
        }

        return false;
    }

    private function errorResult(array $body, int $httpStatus): ZatcaApiResult
    {
        $fallbackMessage = ($body['message'] ?? null) ?: ($body['error'] ?? 'Unknown error');
        $errors = $body['errors'] ?? ($body['validationResults']['errorMessages'] ?? [['message' => $fallbackMessage]]);
        $messages = array_map(fn ($e) => is_array($e) ? ($e['message'] ?? json_encode($e)) : (string) $e, is_array($errors) ? $errors : [$errors]);

        return new ZatcaApiResult(
            successful: false,
            status: ZatcaResponseStatus::Error,
            httpStatus: $httpStatus,
            requestId: null,
            sanitizedResponse: ['httpStatus' => $httpStatus],
            errors: $messages,
        );
    }

    private function decode(ResponseInterface $response): array
    {
        $raw = (string) $response->getBody();

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : ['message' => $raw];
    }
}
