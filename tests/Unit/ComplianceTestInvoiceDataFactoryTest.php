<?php

namespace Zatca\Tests\Unit;

use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Services\Invoice\ComplianceTestInvoiceDataFactory;
use Zatca\Tests\TestCase;

class ComplianceTestInvoiceDataFactoryTest extends TestCase
{
    /**
     * The pattern of ZATCA's BR-KSA-F-13 (SDK 3.4.8 schematron): an all-digit Other
     * Seller/Buyer ID containing eight equal or eight sequential digits is "invalid".
     * The ZATCA sandbox returned this warning for the old buyer CRN 7000000007.
     */
    private const BR_KSA_F_13 = '/(01234567|12345678|23456789|76543210|87654321|98765432|00000000|11111111|22222222|33333333|44444444|55555555|66666666|77777777|88888888|99999999)/';

    public function test_the_standard_compliance_buyer_id_passes_br_ksa_f_13(): void
    {
        foreach ([ZatcaComplianceDocumentType::StandardInvoice, ZatcaComplianceDocumentType::StandardCreditNote, ZatcaComplianceDocumentType::StandardDebitNote] as $type) {
            $data = app(ComplianceTestInvoiceDataFactory::class)->make('default', 'Item', $type);

            $this->assertNotNull($data->buyer?->otherId);
            $this->assertDoesNotMatchRegularExpression(self::BR_KSA_F_13, $data->buyer->otherId->value);
        }
    }

    public function test_simplified_compliance_documents_have_no_buyer(): void
    {
        $data = app(ComplianceTestInvoiceDataFactory::class)->make('default', 'Item', ZatcaComplianceDocumentType::Invoice);

        $this->assertNull($data->buyer);
    }
}
