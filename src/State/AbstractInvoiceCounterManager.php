<?php

namespace Zatca\State;

use Zatca\DTOs\InvoiceSequenceReservation;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Models\ZatcaEgsState;
use Illuminate\Support\Facades\DB;

/**
 * Concrete base for the compliance/production ICV allocators (State\Compliance\InvoiceCounterManager,
 * State\Production\InvoiceCounterManager). No interface — ICV/PIH management has exactly one correct
 * implementation (DB transaction + row lock), per plan §4. The two subclasses exist so compliance and
 * production sequences can never be mixed up by accident (different class, different DI binding) even
 * though the state_type column already partitions rows in zatca_egs_states.
 */
abstract class AbstractInvoiceCounterManager
{
    abstract protected function stateType(): ZatcaCredentialType;

    /**
     * Atomically increments ICV and returns it together with the previous
     * invoice hash still on record (the hash chain link for the invoice
     * about to be built). Row-locked so concurrent invoice generation for
     * the same EGS/environment can never allocate the same ICV twice.
     */
    public function reserve(string $egsIdentifier, ZatcaEnvironment $environment): InvoiceSequenceReservation
    {
        return DB::transaction(function () use ($egsIdentifier, $environment) {
            $state = ZatcaEgsState::query()
                ->where('egs_identifier', $egsIdentifier)
                ->where('environment', $environment->value)
                ->where('state_type', $this->stateType()->value)
                ->lockForUpdate()
                ->first();

            if (! $state) {
                $state = ZatcaEgsState::create([
                    'egs_identifier' => $egsIdentifier,
                    'environment' => $environment->value,
                    'state_type' => $this->stateType()->value,
                    'icv' => 0,
                    // Genesis value per ZATCA/SDK convention: base64(hex(sha256("0"))).
                    'previous_invoice_hash' => base64_encode(hash('sha256', '0')),
                ]);

                $state = ZatcaEgsState::query()
                    ->where('id', $state->id)
                    ->lockForUpdate()
                    ->first();
            }

            $previousInvoiceHash = $state->previous_invoice_hash;
            $nextIcv = $state->icv + 1;

            $state->update(['icv' => $nextIcv]);

            return new InvoiceSequenceReservation($nextIcv, $previousInvoiceHash);
        });
    }

    /** Read-only current ICV, for diagnostics — never used to allocate a new invoice. */
    public function current(string $egsIdentifier, ZatcaEnvironment $environment): int
    {
        return (int) (ZatcaEgsState::query()
            ->where('egs_identifier', $egsIdentifier)
            ->where('environment', $environment->value)
            ->where('state_type', $this->stateType()->value)
            ->value('icv') ?? 0);
    }
}
