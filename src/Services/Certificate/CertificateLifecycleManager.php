<?php

namespace Zatca\Services\Certificate;

use Zatca\Enums\ZatcaCertificateState;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Events\ZatcaCertificateExpiring;
use Zatca\Jobs\RetryZatcaInvoiceJob;
use Zatca\Models\ZatcaEgsCredential;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\Connectors\ConnectorFactory;
use Zatca\Services\Security\ZatcaSigningKeyProvider;
use Zatca\State\EgsRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Owns certificate state end to end so "what happens when a cert expires
 * mid-queue-backlog" has one authoritative answer instead of being
 * reconstructed from Connector + State + Job logic scattered across files.
 *
 * States: Valid -> ExpiringSoon -> Expired -> Renewing -> Valid (or Failed).
 * (There is no separate "Active" state — a prior revision had one reached
 * only right after renewal, but nothing ever treated it differently from
 * Valid, and the next scheduled check() collapsed it back anyway. Valid is
 * the one durable "safe to report" state.)
 *
 * Automatic renewal is gated behind config('zatca.certificate.auto_renewal_enabled')
 * (default: false). ZATCA's dedicated renewal contract — whether it needs a
 * new OTP, a distinct endpoint, or different response fields — has not been
 * verified against an official document in this repository. Until that flag
 * is explicitly turned on by someone who has verified the real contract,
 * renew() refuses to invent an API call: it alerts an administrator and
 * leaves invoices safely paused instead.
 */
class CertificateLifecycleManager
{
    public function __construct(
        private readonly EgsRegistry $egsRegistry,
        private readonly ConnectorFactory $connectorFactory,
        private readonly ZatcaSigningKeyProvider $signingKeyProvider,
        private readonly CertificateParser $certificateParser,
    ) {}

    /** Evaluates Valid -> ExpiringSoon -> Expired transitions for one credential row. Call from a scheduled command. */
    public function check(string $egsIdentifier, ZatcaCredentialType $type): ZatcaCertificateState
    {
        $environment = $this->egsRegistry->for($egsIdentifier)->environment;
        $credential = $this->findCredential($egsIdentifier, $environment, $type);

        if (! $credential) {
            return ZatcaCertificateState::Valid;
        }

        // Older rows (and any onboarding that ran before the certificate parser was
        // fixed) can have a null expiry. Left alone, such a credential is invisible
        // to every expiry check below — so recover the date from the stored
        // certificate instead of silently reporting Valid forever.
        if (! $credential->certificate_expires_at && $credential->certificate) {
            [$issuedAt, $expiresAt] = $this->certificateParser->validity($credential->certificate);

            if ($expiresAt) {
                $credential->update([
                    'certificate_issued_at' => $credential->certificate_issued_at ?: $issuedAt,
                    'certificate_expires_at' => $expiresAt,
                ]);

                $credential->refresh();

                Log::info("[ZATCA] Recovered missing certificate expiry for EGS [{$egsIdentifier}] ({$type->value}): {$expiresAt->format('Y-m-d')}.");
            }
        }

        if (! $credential->certificate_expires_at) {
            Log::warning(
                "[ZATCA] Certificate for EGS [{$egsIdentifier}] ({$type->value}) has no expiry date on record — "
                .'it cannot be monitored. Re-run onboarding, or check that the stored certificate is readable.'
            );

            return ZatcaCertificateState::Valid;
        }

        if (in_array($credential->certificate_state, [ZatcaCertificateState::Renewing, ZatcaCertificateState::Failed], true)) {
            return $credential->certificate_state; // Don't override an in-flight/blocked renewal from a routine check.
        }

        $now = CarbonImmutable::now();
        $expiresAt = CarbonImmutable::parse($credential->certificate_expires_at);
        $alertDays = Config::get('zatca.certificate.alert_days', [60, 30, 7]);

        $newState = match (true) {
            $now->greaterThanOrEqualTo($expiresAt) => ZatcaCertificateState::Expired,
            $now->greaterThanOrEqualTo($expiresAt->subDays(max($alertDays))) => ZatcaCertificateState::ExpiringSoon,
            default => ZatcaCertificateState::Valid,
        };

        if ($newState !== $credential->certificate_state) {
            $credential->update(['certificate_state' => $newState]);

            if ($newState === ZatcaCertificateState::ExpiringSoon) {
                Log::warning("[ZATCA] Certificate for EGS [{$egsIdentifier}] ({$type->value}) expires at {$expiresAt->toDateString()} — renew soon.");
            }

            if ($newState === ZatcaCertificateState::Expired) {
                Log::error("[ZATCA] Certificate for EGS [{$egsIdentifier}] ({$type->value}) has EXPIRED. Reporting is paused until renewed.");
            }

            // Only on the transition — one alert per state change, not one per scheduled check.
            if (in_array($newState, [ZatcaCertificateState::ExpiringSoon, ZatcaCertificateState::Expired], true)) {
                Event::dispatch(new ZatcaCertificateExpiring(
                    egsIdentifier: $egsIdentifier,
                    credentialType: $type,
                    state: $newState,
                    expiresAt: $expiresAt,
                    daysRemaining: (int) $now->diffInDays($expiresAt, false),
                ));
            }
        }

        return $newState;
    }

