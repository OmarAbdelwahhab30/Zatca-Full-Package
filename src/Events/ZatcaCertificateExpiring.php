<?php

namespace Zatca\Events;

use Zatca\Enums\ZatcaCertificateState;
use Zatca\Enums\ZatcaCredentialType;
use Carbon\CarbonImmutable;

/**
 * Fired by CertificateLifecycleManager::check() when a certificate first
 * enters `expiring_soon` (60 days out by default) or `expired`.
 *
 * This is the single most important thing to alert on: renewal cannot be
 * automated (it needs an OTP from the Fatoora portal), so a human must act
 * during the warning window. Once the certificate actually expires, reporting
 * pauses and invoices pile up as pending_reporting.
 *
 * Fired only on the TRANSITION, not on every scheduled check — you get one
 * alert per state change, not one per day.
 */
class ZatcaCertificateExpiring
{
    public function __construct(
        public readonly string $egsIdentifier,
        public readonly ZatcaCredentialType $credentialType,
        public readonly ZatcaCertificateState $state,
        public readonly ?CarbonImmutable $expiresAt,
        /** Negative once the certificate has already expired. */
        public readonly ?int $daysRemaining,
    ) {}

    public function hasExpired(): bool
    {
        return $this->state === ZatcaCertificateState::Expired;
    }
}
