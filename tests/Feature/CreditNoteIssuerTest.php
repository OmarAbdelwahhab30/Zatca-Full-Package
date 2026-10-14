<?php

namespace Zatca\Tests\Feature;

use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\Enums\ZatcaCreditNoteType;
use Zatca\Exceptions\CreditNoteException;
use Zatca\Models\ZatcaCreditNote;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\CreditNote\CreditNoteEligibilityPolicy;
use Zatca\Services\CreditNote\CreditNoteEligibilityVerdict;
use Zatca\Services\CreditNote\CreditNoteIssuer;
use Zatca\Services\CreditNote\CreditNoteLedger;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\Tests\Concerns\BuildsProductionInvoices;
use Zatca\Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Exercises CreditNoteIssuer against a REPORTED original (built by driving the real
 * orchestrator + reporting trait, not by inserting rows), covering the scenarios the
 * concurrency and eligibility guarantees exist for.
 */
class CreditNoteIssuerTest extends TestCase
{
    use BuildsProductionInvoices;

    private const EGS = 'default';

    protected function setUp(): void
    {
        parent::setUp();

        // CreditNoteIssuer dispatches GenerateZatcaCreditNoteJob after commit — faked so
        // these tests exercise the business record, not the queue driver.
        Queue::fake([\Zatca\Jobs\GenerateZatcaCreditNoteJob::class]);
    }

    public function test_full_credit_selects_every_available_line(): void
    {
        $original = $this->reportedThreeLineInvoice(6001);

        $creditNote = $this->issuer()->issue(
            $original,
            ZatcaCreditNoteType::Full,
            [],
            'Full refund',
            (string) Str::uuid(),
        );

        $this->assertSame('full', $creditNote->type->value);
        $this->assertCount(3, $creditNote->lines);
        $this->assertSame(1030.00, (float) $creditNote->total_excl_vat);
        $this->assertSame('CN-'.str_pad((string) $creditNote->id, 6, '0', STR_PAD_LEFT), $creditNote->credit_note_number);
    }

    public function test_partial_credit_of_one_line(): void
    {
        $original = $this->reportedThreeLineInvoice(6002);

        $creditNote = $this->issuer()->issue(
            $original,
            ZatcaCreditNoteType::Partial,
            [1],
            'Course B refunded',
            (string) Str::uuid(),
        );

        $this->assertCount(1, $creditNote->lines);
        $this->assertSame(300.00, (float) $creditNote->total_excl_vat);
        $this->assertSame(345.00, (float) $creditNote->total_incl_vat);
    }

    /** Scenario 5: crediting course B, then course C separately — independent notes, independent lines. */
    public function test_multiple_credit_notes_against_different_lines(): void
    {
        $original = $this->reportedThreeLineInvoice(6003);

        $first = $this->issuer()->issue($original, ZatcaCreditNoteType::Partial, [1], 'B refunded', (string) Str::uuid());
        // The next note waits for this one to settle — see the in-progress test below.
        $first->update(['accepted_at' => now()]);
        $second = $this->issuer()->issue($original, ZatcaCreditNoteType::Partial, [2], 'C refunded', (string) Str::uuid());

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(300.00, (float) $first->total_excl_vat);
        $this->assertSame(230.00, (float) $second->total_excl_vat);

        $ledger = app(CreditNoteLedger::class)->forOriginal($original->fresh());
        $this->assertSame([0], $ledger->availableIndexes);
    }

    /**
     * Scenario 20: while a note is pending, no second note may be issued against the
     * invoice at all — not for the line it holds, and not for any other line either.
     */
    public function test_no_second_note_may_be_issued_while_one_is_in_progress(): void
    {
        $original = $this->reportedThreeLineInvoice(6004);

        $pending = $this->issuer()->issue($original, ZatcaCreditNoteType::Partial, [1], 'B refunded', (string) Str::uuid());

        foreach ([[1], [2]] as $lines) {
            try {
                $this->issuer()->issue($original, ZatcaCreditNoteType::Partial, $lines, 'Again', (string) Str::uuid());
                $this->fail('A second note while one is in progress should have been refused.');
            } catch (CreditNoteException $e) {
                $this->assertSame(CreditNoteEligibilityVerdict::ADJUSTMENT_IN_PROGRESS, $e->reason);
            }
        }

        // Settled either way — accepted here — the invoice takes notes again.
        $pending->update(['accepted_at' => now()]);
        $this->assertSame(
            230.00,
            (float) $this->issuer()->issue($original, ZatcaCreditNoteType::Partial, [2], 'C refunded', (string) Str::uuid())->total_excl_vat,
        );
    }

