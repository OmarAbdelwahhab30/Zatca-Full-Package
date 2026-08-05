<?php

namespace Asas\Zatca\State;

use Asas\Zatca\Enums\ZatcaCredentialType;
use Asas\Zatca\Enums\ZatcaEnvironment;
use Asas\Zatca\Models\ZatcaEgsState;
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
