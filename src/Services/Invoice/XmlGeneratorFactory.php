<?php

namespace Zatca\Services\Invoice;

use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Exceptions\ZatcaException;
use Zatca\Services\Invoice\XmlGenerators\V2\AbstractSimplifiedUblDocumentXmlGenerator;
use Zatca\Services\Invoice\XmlGenerators\V2\SimplifiedCreditNoteXmlGenerator;
use Zatca\Services\Invoice\XmlGenerators\V2\SimplifiedDebitNoteXmlGenerator;
use Zatca\Services\Invoice\XmlGenerators\V2\SimplifiedInvoiceXmlGenerator;
use Illuminate\Support\Facades\Config;

/**
 * Resolves the active XmlGenerators/V{profile} implementation from
 * config('zatca.xml_profile_version') — independent of api_version, since
 * ZATCA's UBL profile and transport API version change on different
 * schedules (plan §2.1).
 *
 * $documentType only matters for compliance onboarding — real production
 * invoices (ProductionInvoiceOrchestrator, zatca:sdk-smoke-test) always call
 * make() with no argument and get the plain invoice generator, exactly as
 * before this parameter existed.
 */
class XmlGeneratorFactory
{
    public function make(ZatcaComplianceDocumentType $documentType = ZatcaComplianceDocumentType::Invoice): AbstractSimplifiedUblDocumentXmlGenerator
    {
        $profile = Config::get('zatca.xml_profile_version');

        if ($profile !== 'V2') {
            throw new ZatcaException("Unsupported ZATCA_XML_PROFILE_VERSION [{$profile}]. Add XmlGenerators/V{$profile}/ before switching config to it.");
        }

        return match ($documentType) {
            ZatcaComplianceDocumentType::Invoice => app(SimplifiedInvoiceXmlGenerator::class),
            ZatcaComplianceDocumentType::CreditNote => app(SimplifiedCreditNoteXmlGenerator::class),
            ZatcaComplianceDocumentType::DebitNote => app(SimplifiedDebitNoteXmlGenerator::class),
        };
    }
}
