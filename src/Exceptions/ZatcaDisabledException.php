<?php

namespace Zatca\Exceptions;

/**
 * Thrown when ZATCA work is requested while the global kill switch is off.
 *
 * Only ProductionInvoiceOrchestrator raises this, and only as a last-resort backstop for
 * code that calls it directly. Its three public methods return a non-nullable ZatcaInvoice,
 * so they cannot decline by returning — refusing loudly is the only option that does not
 * require inventing an invoice that was never created.
 *
 * Jobs never see this. Every job checks Zatca\Support\ZatcaGate before calling the
 * orchestrator, precisely so a queued job exits quietly instead of failing: a job that
 * threw here would be marked failed and retried, which is exactly what disabling ZATCA is
 * supposed to prevent.
 */
class ZatcaDisabledException extends ZatcaException
{
    public static function make(string $operation): self
    {
        return new self(
            "ZATCA invoicing is disabled (zatca.enabled=false); refusing to {$operation}. "
            .'Set ZATCA_ENABLED=true and run `php artisan optimize:clear` to re-enable it.'
        );
    }
}