    /**
     * Triggers renewal. No-op (returns current state) if not
     * Expired/ExpiringSoon/Failed. If auto-renewal is disabled (the
     * default), this NEVER calls the ZATCA API — it alerts an administrator
     * and leaves the certificate/invoices exactly as they were, because
     * inventing a renewal request against an unverified contract is worse
     * than requiring a human to confirm it first.
     */
    public function renew(string $egsIdentifier, ZatcaCredentialType $type): ZatcaCertificateState
    {
        $environment = $this->egsRegistry->for($egsIdentifier)->environment;
        $credential = $this->findCredential($egsIdentifier, $environment, $type);

        if (! $credential) {
            return ZatcaCertificateState::Failed;
        }

        if (! in_array($credential->certificate_state, [ZatcaCertificateState::ExpiringSoon, ZatcaCertificateState::Expired, ZatcaCertificateState::Failed], true)) {
            return $credential->certificate_state;
        }

        if ($type !== ZatcaCredentialType::Production) {
            Log::error("[ZATCA] Compliance CSID for EGS [{$egsIdentifier}] cannot be renewed — re-run zatca:onboard compliance with a fresh OTP instead.");

            return $credential->certificate_state;
        }

        if (! Config::get('zatca.certificate.auto_renewal_enabled', false)) {
            Log::error(
                "[ZATCA] Automatic renewal is disabled (config('zatca.certificate.auto_renewal_enabled') = false) for EGS [{$egsIdentifier}]. "
                .'The ZATCA renewal contract has not been verified in this codebase. Invoices remain paused/pending_reporting. '
                .'An administrator must verify the renewal contract, implement it in HttpConnector::renewProductionCsid(), '
                .'and explicitly enable ZATCA_AUTO_RENEWAL_ENABLED before this can proceed automatically.'
            );

            return $credential->certificate_state;
        }

        $credential->update(['certificate_state' => ZatcaCertificateState::Renewing]);

        $requestId = $this->findCredential($egsIdentifier, $environment, ZatcaCredentialType::Compliance)?->request_id;

        if (! $requestId) {
            $credential->update(['certificate_state' => ZatcaCertificateState::Failed]);
            Log::error("[ZATCA] Certificate renewal failed for EGS [{$egsIdentifier}]: no compliance request ID on record.");

            return ZatcaCertificateState::Failed;
        }

        $result = $this->connectorFactory->make()->renewProductionCsid($egsIdentifier, $requestId);

        if (! $result->successful) {
            $credential->update(['certificate_state' => ZatcaCertificateState::Failed]);
            Log::error("[ZATCA] Certificate renewal failed for EGS [{$egsIdentifier}]: ".implode('; ', $result->errors));

            return ZatcaCertificateState::Failed;
        }

        $this->signingKeyProvider->reuseComplianceKeyForProduction($egsIdentifier, $environment, [
            'binary_security_token' => $result->payload->binarySecurityToken,
            'secret' => $result->payload->secret,
            'certificate' => $result->payload->binarySecurityToken,
            'certificate_state' => ZatcaCertificateState::Valid,
        ]);

        $this->resumePendingInvoices($egsIdentifier, $environment);

        return ZatcaCertificateState::Valid;
    }

    /**
     * Safely resumes every invoice left Pending Reporting due to the expired
     * cert — re-dispatches reporting WITHOUT regenerating XML/UUID/ICV/PIH/Hash.
     */
    private function resumePendingInvoices(string $egsIdentifier, ZatcaEnvironment $environment): void
    {
        ZatcaInvoice::query()
            ->where('egs_identifier', $egsIdentifier)
            ->where('environment', $environment->value)
            ->whereIn('status', [ZatcaInvoiceStatus::PendingReporting->value, ZatcaInvoiceStatus::Retrying->value])
            ->each(fn (ZatcaInvoice $invoice) => RetryZatcaInvoiceJob::dispatch($invoice->id));
    }

    private function findCredential(string $egsIdentifier, ZatcaEnvironment $environment, ZatcaCredentialType $type): ?ZatcaEgsCredential
    {
        return ZatcaEgsCredential::query()
            ->where('egs_identifier', $egsIdentifier)
            ->where('environment', $environment->value)
            ->where('credential_type', $type->value)
            ->first();
    }
}
