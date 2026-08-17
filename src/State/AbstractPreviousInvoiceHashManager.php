<?php

namespace Zatca\State;

use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Models\ZatcaEgsState;
use Illuminate\Support\Facades\DB;

/**
 * Commits the new previous_invoice_hash once an invoice has actually been
 * signed. Kept as a distinct step from InvoiceCounterManager::reserve()
 * because ICV allocation happens before signing, while the hash to persist
 * is only known after the SDK signs the invoice — but both operate on the
 * same locked row so retries never desynchronize ICV and PIH.
 */
abstract class AbstractPreviousInvoiceHashManager
{
    abstract protected function stateType(): ZatcaCredentialType;

    public function commit(string $egsIdentifier, ZatcaEnvironment $environment, string $newInvoiceHash): void
    {
        DB::transaction(function () use ($egsIdentifier, $environment, $newInvoiceHash) {
            ZatcaEgsState::query()
                ->where('egs_identifier', $egsIdentifier)
                ->where('environment', $environment->value)
                ->where('state_type', $this->stateType()->value)
                ->lockForUpdate()
                ->first()
                ?->update(['previous_invoice_hash' => $newInvoiceHash]);
        });
    }

    /**
     * Locks the EGS chain row inside the CALLER's transaction and returns the current
     * head.
     *
     * Exists so a caller can hold the chain steady across resolve -> build -> sign ->
     * commit. Reading the head and committing the new one are only safe as a pair: if
     * anything else signs in between, the head this returned is stale and the invoice
     * built from it would chain to the wrong predecessor. Callers MUST already be
     * inside a transaction, or the lock is released the moment this returns.
     *
     * Returns null when no state row exists yet — the caller is generating the very
     * first invoice for this EGS and reserve() will create the row.
     */
    public function lockCurrent(string $egsIdentifier, ZatcaEnvironment $environment): ?string
    {
        return ZatcaEgsState::query()
            ->where('egs_identifier', $egsIdentifier)
            ->where('environment', $environment->value)
            ->where('state_type', $this->stateType()->value)
            ->lockForUpdate()
            ->value('previous_invoice_hash');
    }

    /** Read-only, for diagnostics/inspection — never used to build a new invoice. */
    public function current(string $egsIdentifier, ZatcaEnvironment $environment): ?string
    {
        return ZatcaEgsState::query()
            ->where('egs_identifier', $egsIdentifier)
            ->where('environment', $environment->value)
            ->where('state_type', $this->stateType()->value)
            ->value('previous_invoice_hash');
    }
}
