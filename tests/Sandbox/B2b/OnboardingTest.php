<?php

namespace Zatca\Tests\Sandbox\B2b;

use PHPUnit\Framework\Attributes\Group;
use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Models\ZatcaComplianceDocument;
use Zatca\Models\ZatcaInvoice;
use Zatca\Support\B2bReadiness;
use Zatca\Tests\Sandbox\Concerns\AssertsOnboardingSteps;
use Zatca\Tests\Sandbox\SandboxTestCase;

/**
 * B2B EGS onboarding against ZATCA's developer-portal sandbox, step by step: the second
 * EGS (csr.invoice.type 1000) sends Standard compliance documents, which ZATCA clears.
 * Refusal cases are in Feature/OnboardCommandFailuresTest (see the B2C OnboardingTest).
 *
 * @group zatca-sandbox
 */
#[Group('zatca-sandbox')]
class OnboardingTest extends SandboxTestCase
{
    use AssertsOnboardingSteps;

    public function test_the_six_onboarding_steps_one_by_one(): void
    {
        $readiness = app(B2bReadiness::class);
        $this->assertFalse($readiness->ready(), 'B2B must not be ready before onboarding.');

        $this->stepGenerateCredentials(self::B2B_EGS, standard: true);
        $this->stepComplianceCsid(self::B2B_EGS);

        $invoice = $this->stepComplianceDocument(self::B2B_EGS, 'test-invoice', ZatcaComplianceDocumentType::StandardInvoice, '388', 1, previous: null, billedInvoice: null);
        $credit = $this->stepComplianceDocument(self::B2B_EGS, 'test-credit-note', ZatcaComplianceDocumentType::StandardCreditNote, '381', 2, previous: $invoice, billedInvoice: $invoice);
        $this->stepComplianceDocument(self::B2B_EGS, 'test-debit-note', ZatcaComplianceDocumentType::StandardDebitNote, '383', 3, previous: $credit, billedInvoice: $invoice);

        $this->assertFalse($readiness->ready(), 'B2B must not be ready before the Production CSID.');

        $this->stepProductionCsid(self::B2B_EGS);

        $this->assertTrue($readiness->ready(), 'B2B must be ready once its EGS is onboarded.');
        $this->assertOnlyOnboardingCalls(complianceDocuments: 3);
        $this->assertSame(0, ZatcaInvoice::query()->count());
        $this->assertSame(0, ZatcaComplianceDocument::query()->where('egs_identifier', self::B2C_EGS)->count());
    }

    public function test_repeating_a_compliance_step_continues_the_chain(): void
    {
        $this->stepGenerateCredentials(self::B2B_EGS, standard: true);
        $this->stepComplianceCsid(self::B2B_EGS);

        $first = $this->stepComplianceDocument(self::B2B_EGS, 'test-invoice', ZatcaComplianceDocumentType::StandardInvoice, '388', 1, previous: null, billedInvoice: null);
        $second = $this->stepComplianceDocument(self::B2B_EGS, 'test-invoice', ZatcaComplianceDocumentType::StandardInvoice, '388', 2, previous: $first, billedInvoice: null);
        $this->assertNotSame($first->uuid, $second->uuid);

        $credit = $this->stepComplianceDocument(self::B2B_EGS, 'test-credit-note', ZatcaComplianceDocumentType::StandardCreditNote, '381', 3, previous: $second, billedInvoice: $second);
        $this->stepComplianceDocument(self::B2B_EGS, 'test-debit-note', ZatcaComplianceDocumentType::StandardDebitNote, '383', 4, previous: $credit, billedInvoice: $second);

        $this->stepProductionCsid(self::B2B_EGS);

        $this->assertOnlyOnboardingCalls(complianceDocuments: 4);
    }

    /**
     * Both EGSs onboarded side by side, as in a real two-EGS setup: separate keys,
     * certificates and compliance chains.
     */
    public function test_the_b2c_and_b2b_egs_onboard_independently(): void
    {
        $this->onboard(self::B2C_EGS);
        $this->onboard(self::B2B_EGS);

        $b2c = ZatcaComplianceDocument::query()->where('egs_identifier', self::B2C_EGS)->orderBy('icv')->get();
        $b2b = ZatcaComplianceDocument::query()->where('egs_identifier', self::B2B_EGS)->orderBy('icv')->get();

        $this->assertSame([1, 2, 3], $b2c->pluck('icv')->all());
        $this->assertSame([1, 2, 3], $b2b->pluck('icv')->all());
        $this->assertSame(['REPORTED', 'REPORTED', 'REPORTED'], $b2c->pluck('reporting_status')->all());
        $this->assertSame(['CLEARED', 'CLEARED', 'CLEARED'], $b2b->pluck('reporting_status')->all());
        $this->assertSame(base64_encode(hash('sha256', '0')), $b2b[0]->previous_invoice_hash, 'The B2B chain must start from genesis, not continue the B2C one.');

        $b2cKey = $this->credentials(self::B2C_EGS, \Zatca\Enums\ZatcaCredentialType::Compliance)->privateKey;
        $b2bKey = $this->credentials(self::B2B_EGS, \Zatca\Enums\ZatcaCredentialType::Compliance)->privateKey;
        $this->assertNotSame($b2cKey, $b2bKey, 'Each EGS must have its own key pair.');
        $this->assertComplianceCertificateMatchesGeneratedKey(self::B2C_EGS);
        $this->assertComplianceCertificateMatchesGeneratedKey(self::B2B_EGS);
        $this->assertTrue(app(B2bReadiness::class)->ready());
    }
}
