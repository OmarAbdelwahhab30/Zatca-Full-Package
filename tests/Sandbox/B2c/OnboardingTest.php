<?php

namespace Zatca\Tests\Sandbox\B2c;

use PHPUnit\Framework\Attributes\Group;
use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Models\ZatcaComplianceDocument;
use Zatca\Models\ZatcaInvoice;
use Zatca\Tests\Sandbox\Concerns\AssertsOnboardingSteps;
use Zatca\Tests\Sandbox\SandboxTestCase;

/**
 * B2C EGS onboarding against ZATCA's developer-portal sandbox, step by step, with the
 * package's own artisan commands. The cases that need a refusal from ZATCA (wrong OTP,
 * Production CSID before the checks) are in Feature/OnboardCommandFailuresTest: the
 * sandbox accepts any OTP and issues a Production CSID regardless (measured 2026-10-15).
 *
 * @group zatca-sandbox
 */
#[Group('zatca-sandbox')]
class OnboardingTest extends SandboxTestCase
{
    use AssertsOnboardingSteps;

    public function test_the_six_onboarding_steps_one_by_one(): void
    {
        $this->stepGenerateCredentials(self::B2C_EGS, standard: false);
        $this->stepComplianceCsid(self::B2C_EGS);

        $invoice = $this->stepComplianceDocument(self::B2C_EGS, 'test-invoice', ZatcaComplianceDocumentType::Invoice, '388', 1, previous: null, billedInvoice: null);
        $credit = $this->stepComplianceDocument(self::B2C_EGS, 'test-credit-note', ZatcaComplianceDocumentType::CreditNote, '381', 2, previous: $invoice, billedInvoice: $invoice);
        $this->stepComplianceDocument(self::B2C_EGS, 'test-debit-note', ZatcaComplianceDocumentType::DebitNote, '383', 3, previous: $credit, billedInvoice: $invoice);

        $this->stepProductionCsid(self::B2C_EGS);

        $this->assertOnlyOnboardingCalls(complianceDocuments: 3);

        // Onboarding never touches production invoicing or the other EGS.
        $this->assertSame(0, ZatcaInvoice::query()->count());
        $this->assertSame(0, ZatcaComplianceDocument::query()->where('egs_identifier', self::B2B_EGS)->count());
    }

    /**
     * A step run twice (an operator retrying after a network blip) continues the
     * compliance chain, and the notes then bill against the newest compliance invoice.
     */
    public function test_repeating_a_compliance_step_continues_the_chain(): void
    {
        $this->stepGenerateCredentials(self::B2C_EGS, standard: false);
        $this->stepComplianceCsid(self::B2C_EGS);

        $first = $this->stepComplianceDocument(self::B2C_EGS, 'test-invoice', ZatcaComplianceDocumentType::Invoice, '388', 1, previous: null, billedInvoice: null);
        $second = $this->stepComplianceDocument(self::B2C_EGS, 'test-invoice', ZatcaComplianceDocumentType::Invoice, '388', 2, previous: $first, billedInvoice: null);
        $this->assertNotSame($first->uuid, $second->uuid);
        $this->assertNotSame($first->document_reference, $second->document_reference);

        $credit = $this->stepComplianceDocument(self::B2C_EGS, 'test-credit-note', ZatcaComplianceDocumentType::CreditNote, '381', 3, previous: $second, billedInvoice: $second);
        $creditAgain = $this->stepComplianceDocument(self::B2C_EGS, 'test-credit-note', ZatcaComplianceDocumentType::CreditNote, '381', 4, previous: $credit, billedInvoice: $second);
        $this->stepComplianceDocument(self::B2C_EGS, 'test-debit-note', ZatcaComplianceDocumentType::DebitNote, '383', 5, previous: $creditAgain, billedInvoice: $second);

        $this->stepProductionCsid(self::B2C_EGS);

        $this->assertOnlyOnboardingCalls(complianceDocuments: 5);
    }
}
