<?php

namespace Zatca\Support;

use Illuminate\Support\Facades\Log;

/**
 * The one place that answers "may ZATCA do anything right now?".
 *
 * ZATCA work is reachable from nine independent entry points — the payment listener,
 * TransactionService, five queued jobs, the scheduled reporting/requeue commands, the
 * host's repair command, certificate-renewal resume, and direct calls to
 * ProductionInvoiceOrchestrator. Scattering `config('zatca.enabled')` across all of them
 * would mean nine places to get the default wrong and nine places to update when the flag
 * ever becomes per-EGS or database-backed. Every caller asks this class instead.
 *
 * Read lazily on each call rather than captured in the constructor: this is safe to use as
 * a constructor default (see AdminZatcaInvoiceService), and a test that flips
 * config()->set('zatca.enabled', false) mid-request is honoured immediately rather than by
 * a value frozen at container-resolution time.
 *
 * Defaults to enabled. A deployment that never sets ZATCA_ENABLED behaves exactly as it did
 * before this class existed — the switch can only ever be turned off deliberately.
 */
class ZatcaGate
{
    /**
     * Only an explicit, unambiguous false switches ZATCA off.
     *
     * This is the single place the raw setting is interpreted — the config file carries
     * env('ZATCA_ENABLED') through untouched — so there is one rule to read and one to
     * change.
     *
     * Two traps are handled deliberately, because both would otherwise stop invoicing a
     * live system by accident rather than by decision:
     *
     *  - `config('zatca.enabled', true)` applies its fallback only when the key is ABSENT.
     *    A key present but null (a published config that omits the value) would sail past
     *    it and cast to false.
     *  - `filter_var` maps both null and the empty string to FALSE, not to null, so
     *    FILTER_NULL_ON_FAILURE does not catch them either. An `.env` line reading
     *    `ZATCA_ENABLED=` with nothing after it arrives here as ''.
     *
     * Both mean "not configured", so both mean enabled. Anything unparseable does too: the
     * failure mode of a malformed setting is "ZATCA keeps working", never "ZATCA quietly
     * stopped". Turning it off has to be deliberate.
     */
    public function enabled(): bool
    {
        $value = config('zatca.enabled', true);

        if ($value === null || $value === '') {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
    }

    public function disabled(): bool
    {
        return ! $this->enabled();
    }

    /**
     * Records that something was skipped because the switch is off.
     *
     * Deliberately info, not warning: while ZATCA is disabled these are the expected
     * outcome of every entry point, not a fault. Logging them at warning level would fill
     * the log with alarms for a state an administrator chose on purpose, and bury the real
     * warnings this package emits.
     *
     * @param  string  $context  What was skipped, in the caller's own words, e.g.
     *                           "invoice generation for payment:41" — enough to identify
     *                           the work without repeating it at the call site.
     */
    public function skip(string $context): void
    {
        Log::info("[ZATCA] Skipped {$context}: ZATCA invoicing is disabled (zatca.enabled=false).");
    }
}
