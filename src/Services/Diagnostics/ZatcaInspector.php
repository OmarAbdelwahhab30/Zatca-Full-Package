<?php

namespace Zatca\Services\Diagnostics;

use Zatca\Models\ZatcaEgsCredential;
use Zatca\Models\ZatcaEgsState;
use Zatca\Models\ZatcaInvoice;
use Zatca\State\EgsRegistry;

/**
 * Single resource-dispatched diagnostics class (plan §2.1 — one class,
 * a fifth of the ceremony of five separate inspector classes). Read-only:
 * never mutates state, never returns raw secrets/private keys/XML.
 */
class ZatcaInspector
{
    public function __construct(private readonly EgsRegistry $egsRegistry) {}

    /** @return array<int,array<string,mixed>> */
    public function credentials(string $egsIdentifier): array
    {
        $environment = $this->egsRegistry->for($egsIdentifier)->environment;

        return ZatcaEgsCredential::query()
            ->where('egs_identifier', $egsIdentifier)
            ->where('environment', $environment->value)
            ->get()
            ->map(fn (ZatcaEgsCredential $row) => [
                'credential_type' => $row->credential_type->value,
                'has_token' => (bool) $row->binary_security_token,
                'has_certificate' => (bool) $row->certificate,
                'has_private_key' => (bool) $row->private_key,
                'certificate_state' => $row->certificate_state->value,
                'certificate_expires_at' => optional($row->certificate_expires_at)->toDateTimeString(),
                'sdk_fingerprint' => $row->sdk_fingerprint,
            ])
            ->all();
    }

    /** @return array<string,mixed> */
    public function state(string $egsIdentifier): array
    {
        $environment = $this->egsRegistry->for($egsIdentifier)->environment;

        return ZatcaEgsState::query()
            ->where('egs_identifier', $egsIdentifier)
            ->where('environment', $environment->value)
            ->get()
            ->mapWithKeys(fn (ZatcaEgsState $row) => [
                $row->state_type->value => [
                    'icv' => $row->icv,
                    'previous_invoice_hash_preview' => substr($row->previous_invoice_hash, 0, 12).'…',
                ],
            ])
            ->all();
    }

    /** @return array<string,mixed>|null */
    public function invoice(string $identifier): ?array
    {
        $invoice = ctype_digit($identifier)
            ? ZatcaInvoice::find((int) $identifier)
            : ZatcaInvoice::where('uuid', $identifier)->first();

        if (! $invoice) {
            return null;
        }

        return [
            'id' => $invoice->id,
            'egs_identifier' => $invoice->egs_identifier,
            'environment' => $invoice->environment->value,
            'source_reference' => $invoice->source_reference,
            'uuid' => $invoice->uuid,
            'icv' => $invoice->icv,
            'status' => $invoice->status->value,
            'reporting_status' => $invoice->reporting_status,
            'attempt_count' => $invoice->attempt_count,
            'last_error' => $invoice->last_error,
            'reported_at' => optional($invoice->reported_at)->toDateTimeString(),
            'total_incl_vat' => (string) $invoice->total_incl_vat,
        ];
    }
}
