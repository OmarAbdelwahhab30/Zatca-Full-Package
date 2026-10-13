<?php

namespace Zatca\Services\Invoice;

use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Exceptions\ZatcaException;
use Zatca\Services\Invoice\XmlGenerators\UblDocumentXmlGenerator;
use Zatca\Services\Invoice\XmlGenerators\V2\SimplifiedCreditNoteXmlGenerator;
use Zatca\Services\Invoice\XmlGenerators\V2\SimplifiedDebitNoteXmlGenerator;
use Zatca\Services\Invoice\XmlGenerators\V2\SimplifiedInvoiceXmlGenerator;
use Zatca\Services\Invoice\XmlGenerators\V2\StandardCreditNoteXmlGenerator;
use Zatca\Services\Invoice\XmlGenerators\V2\StandardDebitNoteXmlGenerator;
use Zatca\Services\Invoice\XmlGenerators\V2\StandardInvoiceXmlGenerator;
use Illuminate\Support\Facades\Config;

/**
 * Resolves the active XmlGenerators/V{profile} implementation from
 * config('zatca.xml_profile_version') — independent of api_version, since
 * ZATCA's UBL profile and transport API version change on different
 * schedules (plan §2.1).
 */
class XmlGeneratorFactory
{
    /**
     * Production entry point: dispatches on ZatcaInvoiceData::$documentType, which
     * defaults to Invoice — so a caller that never sets it (every source that
     * existed before Credit Notes) keeps getting the plain invoice generator.
     *
     * This is the ONLY entry point ProductionInvoiceOrchestrator may use. A call to
     * make() there would silently emit a 388 invoice for a document whose data says
     * otherwise — see reserveDraft()/resumableReplacement()/reserveReplacement().
     */
    public function forData(ZatcaInvoiceData $invoiceData): UblDocumentXmlGenerator
    {
        return $this->make($invoiceData->documentType);
    }

    /**
     * $documentType only matters for compliance onboarding — real production
     * invoices must go through forData() instead, so the document type actually
     * signed always matches the type frozen in the invoice's own snapshot.
     */
    public function make(ZatcaComplianceDocumentType $documentType = ZatcaComplianceDocumentType::Invoice): UblDocumentXmlGenerator
    {
        $profile = Config::get('zatca.xml_profile_version');

        if ($profile !== 'V2') {
            throw new ZatcaException("Unsupported ZATCA_XML_PROFILE_VERSION [{$profile}]. Add XmlGenerators/V{$profile}/ before switching config to it.");
        }

        return match ($documentType) {
            ZatcaComplianceDocumentType::Invoice => app(SimplifiedInvoiceXmlGenerator::class),
            ZatcaComplianceDocumentType::CreditNote => app(SimplifiedCreditNoteXmlGenerator::class),
            ZatcaComplianceDocumentType::DebitNote => app(SimplifiedDebitNoteXmlGenerator::class),
            ZatcaComplianceDocumentType::StandardInvoice => app(StandardInvoiceXmlGenerator::class),
            ZatcaComplianceDocumentType::StandardCreditNote => app(StandardCreditNoteXmlGenerator::class),
            ZatcaComplianceDocumentType::StandardDebitNote => app(StandardDebitNoteXmlGenerator::class),
        };
    }
}
