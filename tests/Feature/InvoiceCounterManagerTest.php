<?php

namespace Asas\Zatca\Tests\Feature;

use Asas\Zatca\Enums\ZatcaEnvironment;
use Asas\Zatca\State\ComplianceInvoiceCounterManager;
use Asas\Zatca\State\CompliancePreviousInvoiceHashManager;
use Asas\Zatca\Tests\TestCase;

class InvoiceCounterManagerTest extends TestCase
{
    private const EGS = 'default';

    public function test_icv_reservation_is_sequential(): void
    {
        $manager = app(ComplianceInvoiceCounterManager::class);

        $first = $manager->reserve(self::EGS, ZatcaEnvironment::Sandbox);
        $second = $manager->reserve(self::EGS, ZatcaEnvironment::Sandbox);

        $this->assertSame(1, $first->icv);
        $this->assertSame(2, $second->icv);
    }

    public function test_genesis_previous_invoice_hash_matches_zatca_sdk_convention(): void
    {
        $reservation = app(ComplianceInvoiceCounterManager::class)->reserve(self::EGS, ZatcaEnvironment::Sandbox);

        // ZATCA/SDK genesis convention: base64(hex(sha256("0"))) — NOT base64("0").
        $this->assertSame(base64_encode(hash('sha256', '0')), $reservation->previousInvoiceHash);
    }

    public function test_previous_invoice_hash_chains_after_commit(): void
    {
        $counter = app(ComplianceInvoiceCounterManager::class);
        $pih = app(CompliancePreviousInvoiceHashManager::class);

        $counter->reserve(self::EGS, ZatcaEnvironment::Sandbox);
        $pih->commit(self::EGS, ZatcaEnvironment::Sandbox, 'FAKE_HASH_1');

        $second = $counter->reserve(self::EGS, ZatcaEnvironment::Sandbox);

        $this->assertSame('FAKE_HASH_1', $second->previousInvoiceHash);
    }

    public function test_compliance_and_production_sequences_are_isolated(): void
    {
        $compliance = app(ComplianceInvoiceCounterManager::class);
        $production = app(\Asas\Zatca\State\ProductionInvoiceCounterManager::class);

        $compliance->reserve(self::EGS, ZatcaEnvironment::Sandbox);
        $compliance->reserve(self::EGS, ZatcaEnvironment::Sandbox);
        $firstProduction = $production->reserve(self::EGS, ZatcaEnvironment::Sandbox);

        $this->assertSame(1, $firstProduction->icv, 'Production ICV must not be affected by compliance reservations.');
    }
}