    /**
     * Scenario 6: requesting a line already reserved (stale selection) fails with the
     * story's exact message, while other lines remain available (so eligibility itself
     * still passes and the failure is specifically about the selection).
     */
    public function test_requesting_an_unavailable_line_is_refused_with_the_story_message(): void
    {
        $original = $this->reportedThreeLineInvoice(6005);
        $this->issuer()->issue($original, ZatcaCreditNoteType::Partial, [0], 'A refunded', (string) Str::uuid())
            ->update(['accepted_at' => now()]);

        $this->expectException(CreditNoteException::class);
        $this->expectExceptionMessageMatches('/The credit amount cannot exceed the remaining eligible amount\./');

        // Line 0 is already credited; requesting it again alongside a fresh line must
        // refuse the whole request rather than silently drop the unavailable one.
        $this->issuer()->issue($original, ZatcaCreditNoteType::Partial, [0, 1], 'x', (string) Str::uuid());
    }

    /** Scenario 19/AC-21: replaying the same idempotency key returns the SAME note, never a second one. */
    public function test_the_same_idempotency_key_returns_the_same_note(): void
    {
        $original = $this->reportedThreeLineInvoice(6006);
        $key = (string) Str::uuid();

        $first = $this->issuer()->issue($original, ZatcaCreditNoteType::Partial, [0], 'x', $key);
        $second = $this->issuer()->issue($original, ZatcaCreditNoteType::Partial, [0], 'x', $key);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ZatcaCreditNote::count());
    }

    /** BR-12/AC-16: once every line is accepted, no further note may be issued. */
    public function test_a_fully_credited_original_refuses_a_new_note(): void
    {
        $original = $this->reportedThreeLineInvoice(6007);
        $creditNote = $this->issuer()->issue($original, ZatcaCreditNoteType::Full, [], 'Full refund', (string) Str::uuid());

        // Simulate acceptance directly — the reporting round trip itself is covered elsewhere.
        $creditNote->update(['accepted_at' => now()]);

        $verdict = app(CreditNoteEligibilityPolicy::class)->verdict($original->fresh());
        $this->assertFalse($verdict->eligible);
        $this->assertSame(CreditNoteEligibilityVerdict::FULLY_CREDITED, $verdict->reason);
    }

    /** The original invoice itself is never touched by issuing a Credit Note against it (BR-01). */
    public function test_the_original_invoice_row_is_untouched(): void
    {
        $original = $this->reportedThreeLineInvoice(6008);
        $before = $original->toArray();

        $this->issuer()->issue($original, ZatcaCreditNoteType::Partial, [0], 'x', (string) Str::uuid());

        $after = ZatcaInvoice::find($original->id)->toArray();
        unset($before['updated_at'], $after['updated_at']);

        $this->assertSame($before, $after);
    }

    public function test_a_non_reported_original_is_not_eligible(): void
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $draft = app(ProductionInvoiceOrchestrator::class)
            ->generate($this->fakeInvoiceSource(), (object) ['id' => 6009], self::EGS);
        // Reset to a non-reported status to simulate "not yet reported".
        $draft->update(['status' => \Zatca\Enums\ZatcaInvoiceStatus::PendingReporting]);

        $this->expectException(CreditNoteException::class);

        $this->issuer()->issue($draft->fresh(), ZatcaCreditNoteType::Full, [], 'x', (string) Str::uuid());
    }

    private function issuer(): CreditNoteIssuer
    {
        return app(CreditNoteIssuer::class);
    }

    /** A reported three-line, whole-number-VAT original — mirrors the story's A/B/C example. */
    private function reportedThreeLineInvoice(int $sourceId): ZatcaInvoice
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $invoice = app(ProductionInvoiceOrchestrator::class)
            ->generate($this->threeLineInvoiceSource(), (object) ['id' => $sourceId], self::EGS);

        $invoice->update([
            'status' => \Zatca\Enums\ZatcaInvoiceStatus::Reported,
            'reported_at' => now(),
        ]);

        return $invoice->fresh();
    }

    private function threeLineInvoiceSource(): \Zatca\Contracts\InvoiceSourceContract
    {
        return new class implements \Zatca\Contracts\InvoiceSourceContract
        {
            public function toInvoiceInput(mixed $source, string $egsIdentifier): \Zatca\DTOs\ZatcaInvoiceInput
            {
                return new \Zatca\DTOs\ZatcaInvoiceInput(
                    egsIdentifier: $egsIdentifier,
                    sourceReference: $this->sourceReference($source),
                    buyerName: 'Original Buyer',
                    buyerVatNumber: null,
                    issueDateTime: new \DateTimeImmutable('2026-09-01T10:00:00+03:00'),
                    currency: 'SAR',
                    // Priced before VAT on this invoice, which also exercises the per-invoice mode override.
                    items: [
                        new \Zatca\DTOs\ZatcaInvoiceItem('Course A', 1, 500.00),
                        new \Zatca\DTOs\ZatcaInvoiceItem('Course B', 1, 300.00),
                        new \Zatca\DTOs\ZatcaInvoiceItem('Course C', 1, 230.00),
                    ],
                    priceMode: \Zatca\Enums\VatPriceMode::Exclusive,
                );
            }

            public function sourceReference(mixed $source): string
            {
                return 'order:'.(is_object($source) ? $source->id : $source);
            }
        };
    }
}
